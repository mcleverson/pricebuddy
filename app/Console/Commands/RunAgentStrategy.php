<?php

namespace App\Console\Commands;

use App\Enums\AccessMode;
use App\Enums\ProductDataOperation;
use App\Models\Store;
use App\Services\ProductData\ApiProviderRegistry;
use App\Services\ProductData\MarketplaceRegistry;
use App\Services\Scraping\MarketplaceStrategyResolver;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

use function Laravel\Prompts\select;

class RunAgentStrategy extends Command implements PromptsForMissingInput
{
    const COMMAND = 'buddy:agent-strategy-run';

    /**
     * The name and signature of the console command.
     */
    protected $signature = self::COMMAND.' {store? : The ID or name of the store}'.
        ' {--all : Run all discovery-eligible stores, in sequence}'.
        ' {--dry-run : Show the command instead of executing it}';

    /**
     * The console command description.
     */
    protected $description = 'Run product discovery for a store configured with access_mode=agentic (via Hermes) or access_mode=api (via its provider, when supported)';

    /**
     * Execute the console command.
     */
    public function handle(
        MarketplaceStrategyResolver $marketplaceStrategies,
        MarketplaceRegistry $marketplaces,
        ApiProviderRegistry $providers,
    ): int {
        $stores = $this->resolveStores($marketplaces, $providers);

        if ($stores->isEmpty()) {
            $this->warn('No discovery-eligible store found.');

            return SymfonyCommand::FAILURE;
        }

        $results = [];
        foreach ($stores as $store) {
            $results[] = $store->access_mode === AccessMode::Api
                ? $this->runApiDiscovery($store, $marketplaces, $providers)
                : $this->runAgenticDiscovery($store, $marketplaceStrategies);
        }

        $this->displaySummary($results);

        $hasFailure = collect($results)->contains(fn (array $result) => ! $result['success']);

        return $hasFailure ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }

    /**
     * @return Collection<int, Store>
     */
    protected function resolveStores(MarketplaceRegistry $marketplaces, ApiProviderRegistry $providers): Collection
    {
        $eligible = fn () => Store::query()
            ->whereIn('access_mode', [AccessMode::Agentic, AccessMode::Api])
            ->with('tags')
            ->get()
            ->filter(fn (Store $store): bool => $this->isDiscoveryEligible($store, $marketplaces, $providers))
            ->values();

        if ($this->option('all')) {
            return $eligible();
        }

        $identifier = $this->argument('store');

        if ($identifier === null) {
            $stores = $eligible();

            if ($stores->isEmpty()) {
                return $stores;
            }

            $selectedId = select(
                label: 'Which store would you like to run?',
                options: $stores->mapWithKeys(fn (Store $store) => [
                    $store->id => $store->name,
                ])->all(),
            );

            return $stores->where('id', $selectedId)->values();
        }

        $store = Store::query()
            ->whereIn('access_mode', [AccessMode::Agentic, AccessMode::Api])
            ->with('tags')
            ->where(fn ($query) => $query->where('id', $identifier)->orWhere('name', $identifier))
            ->first();

        if ($store === null || ! $this->isDiscoveryEligible($store, $marketplaces, $providers)) {
            return collect();
        }

        return collect([$store]);
    }

    /**
     * Agentic stores always run via Hermes. Api stores only run when their
     * configured provider actually supports Discovery (e.g. Shopee) — otherwise
     * this command has nothing to do for them (price refresh is a separate,
     * unrelated flow).
     */
    protected function isDiscoveryEligible(Store $store, MarketplaceRegistry $marketplaces, ApiProviderRegistry $providers): bool
    {
        if ($store->access_mode === AccessMode::Agentic) {
            return true;
        }

        if ($store->access_mode !== AccessMode::Api) {
            return false;
        }

        $provider = $providers->resolve($marketplaces->resolve($store->marketplace_id));

        return $provider !== null
            && $provider->isConfigured($store)
            && $provider->supports($store, ProductDataOperation::Discovery);
    }

    protected function runAgenticDiscovery(
        Store $store,
        MarketplaceStrategyResolver $marketplaceStrategies,
    ): array
    {
        $this->info("Running agentic discovery for store: {$store->name}");

        $urls = collect($store->agent_urls)
            ->pluck('url')
            ->filter()
            ->values();

        if ($urls->isEmpty()) {
            $this->warn("Store [{$store->name}] has no agent URLs to visit.");

            return ['success' => false, 'store' => $store, 'report' => []];
        }

        $allowedHosts = $store->allowedHosts();
        $tagNames = $store->tags->pluck('name')->implode(',');
        $goal = "Encontre ofertas de {$tagNames} em {$store->name}";
        $marketplaceStrategy = $marketplaceStrategies->resolve($urls->first());

        $payload = [
            'marketplace' => $store->name,
            'marketplace_strategy' => $marketplaceStrategy->key(),
            // Cast to object so an empty array (no marketplace-specific options)
            // still serializes as a JSON object `{}` rather than `[]` — Hermes
            // requires agent_options to be an object.
            'agent_options' => (object) $marketplaceStrategy->agentOptions($urls->first()),
            'goal' => $goal,
            'urls' => $urls->values()->all(),
            'tags' => $store->tags->pluck('name')->values()->all(),
            'allowed_hosts' => $allowedHosts,
            'store_id' => $store->id,
            'min_products' => $store->agent_max_products,
            'min_discount_percentage' => $store->agent_min_discount_percentage,
        ];

        if ($this->option('dry-run')) {
            $this->info('Hermes payload:');
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return ['success' => true, 'store' => $store, 'report' => ['status' => 'dry-run']];
        }

        try {
            $response = Http::timeout(1200)
                ->post(config('services.hermes.url', 'http://hermes:8000').'/discover', $payload);

            if (! $response->successful()) {
                $this->error("Store [{$store->name}] failed with HTTP status {$response->status()}");
                $this->error($response->body());

                return ['success' => false, 'store' => $store, 'report' => []];
            }

            $report = $response->json();
            $this->saveReport($store, $report);

            if (($report['status'] ?? null) === 'incomplete') {
                $this->warn('Minimum new products not reached: '.($report['abort_reason'] ?? 'sources exhausted'));
                $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return ['success' => false, 'store' => $store, 'report' => $report];
            }

            if (isset($report['status']) && $report['status'] === 'error') {
                $this->error("Store [{$store->name}] failed: ".($report['error'] ?? 'unknown error'));

                return ['success' => false, 'store' => $store, 'report' => $report];
            }

            $this->info("Store [{$store->name}] completed.");
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return ['success' => true, 'store' => $store, 'report' => $report];
        } catch (\Exception $exception) {
            $this->error("Store [{$store->name}] failed: {$exception->getMessage()}");

            return ['success' => false, 'store' => $store, 'report' => []];
        }
    }

    /**
     * Discovery via the store's own API provider (e.g. Shopee), run in-process —
     * no Hermes involved. Candidates are posted to this same app's own
     * /discovery/candidates endpoint using PRICEBUDDY_API_TOKEN, exactly like
     * Hermes does, so ownership/tag-scoping is identical between both paths.
     */
    protected function runApiDiscovery(
        Store $store,
        MarketplaceRegistry $marketplaces,
        ApiProviderRegistry $providers,
    ): array
    {
        $this->info("Running API discovery for store: {$store->name}");

        $tags = $store->tags->pluck('name')->values();

        if ($tags->isEmpty()) {
            $this->warn("Store [{$store->name}] has no niche (tags) configured.");

            return ['success' => false, 'store' => $store, 'report' => []];
        }

        $target = (int) $store->agent_max_products;
        $provider = $providers->resolve($marketplaces->resolve($store->marketplace_id));

        if ($this->option('dry-run')) {
            $this->info('Would query the API for niches: '.$tags->implode(', '));

            return ['success' => true, 'store' => $store, 'report' => ['status' => 'dry-run']];
        }

        try {
            $candidates = $provider->fetch($store, ProductDataOperation::Discovery, [
                'tags' => $tags->all(),
                'min_discount_percentage' => (float) $store->agent_min_discount_percentage,
                'target_candidates' => $target,
            ]);
        } catch (\Throwable $exception) {
            $this->error("Store [{$store->name}] failed: {$exception->getMessage()}");

            return ['success' => false, 'store' => $store, 'report' => []];
        }

        $created = $this->ingestWithNicheFloor(
            collect($candidates),
            $tags,
            $target,
            (int) $store->discovery_min_percentage_per_tag,
        );

        $report = [
            'status' => $created >= $target ? 'completed' : 'incomplete',
            'total_candidates' => $created,
            'min_products' => $target,
        ];

        $this->saveReport($store, $report);

        if ($created < $target) {
            $this->warn("Minimum new products not reached for [{$store->name}]: {$created}/{$target}.");

            return ['success' => false, 'store' => $store, 'report' => $report];
        }

        $this->info("Store [{$store->name}] completed (created: {$created}/{$target}).");

        return ['success' => true, 'store' => $store, 'report' => $report];
    }

    /**
     * Ingest up to $target candidates, guaranteeing at least
     * ceil($target * $minPercentagePerTag / 100) from each configured niche
     * before filling the remaining slots from any niche (in the order the
     * provider returned them). A niche that simply has fewer matching
     * candidates than its floor just contributes what it has — the shortfall
     * shows up as the run finishing 'incomplete', same as today.
     *
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @param  Collection<int, string>  $tags
     */
    protected function ingestWithNicheFloor(Collection $candidates, Collection $tags, int $target, int $minPercentagePerTag): int
    {
        $byTag = $candidates->groupBy(fn (array $candidate) => data_get($candidate, 'tags.0'));
        $floorPerTag = $minPercentagePerTag > 0 ? (int) ceil($target * $minPercentagePerTag / 100) : 0;
        $created = 0;
        $attempted = [];

        $ingest = function (array $candidate) use (&$created, &$attempted): void {
            $key = (string) ($candidate['url'] ?? '');

            if ($key === '' || isset($attempted[$key])) {
                return;
            }

            $attempted[$key] = true;

            if ($this->ingestCandidate($candidate)) {
                $created++;
            }
        };

        if ($floorPerTag > 0) {
            foreach ($tags as $tag) {
                $createdForTag = 0;

                foreach ($byTag->get($tag, collect()) as $candidate) {
                    if ($createdForTag >= $floorPerTag || $created >= $target) {
                        break;
                    }

                    $before = $created;
                    $ingest($candidate);

                    if ($created > $before) {
                        $createdForTag++;
                    }
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($created >= $target) {
                break;
            }

            $ingest($candidate);
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    protected function ingestCandidate(array $candidate): bool
    {
        $token = config('services.pricebuddy.api_token');

        if (blank($token)) {
            $this->warn('PRICEBUDDY_API_TOKEN is not configured; cannot ingest API-discovered candidates.');

            return false;
        }

        $baseUrl = rtrim((string) config('services.pricebuddy.api_base_url', 'http://app/api'), '/');

        try {
            $response = Http::withToken($token)->post($baseUrl.'/discovery/candidates', $candidate);
        } catch (\Exception $exception) {
            $this->warn("Failed to ingest candidate {$candidate['url']}: {$exception->getMessage()}");

            return false;
        }

        if (! $response->successful()) {
            $this->warn("Candidate {$candidate['url']} rejected with HTTP status {$response->status()}");

            return false;
        }

        return (bool) $response->json('created', false);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    protected function saveReport(Store $store, array $report): void
    {
        $reportPath = 'hermes/reports/'.now()->format('Ymd-His').'-'.Str::uuid().'.json';
        $saved = Storage::disk('local')->put($reportPath, json_encode([
            'store_id' => $store->id,
            'store_name' => $store->name,
            'report' => $report,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if ($saved) {
            $this->line('Report saved: '.Storage::disk('local')->path($reportPath));
        } else {
            $this->warn('Could not persist the discovery report.');
        }
    }

    /**
     * Display a summary of all store executions.
     *
     * @param  array<int, array{success: bool, store: Store, report: array}>  $results
     */
    protected function displaySummary(array $results): void
    {
        $this->newLine();
        $this->info('=== Discovery Run Summary ===');

        foreach ($results as $result) {
            $store = $result['store'];
            $report = $result['report'];
            $status = $result['success'] ? 'completed' : ($report['status'] ?? 'error');
            $created = $report['total_candidates'] ?? 0;
            $target = $report['min_products'] ?? $store->agent_max_products;
            $abortReason = $report['abort_reason'] ?? null;

            $this->line(sprintf(
                '%s: %s (created: %d/%d)%s',
                $store->name,
                $status,
                $created,
                $target,
                $abortReason ? " — {$abortReason}" : ''
            ));
        }

        $failed = count(array_filter($results, fn ($r) => ! $r['success']));
        $this->line(sprintf('Total: %d stores, %d failed.', count($results), $failed));
    }
}

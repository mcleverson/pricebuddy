<?php

namespace App\Console\Commands;

use App\Enums\AccessMode;
use App\Models\Store;
use App\Services\Scraping\MarketplaceStrategyResolver;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
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
        ' {--all : Run all stores in agentic mode, in sequence}'.
        ' {--dry-run : Show the command instead of executing it}';

    /**
     * The console command description.
     */
    protected $description = 'Run Hermes agentic discovery for a store configured with access_mode=agentic';

    /**
     * Execute the console command.
     */
    public function handle(MarketplaceStrategyResolver $marketplaceStrategies): int
    {
        $stores = $this->resolveStores();

        if ($stores->isEmpty()) {
            $this->warn('No agentic store found.');

            return SymfonyCommand::FAILURE;
        }

        $results = [];
        foreach ($stores as $store) {
            $results[] = $this->runStrategy($store, $marketplaceStrategies);
        }

        $this->displaySummary($results);

        $hasFailure = collect($results)->contains(fn (array $result) => ! $result['success']);

        return $hasFailure ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Store>
     */
    protected function resolveStores(): \Illuminate\Support\Collection
    {
        $agentic = fn () => Store::query()
            ->where('access_mode', AccessMode::Agentic)
            ->with('tags')
            ->get();

        if ($this->option('all')) {
            return $agentic();
        }

        $identifier = $this->argument('store');

        if ($identifier === null) {
            $stores = $agentic();

            if ($stores->isEmpty()) {
                return $stores;
            }

            $selectedId = select(
                label: 'Which store would you like to run?',
                options: $stores->mapWithKeys(fn (Store $store) => [
                    $store->id => $store->name,
                ])->all(),
            );

            return $stores->where('id', $selectedId);
        }

        $store = Store::query()
            ->where('access_mode', AccessMode::Agentic)
            ->with('tags')
            ->where(fn ($query) => $query->where('id', $identifier)->orWhere('name', $identifier))
            ->first();

        if ($store === null) {
            return collect();
        }

        return collect([$store]);
    }

    protected function runStrategy(
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
     * Display a summary of all store executions.
     *
     * @param  array<int, array{success: bool, store: Store, report: array}>  $results
     */
    protected function displaySummary(array $results): void
    {
        $this->newLine();
        $this->info('=== Agentic Discovery Run Summary ===');

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

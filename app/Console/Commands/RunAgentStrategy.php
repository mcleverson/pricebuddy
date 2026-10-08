<?php

namespace App\Console\Commands;

use App\Enums\AccessMode;
use App\Enums\ProductDataOperation;
use App\Models\Store;
use App\Models\Tag;
use App\Models\User;
use App\Services\ProductData\ApiProviderRegistry;
use App\Services\ProductData\MarketplaceRegistry;
use App\Services\Scraping\MarketplaceStrategyResolver;
use Filament\Notifications\Notification;
use Illuminate\Console\Application;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

use function Laravel\Prompts\select;

class RunAgentStrategy extends Command implements PromptsForMissingInput
{
    const COMMAND = 'buddy:agent-strategy-run';

    /** Upper bound of fetch → evaluate → ingest rounds for Api discovery. */
    const API_DISCOVERY_MAX_ROUNDS = 3;

    /**
     * The name and signature of the console command.
     */
    protected $signature = self::COMMAND.' {store?* : The IDs or names of the stores, run in sequence}'.
        ' {--all : Run all discovery-eligible stores, in sequence}'.
        ' {--notify= : ID of the user notified in the panel when each store starts and when the run ends}'.
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
        foreach ($stores->values() as $index => $store) {
            $this->notifyUser(Notification::make()
                ->title("Discovery started: {$store->name}")
                ->body(sprintf('Store %d of %d', $index + 1, $stores->count()))
                ->info());

            $results[] = $store->access_mode === AccessMode::Api
                ? $this->runApiDiscovery($store, $marketplaces, $providers)
                : $this->runAgenticDiscovery($store, $marketplaceStrategies);
        }

        $this->displaySummary($results);

        $hasFailure = collect($results)->contains(fn (array $result) => ! $result['success']);

        $this->notifyUser(Notification::make()
            ->title('Discovery finished')
            ->body(implode('<br>', array_map('e', $this->summaryLines($results))))
            ->status($hasFailure ? 'warning' : 'success'));

        return $hasFailure ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }

    /**
     * Stores this command can run, in the order a full run visits them.
     *
     * @return Collection<int, Store>
     */
    public static function eligibleStores(): Collection
    {
        $marketplaces = app(MarketplaceRegistry::class);
        $providers = app(ApiProviderRegistry::class);

        return Store::query()
            ->whereIn('access_mode', [AccessMode::Agentic, AccessMode::Api])
            ->with('tags')
            ->get()
            ->filter(fn (Store $store): bool => self::isDiscoveryEligible($store, $marketplaces, $providers))
            ->values();
    }

    /**
     * A discovery run is active, started from the panel or the terminal.
     * The bracket keeps pgrep from matching its own command line.
     */
    public static function isRunning(): bool
    {
        $pattern = '['.substr(self::COMMAND, 0, 1).']'.substr(self::COMMAND, 1);

        return Process::run(['pgrep', '-f', $pattern])->successful();
    }

    /**
     * Start a run of these stores detached from the request (a run takes up to
     * HERMES_RUN_TIMEOUT_SECONDS per store, so neither the request nor the
     * single queue worker can hold it). Output goes to storage/logs/discovery-*.log.
     *
     * @param  array<int, int>  $storeIds
     */
    public static function startInBackground(array $storeIds, User $notify): void
    {
        $command = Application::formatCommandString(implode(' ', [
            self::COMMAND, ...array_map('intval', $storeIds), '--notify='.(int) $notify->id,
        ]));
        $log = escapeshellarg(storage_path('logs/discovery-'.now()->format('Y-m-d').'.log'));

        Process::path(base_path())->run("setsid nohup {$command} >> {$log} 2>&1 < /dev/null &");
    }

    /**
     * @return Collection<int, Store>
     */
    protected function resolveStores(MarketplaceRegistry $marketplaces, ApiProviderRegistry $providers): Collection
    {
        if ($this->option('all')) {
            return self::eligibleStores();
        }

        $identifiers = (array) $this->argument('store');

        if ($identifiers === []) {
            $stores = self::eligibleStores();

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

        return Store::query()
            ->whereIn('access_mode', [AccessMode::Agentic, AccessMode::Api])
            ->with('tags')
            ->where(fn ($query) => $query->whereIn('id', $identifiers)->orWhereIn('name', $identifiers))
            ->get()
            ->filter(fn (Store $store): bool => self::isDiscoveryEligible($store, $marketplaces, $providers))
            // In the order given.
            ->sortBy(fn (Store $store): int => (int) collect($identifiers)->search(
                fn (string $identifier): bool => $identifier === (string) $store->id || $identifier === $store->name,
            ))
            ->values();
    }

    /**
     * Agentic stores always run via Hermes. Api stores only run when their
     * configured provider actually supports Discovery (e.g. Shopee) — otherwise
     * this command has nothing to do for them (price refresh is a separate,
     * unrelated flow).
     */
    protected static function isDiscoveryEligible(Store $store, MarketplaceRegistry $marketplaces, ApiProviderRegistry $providers): bool
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

    /**
     * Hermes browser options the Store overrides, typed the way Hermes expects
     * (it checks `is True` and int types). Blank fields mean "use the
     * marketplace default" and are dropped.
     *
     * @return array<string, bool|int|list<string>>
     */
    protected function storeAgentOptions(Store $store): array
    {
        $options = [];

        foreach ((array) data_get($store->settings, 'agent_options', []) as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $typed = match (true) {
                in_array($key, Store::AGENT_BOOLEAN_OPTIONS, true) => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
                in_array($key, Store::AGENT_INTEGER_OPTIONS, true) => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null,
                in_array($key, Store::AGENT_LIST_OPTIONS, true) => array_values(array_filter(array_map('trim', array_filter((array) $value, 'is_string')), fn (string $item): bool => $item !== '')) ?: null,
                default => null,
            };

            if ($typed !== null) {
                $options[$key] = $typed;
            }
        }

        return $options;
    }

    /**
     * All relevance rules live in the niche (tag), so every store and
     * discovery path using a niche applies the same rules.
     *
     * @return array{niches: array<string, array<string, mixed>>, brand_policy?: string}|null
     */
    protected function relevanceProfile(Store $store): ?array
    {
        $niches = [];

        /** @var Tag $tag */
        foreach ($store->tags as $tag) {
            $profile = $this->cleanProfile((array) $tag->relevance_profile);
            if ($profile !== []) {
                $niches[$tag->name] = $profile;
            }
        }

        if ($niches === []) {
            return null;
        }

        // Store-level strategy choice; unset keeps the default (recognized) in Hermes.
        $brandPolicy = data_get($store->settings, 'discovery_brand_policy');

        return in_array($brandPolicy, ['any', 'recognized', 'priority'], true)
            ? ['niches' => $niches, 'brand_policy' => $brandPolicy]
            : ['niches' => $niches];
    }

    /**
     * Drop blank values and trim strings so Hermes receives only meaningful rules.
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    protected function cleanProfile(array $profile): array
    {
        $clean = [];

        foreach ($profile as $key => $value) {
            if (is_array($value)) {
                $value = array_values(array_filter(
                    array_map('trim', array_filter($value, 'is_string')),
                    fn (string $item): bool => $item !== '',
                ));
            } elseif (is_string($value)) {
                $value = trim($value);
            }

            if (in_array($key, ['min_price', 'max_price'], true)) {
                $value = is_numeric($value) && (float) $value > 0 ? (float) $value : null;
            }

            if ($value !== null && $value !== '' && $value !== []) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    protected function runAgenticDiscovery(
        Store $store,
        MarketplaceStrategyResolver $marketplaceStrategies,
    ): array {
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
            // Store overrides (set on the Store screen) win over the marketplace
            // strategy defaults, the same precedence ScrapingGateway uses.
            'agent_options' => (object) array_replace(
                $marketplaceStrategy->agentOptions($urls->first()),
                $this->storeAgentOptions($store),
            ),
            'goal' => $goal,
            'urls' => $urls->values()->all(),
            'tags' => $store->tags->pluck('name')->values()->all(),
            'allowed_hosts' => $allowedHosts,
            'store_id' => $store->id,
            'min_products' => $store->agent_max_products,
            'min_discount_percentage' => $store->agent_min_discount_percentage,
            'min_rating' => (float) $store->discovery_min_rating,
            'min_sales' => (int) $store->discovery_min_sales,
        ];

        // Only the relevance profiles of this store's own niches — never a
        // global catalog. Omitted entirely when none is configured, so Hermes
        // keeps today's unfiltered behavior for those stores.
        $relevanceProfile = $this->relevanceProfile($store);
        if ($relevanceProfile !== null) {
            $payload['relevance_profile'] = $relevanceProfile;
        }

        if ($this->option('dry-run')) {
            $this->info('Hermes payload:');
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return ['success' => true, 'store' => $store, 'report' => ['status' => 'dry-run']];
        }

        try {
            $response = Http::timeout(config('services.hermes.timeout'))
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
    ): array {
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

        $minPercentagePerTag = (int) $store->discovery_min_percentage_per_tag;
        $floorPerTag = $minPercentagePerTag > 0 ? (int) ceil($target * $minPercentagePerTag / 100) : 0;
        $relevanceProfile = $this->relevanceProfile($store);
        $created = 0;
        $createdPerTag = array_fill_keys($tags->all(), 0);
        $rounds = [];
        $relevance = null;
        $admittedProductKeys = [];

        // Each fetch searches the next terms of every niche's rotation, so when
        // a round falls short of the target (or of a niche's floor), another
        // round looks further instead of ending the run early. Bounded to keep
        // API and LLM usage predictable.
        for ($round = 1; $round <= self::API_DISCOVERY_MAX_ROUNDS && $created < $target; $round++) {
            $pendingTags = $tags->filter(fn (string $tag): bool => $createdPerTag[$tag] < $floorPerTag)->values();
            // Middle rounds focus on niches below their floor; the last round
            // searches every niche so any of them can fill the remaining target.
            $roundTags = $round > 1 && $round < self::API_DISCOVERY_MAX_ROUNDS && $pendingTags->isNotEmpty()
                ? $pendingTags
                : $tags;

            try {
                $candidates = collect($provider->fetch($store, ProductDataOperation::Discovery, [
                    'tags' => $roundTags->all(),
                    'min_discount_percentage' => (float) $store->agent_min_discount_percentage,
                    'min_sales' => (float) $store->discovery_min_sales,
                    'min_rating' => (float) $store->discovery_min_rating,
                    'target_candidates' => $target,
                ]));
            } catch (\Throwable $exception) {
                if ($round === 1) {
                    $this->error("Store [{$store->name}] failed: {$exception->getMessage()}");

                    return ['success' => false, 'store' => $store, 'report' => []];
                }

                $this->warn("Store [{$store->name}] round {$round} failed, keeping earlier rounds: {$exception->getMessage()}");
                break;
            }

            $roundRelevance = null;
            if ($relevanceProfile !== null) {
                [$candidates, $roundRelevance] = $this->filterByRelevance($candidates, $tags, $relevanceProfile, $admittedProductKeys);
                $relevance = $this->mergeRelevanceReports($relevance, $roundRelevance, $round);
            }

            $before = $created;
            $created = $this->ingestWithNicheFloor(
                $candidates,
                $tags,
                $target,
                $minPercentagePerTag,
                $createdPerTag,
                $created,
                // Keep room for niches still below their floor until the last round.
                reserveFloors: $round < self::API_DISCOVERY_MAX_ROUNDS,
            );
            $rounds[] = ['round' => $round, 'tags' => $roundTags->all(), 'created' => $created - $before];
            $this->line("Round {$round} ({$roundTags->implode(', ')}): created ".($created - $before).", total {$created}/{$target}.");
        }

        $report = [
            'status' => $created >= $target ? 'completed' : 'incomplete',
            'total_candidates' => $created,
            'min_products' => $target,
            'created_per_niche' => $createdPerTag,
            'rounds' => $rounds,
        ];

        if ($relevance !== null) {
            $report['relevance'] = $relevance;
        }

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
    /**
     * Apply the same relevance admission Hermes uses to API-discovered
     * candidates, via Hermes's /evaluate endpoint (one implementation of the
     * rules, prompt and cache for both discovery paths). Products the token
     * owner already has pass through untouched (price refresh only); new ones
     * are ingested only when admitted, under the niche the evaluation chose
     * rather than the keyword that happened to find them. Fails closed: if
     * the evaluation is unavailable, no new product is ingested.
     *
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @param  Collection<int, string>  $tags
     * @param  array{niches: array<string, array<string, mixed>>, brand_policy?: string}  $profile
     * @param  array<string, true>  $admittedProductKeys  product_keys admitted by earlier rounds, updated in place
     *                                                    (Hermes dedupes within one /evaluate request only)
     * @return array{0: Collection<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    protected function filterByRelevance(Collection $candidates, Collection $tags, array $profile, array &$admittedProductKeys = []): array
    {
        $existing = collect();

        try {
            $token = (string) config('services.pricebuddy.api_token');
            $baseUrl = rtrim((string) config('services.pricebuddy.api_base_url', 'http://app/api'), '/');
            $keys = [];
            $exists = [];

            foreach ($candidates->pluck('url')->filter()->unique()->values()->chunk(100) as $urls) {
                $response = Http::withToken($token)->post($baseUrl.'/discovery/candidates/check', ['urls' => $urls->values()->all()]);
                if (! $response->successful()) {
                    throw new \RuntimeException("candidate lookup failed with HTTP status {$response->status()}");
                }
                foreach ((array) $response->json('results', []) as $result) {
                    $keys[$result['url']] = $result['key'];
                    $exists[$result['url']] = (bool) $result['exists'];
                }
            }

            $existing = $candidates->filter(fn (array $candidate): bool => $exists[$candidate['url'] ?? ''] ?? false);
            $new = $candidates
                ->filter(fn (array $candidate): bool => isset($keys[$candidate['url'] ?? '']) && ! $exists[$candidate['url']])
                ->unique('url')
                ->values();

            $decisions = collect();
            foreach ($new->chunk(300) as $chunk) {
                $response = Http::timeout((int) config('services.hermes.timeout'))
                    ->post(config('services.hermes.url', 'http://hermes:8000').'/evaluate', [
                        'tags' => $tags->values()->all(),
                        'relevance_profile' => $profile,
                        'candidates' => $chunk->map(fn (array $candidate): array => [
                            'key' => $keys[$candidate['url']],
                            'url' => $candidate['url'],
                            'title' => (string) ($candidate['title'] ?? ''),
                            'price' => $candidate['price'] ?? null,
                            'original_price' => $candidate['original_price'] ?? null,
                            'tag' => data_get($candidate, 'tags.0'),
                        ])->values()->all(),
                    ]);
                if (! $response->successful()) {
                    throw new \RuntimeException("relevance evaluation failed with HTTP status {$response->status()}");
                }
                if ($response->json('enabled') !== true) {
                    // No usable rule for these niches: behave as without a profile.
                    return [$candidates, ['enabled' => false]];
                }
                $decisions = $decisions->merge($response->json('decisions', []));
            }
        } catch (\Throwable $exception) {
            $this->warn("Relevance filter unavailable, no new product ingested: {$exception->getMessage()}");

            // Known products are still refreshed; only new ones need an admission decision.
            return [$existing->values(), ['enabled' => true, 'error' => $exception->getMessage()]];
        }

        $sentKeys = $new->map(fn (array $candidate): string => $keys[$candidate['url']])->flip();
        $decisions = $decisions->keyBy('key')->map(function (array $decision) use (&$admittedProductKeys, $sentKeys): array {
            $productKey = $decision['product_key'] ?? null;
            if (($decision['ingest'] ?? false) !== true || ! is_string($productKey) || $productKey === ''
                || ! $sentKeys->has($decision['key'] ?? '')) {
                return $decision;
            }
            if (isset($admittedProductKeys[$productKey])) {
                return [...$decision, 'ingest' => false, 'excluded_reason' => 'duplicate product'];
            }
            $admittedProductKeys[$productKey] = true;

            return $decision;
        });
        $admitted = $new
            ->filter(fn (array $candidate): bool => data_get($decisions->get($keys[$candidate['url']]), 'ingest') === true)
            ->map(function (array $candidate) use ($decisions, $keys, $tags): array {
                $niche = data_get($decisions->get($keys[$candidate['url']]), 'niche');

                return $tags->contains($niche) ? [...$candidate, 'tags' => [$niche]] : $candidate;
            });

        return [$existing->merge($admitted)->values(), [
            'enabled' => true,
            'decisions_by_classification' => $decisions->countBy('classification')->all(),
            'decisions' => $new->map(fn (array $candidate): array => [
                'url' => $candidate['url'],
                'title' => $candidate['title'] ?? null,
                'listing_niche' => data_get($candidate, 'tags.0'),
                ...collect($decisions->get($keys[$candidate['url']], []))->except('key')->all(),
            ])->all(),
        ]];
    }

    /**
     * @param  array<array-key, int>  $createdPerTag  running per-niche count, updated in place across rounds
     * @param  int  $alreadyCreated  products created by earlier rounds
     * @param  bool  $reserveFloors  leave room for niches still below their floor
     *                               (a later round may still find them)
     * @return int total created, including earlier rounds
     */
    protected function ingestWithNicheFloor(
        Collection $candidates,
        Collection $tags,
        int $target,
        int $minPercentagePerTag,
        array &$createdPerTag = [],
        int $alreadyCreated = 0,
        bool $reserveFloors = false,
    ): int {
        $byTag = $candidates->groupBy(fn (array $candidate) => data_get($candidate, 'tags.0'));
        $floorPerTag = $minPercentagePerTag > 0 ? (int) ceil($target * $minPercentagePerTag / 100) : 0;
        $created = $alreadyCreated;
        $attempted = [];
        $createdPerTag += array_fill_keys($tags->all(), 0);

        $ingest = function (array $candidate) use (&$created, &$attempted, &$createdPerTag): bool {
            $key = (string) ($candidate['url'] ?? '');

            if ($key === '' || isset($attempted[$key])) {
                return false;
            }

            $attempted[$key] = true;

            if ($this->ingestCandidate($candidate)) {
                $created++;
                $tag = data_get($candidate, 'tags.0');
                if (is_string($tag) && array_key_exists($tag, $createdPerTag)) {
                    $createdPerTag[$tag]++;
                }

                return true;
            }

            return false;
        };

        if ($floorPerTag > 0) {
            // Round-robin one candidate per tag per round (rather than filling one
            // tag's floor completely before moving to the next) — otherwise, when
            // floors summed across tags exceed the target (e.g. 5 tags x 30% of a
            // small target), the first tags would consume the whole target and the
            // last ones would get nothing, defeating the point of a floor.
            $queues = $tags->mapWithKeys(fn (string $tag) => [$tag => $byTag->get($tag, collect())->values()])->all();

            $madeProgress = true;
            while ($madeProgress && $created < $target) {
                $madeProgress = false;

                foreach ($tags as $tag) {
                    if ($created >= $target || $createdPerTag[$tag] >= $floorPerTag) {
                        continue;
                    }

                    while ($queues[$tag]->isNotEmpty()) {
                        $candidate = $queues[$tag]->shift();

                        if ($ingest($candidate)) {
                            $madeProgress = true;
                            break;
                        }
                    }
                }
            }
        }

        $reserved = $reserveFloors
            ? array_sum(array_map(fn (int $count): int => max(0, $floorPerTag - $count), $createdPerTag))
            : 0;

        foreach ($candidates as $candidate) {
            if ($created >= $target - $reserved) {
                break;
            }

            $tag = data_get($candidate, 'tags.0');
            $wasBelowFloor = is_string($tag) && ($createdPerTag[$tag] ?? $floorPerTag) < $floorPerTag;
            if ($ingest($candidate) && $wasBelowFloor) {
                // A product for a niche below its floor fills part of the reservation.
                $reserved--;
            }
        }

        return $created;
    }

    /**
     * Accumulate per-round relevance reports into one run report.
     *
     * @param  array<string, mixed>|null  $report
     * @param  array<string, mixed>  $round
     * @return array<string, mixed>
     */
    protected function mergeRelevanceReports(?array $report, array $round, int $number): array
    {
        $decisions = array_map(fn (array $decision): array => [...$decision, 'round' => $number], (array) ($round['decisions'] ?? []));

        if ($report === null) {
            return [...$round, 'decisions' => $decisions];
        }

        foreach ((array) ($round['decisions_by_classification'] ?? []) as $classification => $count) {
            $report['decisions_by_classification'][$classification] = ($report['decisions_by_classification'][$classification] ?? 0) + $count;
        }
        $report['decisions'] = [...(array) ($report['decisions'] ?? []), ...$decisions];
        if (isset($round['error'])) {
            $report['errors'][] = "round {$number}: {$round['error']}";
        }

        return $report;
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

        foreach ($this->summaryLines($results) as $line) {
            $this->line($line);
        }
    }

    /**
     * One line per store plus the totals, for the terminal and the panel notification.
     *
     * @param  array<int, array{success: bool, store: Store, report: array}>  $results
     * @return array<int, string>
     */
    protected function summaryLines(array $results): array
    {
        $lines = [];

        foreach ($results as $result) {
            $store = $result['store'];
            $report = $result['report'];
            $status = $result['success'] ? 'completed' : ($report['status'] ?? 'error');
            $created = $report['total_candidates'] ?? 0;
            $target = $report['min_products'] ?? $store->agent_max_products;
            $abortReason = $report['abort_reason'] ?? null;

            $lines[] = sprintf(
                '%s: %s (created: %d/%d)%s',
                $store->name,
                $status,
                $created,
                $target,
                $abortReason ? " — {$abortReason}" : ''
            );
        }

        $failed = count(array_filter($results, fn ($r) => ! $r['success']));
        $lines[] = sprintf('Total: %d stores, %d failed.', count($results), $failed);

        return $lines;
    }

    /**
     * Panel (bell) notification for the user given in --notify; runs from the terminal stay silent.
     */
    protected function notifyUser(Notification $notification): void
    {
        $user = $this->option('notify') ? User::find($this->option('notify')) : null;

        if ($user !== null) {
            $notification->sendToDatabase($user);
        }
    }
}

<?php

namespace App\Services\Carousels;

use App\Console\Commands\RunAgentStrategy;
use App\Contracts\MarketplaceBrowserStrategy;
use App\Enums\AccessMode;
use App\Enums\AiFeature;
use App\Enums\ProductDataOperation;
use App\Exceptions\AiProviderException;
use App\Models\Carousel;
use App\Models\CarouselCandidate;
use App\Models\Store;
use App\Models\Url;
use App\Services\AiService;
use App\Services\Helpers\CurrencyHelper;
use App\Services\Helpers\IntegrationHelper;
use App\Services\ProductData\ApiProviderRegistry;
use App\Services\ProductData\MarketplaceRegistry;
use App\Services\ProductData\ProductDataGateway;
use App\Services\Scraping\MarketplaceStrategyResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Finds products for a carousel niche in the stores that already support
 * discovery, reusing their existing paths: the store's API provider (e.g.
 * Shopee keyword search) or Hermes browsing the marketplace's own search page.
 * Nothing is added to the catalog here; candidates stay on the carousel.
 */
class CarouselDiscovery
{
    /** Candidates kept per store for scoring, as a multiple of the per-store target. */
    public const KEEP_FACTOR = 3;

    /** Titles at least this similar (%) are treated as the same product. */
    public const DUPLICATE_TITLE_SIMILARITY = 90;

    /** Marketplace searches per carousel, written by the AI provider from the niche. */
    public const SEARCH_TERMS = 4;

    public const SEARCH_TERMS_INSTRUCTIONS = <<<'TEXT'
        You plan marketplace searches for an Instagram carousel about one niche. The carousel shows products that
        sell well but that people would not come across or think of when casually browsing the niche: useful,
        clever or surprising products, not the obvious items everyone already has. For example, for car gadgets a
        dash cam or a portable tire inflator, not another phone holder or USB charger.
        terms: specific product searches (2 to 4 words each, as a shopper would type them in the marketplace search
        box), each for a different kind of product within the niche. Write them in the requested language.
        TEXT;

    public function __construct(
        protected MarketplaceRegistry $marketplaces,
        protected ApiProviderRegistry $providers,
        protected MarketplaceStrategyResolver $strategies,
    ) {}

    /**
     * Stores able to search a free-text niche, limited to the ones chosen in
     * Settings > Carousels (none chosen: all of them).
     *
     * @return Collection<int, Store>
     */
    public function stores(): Collection
    {
        $selected = array_map('intval', (array) CarouselSettings::get('store_ids'));

        return RunAgentStrategy::eligibleStores()
            ->filter(fn (Store $store): bool => $selected === [] || in_array($store->getKey(), $selected, true))
            ->filter(fn (Store $store): bool => $store->access_mode === AccessMode::Api
                || $this->strategyFor($store)->searchUrl('test') !== null)
            ->values();
    }

    /**
     * Search every store in turn. A store that fails or finds little is
     * recorded in store_reports and never stops the others.
     */
    public function run(Carousel $carousel): void
    {
        $settings = CarouselSettings::all();
        $target = max(1, (int) $settings['candidates_per_store']);
        $terms = $this->searchTerms($carousel);
        $reports = [];

        foreach ($this->stores() as $store) {
            $report = ['store_id' => $store->getKey(), 'store' => $store->name, 'status' => 'completed', 'terms' => $terms];

            try {
                [$found, $note] = $store->access_mode === AccessMode::Api
                    ? [$this->fromApi($store, $terms, $target), null]
                    : $this->fromHermes($store, $carousel->niche, $terms, $target, (bool) $settings['require_image']);
                $report['found'] = count($found);
                $report['kept'] = $this->keep($carousel, $store, $found, $settings, $target * self::KEEP_FACTOR);
                if ($note !== null) {
                    $report['note'] = $note;
                }
            } catch (Throwable $exception) {
                $report['status'] = 'failed';
                $report['error'] = Str::limit($exception->getMessage(), 300);
            }

            $reports[] = $report;
            $carousel->update(['store_reports' => $reports]);
        }
    }

    /**
     * Concrete searches for the niche, so less obvious kinds of product are
     * found too (a literal "car gadgets" search mostly returns chargers and
     * phone holders). Without an AI provider, the niche itself is searched.
     *
     * @return non-empty-list<string>
     */
    public function searchTerms(Carousel $carousel): array
    {
        $provider = IntegrationHelper::resolveFeatureProvider(AiFeature::CarouselCuration);
        if ($provider === null) {
            return [$carousel->niche];
        }

        try {
            $result = AiService::new()->structured(
                self::SEARCH_TERMS_INSTRUCTIONS,
                fn (JsonSchema $schema): array => [
                    'terms' => $schema->array()->items($schema->string())->max(self::SEARCH_TERMS)->required(),
                ],
                json_encode(['niche' => $carousel->niche, 'language' => $carousel->language ?? 'pt-BR', 'count' => self::SEARCH_TERMS], JSON_UNESCAPED_UNICODE),
                $provider,
            );
        } catch (AiProviderException) {
            $result = null;
        }

        $terms = collect((array) ($result['terms'] ?? []))
            ->filter(fn (mixed $term): bool => is_string($term) && trim($term) !== '')
            ->map(fn (string $term): string => Str::limit(trim($term), 80, ''))
            ->unique(fn (string $term): string => mb_strtolower($term))
            ->take(self::SEARCH_TERMS)
            ->values()->all();

        return $terms ?: [$carousel->niche];
    }

    /**
     * @param  non-empty-list<string>  $terms
     * @return list<array<string, mixed>>
     */
    protected function fromApi(Store $store, array $terms, int $target): array
    {
        $provider = $this->providers->resolve($this->marketplaces->resolve($store->marketplace_id))
            ?? throw new RuntimeException('No API provider for this store.');

        // Search terms that are not one of the store's tags are searched by
        // their own text. No discount, sales or rating floor: carousels are not deals.
        $found = collect($provider->fetch($store, ProductDataOperation::Discovery, [
            'tags' => $terms,
            'min_discount_percentage' => 0,
            'min_sales' => 0,
            'min_rating' => 0,
            'target_candidates' => $target,
        ]));

        // Best sellers of each search first, taking turns between searches so
        // the kept candidates are not all from the first one.
        $bySearch = $found->groupBy(fn (array $candidate): string => (string) ($candidate['tags'][0] ?? ''))
            ->map(fn (Collection $candidates): array => $candidates->sortByDesc(fn (array $candidate): float => (float) ($candidate['sales'] ?? 0))->values()->all())
            ->values()->all();
        $interleaved = collect();
        for ($i = 0; $bySearch !== [] && $i < max(array_map('count', $bySearch)); $i++) {
            foreach ($bySearch as $candidates) {
                if (isset($candidates[$i])) {
                    $interleaved->push($candidates[$i]);
                }
            }
        }

        return $interleaved->map(fn (array $candidate): array => [
            'source' => CarouselCandidate::SOURCE_API,
            'url' => $candidate['url'] ?? null,
            'affiliate_url' => $candidate['affiliate_url'] ?? null,
            'title' => $candidate['title'] ?? null,
            'image' => $candidate['image'] ?? null,
            'price' => $candidate['price'] ?? null,
            'original_price' => $candidate['original_price'] ?? null,
            'rating' => $candidate['rating'] ?? null,
            'sales_count' => $candidate['sales'] ?? null,
        ])->values()->all();
    }

    /**
     * Hermes browses the marketplace's search page for each search term in
     * one run and only reports what it saw (ingest: false). Each search
     * contributes at most its share of the target. A run that ends early (time
     * or LLM budget) still returns what it collected, with the reason as a note.
     *
     * @param  non-empty-list<string>  $terms
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    protected function fromHermes(Store $store, string $niche, array $terms, int $target, bool $requireImage): array
    {
        $strategy = $this->strategyFor($store);
        $searchUrls = array_values(array_unique(array_filter(array_map(fn (string $term): ?string => $strategy->searchUrl($term), $terms))));
        if ($searchUrls === []) {
            throw new RuntimeException('This marketplace has no search page for the niche.');
        }
        $searchUrl = $searchUrls[0];

        $response = Http::timeout((int) config('services.hermes.timeout'))
            ->post(config('services.hermes.url', 'http://hermes:8000').'/discover', [
                'marketplace' => $store->name,
                'marketplace_strategy' => $strategy->key(),
                'agent_options' => (object) array_replace(
                    $strategy->agentOptions($searchUrl),
                    RunAgentStrategy::storeAgentOptions($store),
                    $requireImage ? ['require_image' => true] : [],
                ),
                'goal' => "Encontre produtos de {$niche} em {$store->name} úteis, diferentes e com boa procura (vendas, avaliações), que não sejam os itens óbvios do nicho, mesmo sem desconto",
                'urls' => $searchUrls,
                'tags' => [$niche],
                'allowed_hosts' => array_values(array_unique([...$store->allowedHosts(), (string) parse_url($searchUrl, PHP_URL_HOST)])),
                'store_id' => $store->getKey(),
                'min_products' => $target * 2,
                'per_source_products' => (int) ceil($target * 2 / count($searchUrls)),
                'min_discount_percentage' => 0,
                'min_rating' => 0,
                'min_sales' => 0,
                'ingest' => false,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Hermes returned HTTP {$response->status()}: ".Str::limit((string) $response->json('error'), 200));
        }

        $candidates = collect((array) $response->json('candidates', []))
            ->map(fn (array $candidate): array => [
                'source' => CarouselCandidate::SOURCE_HERMES,
                'url' => $candidate['url'] ?? null,
                'affiliate_url' => null,
                'title' => $candidate['title'] ?? null,
                'image' => $candidate['image_url'] ?? null,
                'price' => $candidate['price_value'] ?? null,
                'original_price' => $candidate['original_price_value'] ?? null,
                'rating' => is_string($candidate['rating'] ?? null) ? str_replace(',', '.', $candidate['rating']) : null,
                'sales_count' => is_string($candidate['sales_count'] ?? null) ? preg_replace('/\D+/', '', $candidate['sales_count']) : null,
            ])->values()->all();

        $reason = $response->json('error') ?? $response->json('abort_reason');
        if ($candidates === [] && $response->json('status') === 'error') {
            throw new RuntimeException('Hermes failed: '.Str::limit((string) $reason, 200));
        }

        return [$candidates, is_string($reason) && $reason !== '' ? Str::limit($reason, 200) : null];
    }

    /**
     * Add a product the user found by its URL, read through the store's usual
     * path (API provider or scraper, with its proxy and strategies). Only
     * stores already registered are accepted, since approval ingests the
     * product into that store. The candidate is scored like the others.
     *
     * @throws RuntimeException with a message for the user
     */
    public function addByUrl(Carousel $carousel, string $url): CarouselCandidate
    {
        ['store' => $store, 'scrape' => $scrape] = Url::scrapeAndResolveStore($url, createStore: false);
        if (! $store instanceof Store) {
            throw new RuntimeException('This URL is not from a registered store.');
        }
        // Scraped text ("R$ 1.299,90") is locale-formatted, API prices are numbers (see Url::updatePrice()).
        $toFloat = fn (mixed $value): ?float => match (true) {
            is_int($value), is_float($value) => (float) $value,
            is_string($value) && trim($value) !== '' => CurrencyHelper::toFloat($value, locale: $store->locale ?: 'pt_BR', iso: $store->currency ?: 'BRL'),
            default => null,
        };
        $price = $toFloat(data_get($scrape, 'price'));
        if (! $price) {
            throw new RuntimeException('Could not read the price of this product.');
        }

        try {
            $affiliateUrl = resolve(ProductDataGateway::class)->affiliateLink($store, $url);
        } catch (Throwable) {
            $affiliateUrl = null;
        }

        $kept = $this->keep($carousel, $store, [[
            'source' => CarouselCandidate::SOURCE_MANUAL,
            'url' => $url,
            'affiliate_url' => $affiliateUrl,
            'title' => data_get($scrape, 'title'),
            'image' => data_get($scrape, 'image'),
            'price' => $price,
            'original_price' => $toFloat(data_get($scrape, 'original_price')),
        ]], ['require_image' => false, 'filter_duplicates' => false], 1);
        if ($kept === 0) {
            throw new RuntimeException('This product is already on the carousel, or its page has no title.');
        }

        return $carousel->candidates()->where('url_normalized', Url::normalizeForMatch($url))->firstOrFail();
    }

    /**
     * Eligibility checks (valid URL, title, price, image when required) and
     * duplicate filtering, then store up to $limit candidates.
     *
     * @param  list<array<string, mixed>>  $found
     * @param  array<string, mixed>  $settings
     */
    protected function keep(Carousel $carousel, Store $store, array $found, array $settings, int $limit): int
    {
        $existing = $carousel->candidates()->get(['url_normalized', 'title']);
        $urls = $existing->pluck('url_normalized')->flip()->all();
        $titles = $existing->pluck('title')->map(fn (string $title): string => self::comparableTitle($title))->all();
        $kept = 0;

        foreach ($found as $candidate) {
            if ($kept >= $limit) {
                break;
            }

            $url = $candidate['url'];
            $title = is_string($candidate['title']) ? trim($candidate['title']) : '';
            $image = $candidate['image'];
            if (! is_string($url) || ! preg_match('#^https?://#i', $url) || $title === ''
                || ! is_numeric($candidate['price']) || (float) $candidate['price'] <= 0
                || ($settings['require_image'] && (! is_string($image) || ! preg_match('#^https?://#i', $image)))) {
                continue;
            }

            $normalized = Url::normalizeForMatch($url);
            $comparable = self::comparableTitle($title);
            if (isset($urls[$normalized])
                || ($settings['filter_duplicates'] && self::isNearDuplicate($comparable, $titles))) {
                continue;
            }

            $carousel->candidates()->create([
                'store_id' => $store->getKey(),
                'source' => $candidate['source'],
                'url' => $url,
                'url_normalized' => $normalized,
                'affiliate_url' => is_string($candidate['affiliate_url']) ? Str::limit($candidate['affiliate_url'], 2048, '') : null,
                'title' => Str::limit($title, 1020, ''),
                'image' => is_string($image) ? Str::limit($image, 1020, '') : null,
                'price' => (float) $candidate['price'],
                'original_price' => is_numeric($candidate['original_price']) ? (float) $candidate['original_price'] : null,
                'rating' => is_numeric($candidate['rating'] ?? null) && $candidate['rating'] >= 0 && $candidate['rating'] <= 5 ? (float) $candidate['rating'] : null,
                'sales_count' => is_numeric($candidate['sales_count'] ?? null) ? min((int) $candidate['sales_count'], 4294967295) : null,
                'status' => CarouselCandidate::STATUS_PENDING,
            ]);

            $urls[$normalized] = true;
            $titles[] = $comparable;
            $kept++;
        }

        return $kept;
    }

    public static function comparableTitle(string $title): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($title)) ?? '');
    }

    /**
     * @param  list<string>  $titles  comparable titles already kept
     */
    public static function isNearDuplicate(string $comparable, array $titles): bool
    {
        foreach ($titles as $title) {
            similar_text($comparable, $title, $percent);
            if ($percent >= self::DUPLICATE_TITLE_SIMILARITY) {
                return true;
            }
        }

        return false;
    }

    protected function strategyFor(Store $store): MarketplaceBrowserStrategy
    {
        $host = collect($store->allowedHosts())->first();

        return $this->strategies->resolve('https://'.($host ?? 'unknown.invalid').'/');
    }
}

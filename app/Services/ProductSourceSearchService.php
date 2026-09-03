<?php

namespace App\Services;

use App\Dto\StandardStrategyDto;
use App\Models\ProductSource;
use App\Services\Helpers\CurrencyHelper;
use App\Services\ProductData\ProductDataGateway;
use App\Services\Scraping\ScrapingGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Jez500\WebScraperForLaravel\Exceptions\DomSelectorException;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Jez500\WebScraperForLaravel\WebScraperInterface;
use Psr\Log\LoggerInterface;

class ProductSourceSearchService
{
    protected LoggerInterface $logger;

    protected ?string $htmlMemoryCache = null;

    public bool $logErrors = true;

    protected array $keys = [
        'list_container',
        'product_title',
        'product_url',
        'product_price',
        'product_image',
    ];

    public function __construct(protected ProductSource $source)
    {
        // @phpstan-ignore-next-line - withContext is valid.
        $this->logger = Log::channel('db')->withContext(['source' => $source->getKey()]);
    }

    public static function new(ProductSource $source): self
    {
        return resolve(static::class, ['source' => $source]);
    }

    public function makeScraper(string $query): WebScraperInterface
    {
        $result = resolve(ScrapingGateway::class)->fetch(
            url: $this->buildSearchUrl($query),
            scraperService: $this->source->scraper_service,
            storeOptions: $this->scraperOptions(),
            useCache: false,
        );

        return $result->page ?? WebScraper::http()->setBody('');
    }

    public function search(string $query): Collection
    {
        return resolve(ProductDataGateway::class)->productSearch(
            $this->source,
            $query,
            fn (): Collection => $this->searchByScraping($query),
        );
    }

    protected function searchByScraping(string $query): Collection
    {
        $strategy = data_get($this->source, 'extraction_strategy', []);
        $items = $this->getList($query);

        try {
            // For each result, instantiate a new scraper and extract the title and url.
            return $items->map(function ($item) use ($strategy) {
                $itemScraper = WebScraper::http()->setBody($item);
                $price = $this->extractOptionalValue($itemScraper, $strategy, 'product_price');

                return [
                    'title' => $this->extractValue($itemScraper, $strategy['product_title'], 'product_title'),
                    'url' => $this->scrapeUrl($itemScraper, $strategy),
                    'price' => $price === null ? null : CurrencyHelper::toFloat(
                        $price,
                        data_get($this->source, 'store.settings.locale_settings.locale', CurrencyHelper::getLocale()),
                        data_get($this->source, 'store.settings.locale_settings.currency', CurrencyHelper::getCurrency()),
                    ),
                    'image' => $this->extractOptionalValue($itemScraper, $strategy, 'product_image'),
                    'content' => $item,
                ];
            })
                ->reject(fn ($item) => empty($item['title'])
                    || empty($item['url'])
                    || ScrapeUrl::preSaveMaxLength($item['url']) === null)
                ->values();
        } catch (DomSelectorException $e) {
            $this->errorLog($e->getMessage());

            return collect();
        }
    }

    public function getHtml(string $query): ?string
    {
        if (is_null($this->htmlMemoryCache)) {
            // We cache the body response for 5 minutes to prevent multiple scrapes.
            $this->htmlMemoryCache = cache()
                ->remember(
                    'product_source_search:html:'.md5($this->source->updated_at.':'.$query),
                    now()->addMinutes(5),
                    fn () => $this->makeScraper($query)->getBody()
                );
        }

        return $this->htmlMemoryCache;
    }

    public function getList(string $query): Collection
    {
        $strategy = data_get($this->source, 'extraction_strategy', []);

        $scraper = $this->makeScraper($query);

        if ($errors = $scraper->getErrors()) {
            $this->errorLog('Error scraping Product Source search result page', [
                'store_id' => $this->source->getKey(),
                'errors' => $errors,
            ]);

            return collect();
        }

        return $this->scrapeListContainers($scraper, $strategy['list_container'] ?? []);
    }

    /**
     * Extract the raw inner HTML of each search result container so that
     * per-card scraping can resolve titles and URLs from the preserved markup.
     */
    protected function scrapeListContainers(WebScraperInterface $scraper, array $options): Collection
    {
        $type = data_get($options, 'type');
        $value = data_get($options, 'value');

        if (empty($type) || empty($value)) {
            return collect();
        }

        $method = ScrapeUrl::getMethodFromType($type);

        try {
            return match ($type) {
                'selector' => $this->scrapeSelectorHtml($scraper, $value),
                'xpath' => call_user_func_array([$scraper, $method], [$value, 'html']),
                default => call_user_func_array([$scraper, $method], $this->scrapeOptionArgs($type, $value)),
            };
        } catch (DomSelectorException $e) {
            $this->errorLog('Error scraping search result list container', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return collect();
        }
    }

    /**
     * Extract inner HTML for a CSS selector based list container.
     */
    protected function scrapeSelectorHtml(WebScraperInterface $scraper, string $value): Collection
    {
        $args = ScrapeUrl::parseSelector($value);

        // Force inner HTML so the wrapper markup is preserved for per-card scraping.
        if (($args[1] ?? '') === 'text') {
            $args[1] = 'html';
        }

        return call_user_func_array([$scraper, 'getSelector'], $args);
    }

    /**
     * Build fallback argument list for non selector/xpath list container strategies.
     */
    protected function scrapeOptionArgs(string $type, mixed $value): array
    {
        return match ($type) {
            'selector' => ScrapeUrl::parseSelector($value),
            default => [$value],
        };
    }

    /**
     * Parse the newline separated scraper service settings into key/value pairs.
     */
    protected function scraperOptions(): array
    {
        $settings = data_get($this->source, 'settings.scraper_service_settings', '');

        $options = collect(explode(PHP_EOL, (string) $settings))
            ->filter(fn ($option) => filled($option) && str_contains($option, '='))
            ->mapWithKeys(function ($option) {
                $parts = explode('=', $option, 2);

                return [trim((string) $parts[0]) => trim((string) $parts[1])];
            })
            ->toArray();

        $sleep = data_get($this->source, 'settings.scraper_sleep');
        if ($sleep !== null && $sleep !== '') {
            $options['sleep'] = (string) $sleep;
        }

        $waitUntil = data_get($this->source, 'settings.scraper_wait_until');
        if (filled($waitUntil)) {
            $options['wait-until'] = $waitUntil;
        }

        return $options;
    }

    public function buildSearchUrl(string $query): string
    {
        $searchTerm = match (data_get($this->source, 'settings.search_term_format', 'urlencode')) {
            'slug' => str($query)->slug('-')->toString(),
            default => urlencode($query),
        };

        return str_replace(':search_term', $searchTerm, $this->source->search_url);
    }

    /**
     * Extract a single value from a slot, applying prepend/append (and regex /
     * schema.org handling) via the shared StrategyExtractor. Returns null and
     * logs if the selector is invalid, so one bad item does not abort the search.
     *
     * @param  array<string, mixed>  $slot
     */
    protected function extractValue(WebScraperInterface $scraper, array $slot, string $field): ?string
    {
        $dto = StandardStrategyDto::fromArray($slot);

        if ($dto === null) {
            return null;
        }

        try {
            return StrategyExtractor::extract($scraper, $dto, $field);
        } catch (DomSelectorException $e) {
            $this->errorLog('Error extracting field from search result item', [
                'field' => $field,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        return null;
    }

    /**
     * Extract a configured optional field without requiring every source to
     * provide the strategy.
     *
     * @param  array<string, mixed>  $strategy
     */
    protected function extractOptionalValue(WebScraperInterface $scraper, array $strategy, string $field): ?string
    {
        $slot = data_get($strategy, $field);

        return is_array($slot) ? $this->extractValue($scraper, $slot, $field) : null;
    }

    protected function scrapeOption(WebScraperInterface $scraper, array $options, bool $multiple = false): Collection
    {
        $type = data_get($options, 'type');
        $value = data_get($options, 'value');

        $value = match ($type) {
            'selector' => ScrapeUrl::parseSelector($value),
            default => [$value]
        };

        $method = ScrapeUrl::getMethodFromType($type);

        try {
            // Return a collection of values.
            return call_user_func_array([$scraper, $method], $value);
        } catch (DomSelectorException $e) {
            $this->errorLog('Error scraping URL', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        return collect();
    }

    protected function scrapeUrl(WebScraperInterface $scraper, array $strategy): ?string
    {
        $url = $this->extractValue($scraper, $strategy['product_url'], 'product_url');

        if (! empty($strategy['product_url']['url_decode'])) {
            $url = urldecode((string) $url);
        }

        return $url;
    }

    protected function errorLog(string $message, array $data = []): void
    {
        if (! $this->logErrors) {
            return;
        }

        $this->logger->error($message, $data);
    }
}

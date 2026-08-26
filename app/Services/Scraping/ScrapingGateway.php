<?php

namespace App\Services\Scraping;

use App\Enums\ProxyMode;
use App\Services\Scraping\Proxy\ProxyConfig;
use App\Services\Scraping\Proxy\ProxyPool;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Jez500\WebScraperForLaravel\WebScraperApi;
use Jez500\WebScraperForLaravel\WebScraperInterface;
use Throwable;

class ScrapingGateway
{
    public function __construct(
        protected MarketplaceStrategyResolver $resolver,
        protected ProxyPool $proxyPool,
    ) {}

    public function fetch(
        string $url,
        string $scraperService,
        array $storeOptions = [],
        ?string $cookies = null,
        bool $useCache = true,
        int $cacheTtlMinutes = 0,
        int $connectTimeout = 30,
        int $requestTimeout = 30,
    ): ScrapeFetchResult {
        $strategy = $this->resolver->resolve($url);
        $mode = $this->proxyMode($storeOptions);
        $maxAttempts = $mode === ProxyMode::Disabled
            ? 1
            : max(1, (int) config('scraping.proxy.max_attempts_per_scrape', 2));
        $excluded = [];
        $last = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $proxy = $mode === ProxyMode::Disabled ? null : $this->proxyPool->acquire($excluded);

            if ($mode === ProxyMode::Required && $proxy === null) {
                return new ScrapeFetchResult(null, ['proxy_required_but_unavailable'], meta: [
                    'marketplace' => $strategy->key(),
                    'strategy' => $strategy::class,
                    'attempts' => $attempt - 1,
                    'proxy_used' => false,
                ]);
            }

            $options = $this->options($strategy, $url, $storeOptions, $proxy);
            $startedAt = microtime(true);

            try {
                $scraper = WebScraper::make($scraperService)
                    ->setConnectTimeout($connectTimeout)
                    ->setRequestTimeout($requestTimeout);

                if ($scraper instanceof WebScraperApi) {
                    $scraper->setScraperApiBaseUrl(config('price_buddy.scraper_api_url', 'http://scraper:3000'));
                }

                $scraper = $scraper
                    // `from()` in the package creates a fresh scraper instance;
                    // setUrl keeps the timeout/options already applied above.
                    ->setUrl($url)
                    ->setCacheMinsTtl($cacheTtlMinutes)
                    // A selected proxy is transport state, so do not let a cached
                    // direct response make the proxy look healthy without using it.
                    ->setUseCache($useCache && $proxy === null)
                    ->setOptions($options);

                if (filled($cookies)) {
                    $scraper->setCookies($cookies);
                }

                $page = $scraper->get();
                $errors = $this->sanitizeErrors((array) $scraper->getErrors());
                $body = (string) $page->getBody();
                $blockedReason = $strategy->detectBlockedResponse($errors, $body, null);
                $last = new ScrapeFetchResult($page, $errors, $blockedReason, [
                    'marketplace' => $strategy->key(),
                    'strategy' => $strategy::class,
                    'attempts' => $attempt,
                    'proxy_used' => $proxy !== null,
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    ...($proxy?->logContext() ?? []),
                ]);

                if ($last->successful()) {
                    if ($proxy !== null) {
                        $this->proxyPool->markSuccess($proxy);
                    }

                    $this->log('success', $last->meta);

                    return $last;
                }

                if ($proxy !== null) {
                    $this->proxyPool->markFailure($proxy);
                    $excluded[] = $proxy->id;
                }

                $this->log($blockedReason ? 'blocked' : 'error', [
                    ...$last->meta,
                    'reason' => $blockedReason ?: 'scraper_error',
                ]);
            } catch (Throwable $exception) {
                if ($proxy !== null) {
                    $this->proxyPool->markFailure($proxy);
                    $excluded[] = $proxy->id;
                }

                $last = new ScrapeFetchResult(null, $this->sanitizeErrors([$exception->getMessage()]), meta: [
                    'marketplace' => $strategy->key(),
                    'strategy' => $strategy::class,
                    'attempts' => $attempt,
                    'proxy_used' => $proxy !== null,
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    ...($proxy?->logContext() ?? []),
                ]);
                $this->log('error', [...$last->meta, 'reason' => 'exception']);
            }

            if ($mode === ProxyMode::Prefer && $proxy === null && $attempt === 1) {
                break;
            }
        }

        return $last ?? new ScrapeFetchResult(null, ['scrape_failed']);
    }

    protected function proxyMode(array $storeOptions): ProxyMode
    {
        $value = data_get($storeOptions, 'proxy_mode')
            ?? data_get($storeOptions, 'scraper_proxy_mode')
            ?? config('scraping.proxy.mode', ProxyMode::Disabled->value);

        return ProxyMode::tryFrom((string) $value) ?? ProxyMode::Disabled;
    }

    protected function options($strategy, string $url, array $storeOptions, ?ProxyConfig $proxy): array
    {
        $global = (array) config('scraping.global_options', []);
        $strategyOptions = $strategy->browserOptions($url);
        $store = $storeOptions;
        unset($store['proxy_mode'], $store['scraper_proxy_mode']);

        if ($proxy !== null) {
            $store['proxy'] = $proxy->scraperOption();
        }

        return array_replace($global, $strategyOptions, $store);
    }

    protected function log(string $result, array $context): void
    {
        try {
            logger()->info('Marketplace scrape '.$result, [
                ...$context,
                'result' => $result,
            ]);
        } catch (Throwable) {
            // Observability must never turn a valid page into a scrape failure
            // (and is especially important when a caller replaces the logger in tests).
        }
    }

    /**
     * Scraper API errors can echo request parameters. Keep proxy credentials,
     * cookies, headers, and token-like values out of results and logs.
     */
    protected function sanitizeErrors(array $errors): array
    {
        return $this->sanitizeValue($errors);
    }

    protected function sanitizeValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/password|username|cookie|token|secret|authorization|proxy/i', $key) === 1) {
            return '[redacted]';
        }

        if (is_array($value)) {
            return array_map(
                fn (mixed $item, int|string $itemKey): mixed => $this->sanitizeValue($item, (string) $itemKey),
                $value,
                array_keys($value),
            );
        }

        if (is_string($value)) {
            return preg_replace(
                '/(https?:\/\/)([^\s\/:@]+):([^\s@]+)@/i',
                '$1[redacted]@',
                $value,
            ) ?? '[redacted]';
        }

        return $value;
    }
}

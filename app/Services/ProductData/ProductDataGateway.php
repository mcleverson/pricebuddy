<?php

namespace App\Services\ProductData;

use App\Enums\AccessMode;
use App\Enums\ProductDataOperation;
use App\Exceptions\ProductDataAccessException;
use App\Models\Store;

class ProductDataGateway
{
    public function __construct(
        protected MarketplaceRegistry $marketplaces,
        protected ApiProviderRegistry $providers,
    ) {}

    /**
     * Resolve product details for a URL. The fallback is deliberately supplied by
     * the caller so extraction remains in ScrapeUrl and the existing gateway stack.
     *
     * @param  callable(): array<string, mixed>  $scrapingFallback
     * @return array<string, mixed>
     */
    public function productDetails(Store $store, string $url, callable $scrapingFallback): array
    {
        $result = $this->run(
            $store,
            ProductDataOperation::ProductDetails,
            ['store' => $store, 'url' => $url],
            $scrapingFallback,
            $url,
        );

        if (is_array($result)) {
            return $result;
        }

        $first = $result->first();

        return is_array($first) ? $first : [];
    }

    /**
     * Resolve a ready-to-use affiliate link for a URL, when this store's access
     * mode is Api and its provider supports generating one (e.g. Shopee). Callers
     * are expected to cache the result — this always makes a network call.
     */
    public function affiliateLink(Store $store, string $url): ?string
    {
        if ($this->accessMode($store) !== AccessMode::Api) {
            return null;
        }

        $marketplace = $this->marketplaces->resolve($store->marketplace_id, $url);
        $provider = $this->providers->resolve($marketplace);

        if ($provider === null
            || ! $provider->isConfigured($store)
            || ! $provider->supports($store, ProductDataOperation::AffiliateLink)) {
            return null;
        }

        $result = $provider->fetch($store, ProductDataOperation::AffiliateLink, ['store' => $store, 'url' => $url]);
        $link = is_array($result) ? data_get($result, 'affiliate_url') : null;

        return is_string($link) && $link !== '' ? $link : null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  callable(): mixed  $scrapingFallback
     */
    protected function run(
        Store $subject,
        ProductDataOperation $operation,
        array $context,
        callable $scrapingFallback,
        ?string $url,
    ): mixed {
        $mode = $this->accessMode($subject);

        if ($mode === AccessMode::Scraping) {
            return $scrapingFallback();
        }

        $marketplace = $this->marketplaces->resolve($subject->marketplace_id, $url);
        $provider = $this->providers->resolve($marketplace);
        $available = $provider !== null
            && $provider->isConfigured($subject)
            && $provider->supports($subject, $operation);

        if (! $available) {
            if ($mode === AccessMode::Api) {
                throw new ProductDataAccessException(sprintf(
                    'API access is required for %s, but no configured provider supports %s.',
                    $marketplace ?? 'the selected marketplace',
                    $operation->value,
                ));
            }

            return $scrapingFallback();
        }

        return $provider->fetch($subject, $operation, $context);
    }

    protected function accessMode(Store $subject): AccessMode
    {
        $value = $subject->access_mode;

        return $value instanceof AccessMode ? $value : AccessMode::Scraping;
    }
}

<?php

namespace App\Services\ProductData;

use App\Enums\AccessMode;
use App\Enums\ProductDataOperation;
use App\Exceptions\ProductDataAccessException;
use App\Models\ProductSource;
use App\Models\Store;
use Illuminate\Support\Collection;

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
     * Search a Product Source, preserving its existing HTML extraction fallback.
     *
     * @param  callable(): Collection<int, array<string, mixed>>  $scrapingFallback
     * @return Collection<int, array<string, mixed>>
     */
    public function productSearch(ProductSource $source, string $query, callable $scrapingFallback): Collection
    {
        $result = $this->run(
            $source,
            ProductDataOperation::ProductSearch,
            ['source' => $source, 'query' => $query],
            $scrapingFallback,
            $source->search_url,
        );

        return $result instanceof Collection ? $result : collect($result);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  callable(): mixed  $scrapingFallback
     */
    protected function run(
        Store|ProductSource $subject,
        ProductDataOperation $operation,
        array $context,
        callable $scrapingFallback,
        ?string $url,
    ): mixed {
        $mode = $this->accessMode($subject);

        if ($mode === AccessMode::Scraping) {
            return $scrapingFallback();
        }

        if ($subject instanceof ProductSource) {
            $subjectMarketplace = $subject->marketplace_id;

            if (blank($subjectMarketplace)) {
                $subjectMarketplace = $subject->store?->marketplace_id;
            }
        } else {
            $subjectMarketplace = $subject->marketplace_id;
        }
        $marketplace = $this->marketplaces->resolve($subjectMarketplace, $url);
        $provider = $this->providers->resolve($marketplace);
        $available = $provider !== null
            && $provider->isConfigured()
            && $provider->supports($operation);

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

        return $provider->fetch($operation, $context);
    }

    protected function accessMode(Store|ProductSource $subject): AccessMode
    {
        $value = $subject->access_mode;

        return $value instanceof AccessMode ? $value : AccessMode::Scraping;
    }
}

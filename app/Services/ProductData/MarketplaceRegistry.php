<?php

namespace App\Services\ProductData;

use App\Services\Scraping\MarketplaceStrategyResolver;

class MarketplaceRegistry
{
    /**
     * Display names for known marketplace ids. Ids without an entry here are
     * still selectable and shown as-is.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'amazon_br' => 'Amazon Brasil',
        'shopee_br' => 'Shopee Brasil',
    ];

    /** @var array<string, string> */
    private const ALIASES = [
        'shopee' => 'shopee_br',
    ];

    public function __construct(protected MarketplaceStrategyResolver $strategyResolver) {}

    public function resolve(?string $marketplaceId = null, ?string $url = null): ?string
    {
        if (filled($marketplaceId)) {
            return $this->canonicalize($marketplaceId);
        }

        if (blank($url)) {
            return null;
        }

        $key = $this->strategyResolver->resolve($url)->key();

        return $key === 'default' ? null : $this->canonicalize($key);
    }

    public function canonicalize(string $marketplaceId): string
    {
        return self::ALIASES[$marketplaceId] ?? $marketplaceId;
    }

    /**
     * Marketplace ids only select an API provider, so the options are the
     * providers registered in config/product_data.php — registering a new
     * provider there is enough to make it selectable.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return collect(array_keys((array) config('product_data.providers', [])))
            ->mapWithKeys(fn (string $id): array => [$id => self::LABELS[$id] ?? $id])
            ->all();
    }
}

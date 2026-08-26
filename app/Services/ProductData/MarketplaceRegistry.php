<?php

namespace App\Services\ProductData;

use App\Services\Scraping\MarketplaceStrategyResolver;

class MarketplaceRegistry
{
    /** @var array<string, string> */
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

    /** @return array<string, string> */
    public function options(): array
    {
        return self::LABELS;
    }
}

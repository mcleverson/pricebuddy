<?php

namespace App\Services\ProductData;

use App\Contracts\ProductDataProvider;

class ApiProviderRegistry
{
    /** @var array<string, ProductDataProvider> */
    protected array $providers;

    /**
     * @param  iterable<ProductDataProvider>|null  $providers
     */
    public function __construct(?iterable $providers = null)
    {
        $this->providers = [];

        foreach ($providers ?? $this->configuredProviders() as $provider) {
            $this->providers[$provider->marketplaceId()] = $provider;
        }
    }

    public function resolve(?string $marketplaceId): ?ProductDataProvider
    {
        return $marketplaceId === null ? null : ($this->providers[$marketplaceId] ?? null);
    }

    /** @return list<ProductDataProvider> */
    protected function configuredProviders(): array
    {
        return collect((array) config('product_data.providers', []))
            ->map(fn (string $class): ProductDataProvider => app($class))
            ->values()
            ->all();
    }
}

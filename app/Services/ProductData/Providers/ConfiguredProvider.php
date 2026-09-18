<?php

namespace App\Services\ProductData\Providers;

use App\Contracts\ProductDataProvider;
use App\Enums\ProductDataOperation;
use App\Exceptions\ProductDataAccessException;
use App\Models\Store;
use Illuminate\Support\Collection;

abstract class ConfiguredProvider implements ProductDataProvider
{
    abstract public function marketplaceId(): string;

    public function isConfigured(Store $store): bool
    {
        $credentials = (array) data_get($store->settings, 'api_credentials', []);

        return filled(array_filter($credentials));
    }

    /**
     * Providers that support anything beyond nothing must declare it explicitly
     * (which operations they support is a fact of code, not something an end
     * user should have to toggle in settings).
     */
    public function supports(Store $store, ProductDataOperation $operation): bool
    {
        return false;
    }

    public function fetch(Store $store, ProductDataOperation $operation, array $context): array|Collection
    {
        throw new ProductDataAccessException(sprintf(
            '%s is configured but has no API transport implementation for %s.',
            $this->marketplaceId(),
            $operation->value,
        ));
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    public static function credentialFields(): array
    {
        return [];
    }
}

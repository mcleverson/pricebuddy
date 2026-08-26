<?php

namespace App\Services\ProductData\Providers;

use App\Contracts\ProductDataProvider;
use App\Enums\ProductDataOperation;
use App\Exceptions\ProductDataAccessException;
use App\Services\Helpers\IntegrationHelper;
use Illuminate\Support\Collection;

abstract class ConfiguredProvider implements ProductDataProvider
{
    abstract public function marketplaceId(): string;

    public function isConfigured(): bool
    {
        $settings = IntegrationHelper::getMarketplaceSettings($this->marketplaceId());

        return (bool) data_get($settings, 'enabled', false)
            && is_array(data_get($settings, 'credentials'))
            && filled(array_filter(data_get($settings, 'credentials', [])));
    }

    public function supports(ProductDataOperation $operation): bool
    {
        return in_array(
            $operation->value,
            (array) data_get(
                IntegrationHelper::getMarketplaceSettings($this->marketplaceId()),
                'capabilities',
                [],
            ),
            true,
        );
    }

    public function fetch(ProductDataOperation $operation, array $context): array|Collection
    {
        throw new ProductDataAccessException(sprintf(
            '%s is configured but has no API transport implementation for %s.',
            $this->marketplaceId(),
            $operation->value,
        ));
    }
}

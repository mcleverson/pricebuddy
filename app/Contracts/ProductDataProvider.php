<?php

namespace App\Contracts;

use App\Enums\ProductDataOperation;
use App\Models\Store;
use Illuminate\Support\Collection;

interface ProductDataProvider
{
    public function marketplaceId(): string;

    /**
     * Whether this Store has everything this provider needs configured
     * (e.g. credentials under $store->settings['api_credentials']).
     */
    public function isConfigured(Store $store): bool;

    public function supports(Store $store, ProductDataOperation $operation): bool;

    /**
     * Return the PriceBuddy-shaped result for the requested operation.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|Collection<int, array<string, mixed>>
     */
    public function fetch(Store $store, ProductDataOperation $operation, array $context): array|Collection;

    /**
     * Filament form fields (statePath-relative to 'settings.api_credentials')
     * shown on the Store form when this marketplace is selected and Collection
     * Mode is Api. Empty when this provider needs no credentials.
     *
     * @return array<int, \Filament\Forms\Components\Component>
     */
    public static function credentialFields(): array;
}

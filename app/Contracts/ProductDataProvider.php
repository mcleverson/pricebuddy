<?php

namespace App\Contracts;

use App\Enums\ProductDataOperation;
use Illuminate\Support\Collection;

interface ProductDataProvider
{
    public function marketplaceId(): string;

    public function isConfigured(): bool;

    public function supports(ProductDataOperation $operation): bool;

    /**
     * Return the PriceBuddy-shaped result for the requested operation.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|Collection<int, array<string, mixed>>
     */
    public function fetch(ProductDataOperation $operation, array $context): array|Collection;
}

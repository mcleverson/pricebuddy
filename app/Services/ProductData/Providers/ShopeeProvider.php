<?php

namespace App\Services\ProductData\Providers;

class ShopeeProvider extends ConfiguredProvider
{
    public function marketplaceId(): string
    {
        return 'shopee_br';
    }
}

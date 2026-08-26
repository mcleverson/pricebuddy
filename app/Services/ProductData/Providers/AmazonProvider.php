<?php

namespace App\Services\ProductData\Providers;

class AmazonProvider extends ConfiguredProvider
{
    public function marketplaceId(): string
    {
        return 'amazon_br';
    }
}

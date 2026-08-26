<?php

use App\Services\ProductData\Providers\AmazonProvider;
use App\Services\ProductData\Providers\ShopeeProvider;

return [
    'providers' => [
        'amazon_br' => AmazonProvider::class,
        'shopee_br' => ShopeeProvider::class,
    ],
];

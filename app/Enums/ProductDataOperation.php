<?php

namespace App\Enums;

enum ProductDataOperation: string
{
    case ProductSearch = 'product_search';
    case ProductDetails = 'product_details';
    case Price = 'price';
    case AffiliateLink = 'affiliate_link';
}

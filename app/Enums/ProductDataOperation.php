<?php

namespace App\Enums;

enum ProductDataOperation: string
{
    case ProductDetails = 'product_details';
    case Price = 'price';
    case AffiliateLink = 'affiliate_link';
    case Discovery = 'discovery';
}

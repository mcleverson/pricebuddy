<?php

namespace App\Services\Scraping\Marketplace;

class AmazonBrowserStrategy extends AbstractMarketplaceBrowserStrategy
{
    public function key(): string { return 'amazon_br'; }

    public function domains(): array { return ['amazon.com.br']; }

    public function agentOptions(string $url): array
    {
        return [
            // Amazon coupons are mostly a "Resgatar cupom" box on the product
            // page rather than a typed code, so they are read with the product.
            'product_page_coupons' => true,
            // Product pages are /dp/<ASIN> or /gp/product/<ASIN>; anything else
            // (categories, stores, search) is never a discovery candidate.
            'product_url_patterns' => ['/(?:dp|gp/product)/[A-Z0-9]{10}'],
        ];
    }
}

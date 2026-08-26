<?php

namespace App\Services\Scraping\Marketplace;

class ShopeeBrowserStrategy extends AbstractMarketplaceBrowserStrategy
{
    public function key(): string { return 'shopee'; }

    public function domains(): array { return ['shopee.com.br', 'shopee.com']; }
}

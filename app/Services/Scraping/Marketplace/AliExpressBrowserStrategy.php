<?php

namespace App\Services\Scraping\Marketplace;

class AliExpressBrowserStrategy extends AbstractMarketplaceBrowserStrategy
{
    public function key(): string { return 'aliexpress'; }

    public function domains(): array { return ['aliexpress.com']; }
}

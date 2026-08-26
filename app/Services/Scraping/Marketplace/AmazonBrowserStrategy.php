<?php

namespace App\Services\Scraping\Marketplace;

class AmazonBrowserStrategy extends AbstractMarketplaceBrowserStrategy
{
    public function key(): string { return 'amazon_br'; }

    public function domains(): array { return ['amazon.com.br']; }
}

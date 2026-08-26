<?php

namespace App\Services\Scraping\Marketplace;

class DefaultMarketplaceBrowserStrategy extends AbstractMarketplaceBrowserStrategy
{
    public function key(): string
    {
        return 'default';
    }

    public function domains(): array
    {
        return [];
    }
}

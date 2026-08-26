<?php

namespace App\Services\Scraping\Marketplace;

class MagaluBrowserStrategy extends AbstractMarketplaceBrowserStrategy
{
    public function key(): string { return 'magalu'; }

    public function domains(): array { return ['magazineluiza.com.br', 'magalu.com']; }
}

<?php

namespace App\Services\Scraping;

use App\Contracts\MarketplaceBrowserStrategy;
use App\Services\Scraping\Marketplace\AliExpressBrowserStrategy;
use App\Services\Scraping\Marketplace\AmazonBrowserStrategy;
use App\Services\Scraping\Marketplace\DefaultMarketplaceBrowserStrategy;
use App\Services\Scraping\Marketplace\MagaluBrowserStrategy;
use App\Services\Scraping\Marketplace\MercadoLivreBrowserStrategy;
use App\Services\Scraping\Marketplace\ShopeeBrowserStrategy;
use Illuminate\Support\Uri;

class MarketplaceStrategyResolver
{
    /**
     * @var list<MarketplaceBrowserStrategy>
     */
    protected array $strategies;

    public function __construct()
    {
        $this->strategies = [
            new MercadoLivreBrowserStrategy,
            new ShopeeBrowserStrategy,
            new AmazonBrowserStrategy,
            new MagaluBrowserStrategy,
            new AliExpressBrowserStrategy,
        ];
    }

    public function resolve(string $url): MarketplaceBrowserStrategy
    {
        $host = $this->host($url);

        foreach ($this->strategies as $strategy) {
            foreach ($strategy->domains() as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return $strategy;
                }
            }
        }

        return new DefaultMarketplaceBrowserStrategy;
    }

    public function host(string $url): string
    {
        try {
            $host = Uri::of($url)->host();
        } catch (\Throwable) {
            $host = parse_url($url, PHP_URL_HOST) ?: '';
        }

        return strtolower(rtrim((string) $host, '.'));
    }
}

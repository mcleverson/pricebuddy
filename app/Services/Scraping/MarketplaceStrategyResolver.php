<?php

namespace App\Services\Scraping;

use App\Contracts\MarketplaceBrowserStrategy;
use App\Services\Scraping\Marketplace\DefaultMarketplaceBrowserStrategy;
use Illuminate\Support\Uri;

class MarketplaceStrategyResolver
{
    /**
     * @var list<MarketplaceBrowserStrategy>
     */
    protected array $strategies;

    /**
     * @param  iterable<MarketplaceBrowserStrategy>|null  $strategies
     */
    public function __construct(?iterable $strategies = null)
    {
        $configured = $strategies ?? config('scraping.marketplace_strategies', []);

        $this->strategies = [];
        foreach ($configured as $strategy) {
            $resolved = is_string($strategy) ? app($strategy) : $strategy;

            if ($resolved instanceof MarketplaceBrowserStrategy) {
                $this->strategies[] = $resolved;
            }
        }
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

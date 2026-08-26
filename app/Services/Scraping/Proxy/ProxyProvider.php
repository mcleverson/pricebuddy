<?php

namespace App\Services\Scraping\Proxy;

interface ProxyProvider
{
    /**
     * @return list<ProxyConfig>
     */
    public function all(): array;
}

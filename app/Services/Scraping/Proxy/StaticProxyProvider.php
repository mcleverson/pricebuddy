<?php

namespace App\Services\Scraping\Proxy;

class StaticProxyProvider implements ProxyProvider
{
    /**
     * @param  array<int|string, mixed>|string|null  $proxies
     */
    public function __construct(protected array|string|null $proxies = null) {}

    public function all(): array
    {
        $configured = $this->proxies ?? config('scraping.proxy.proxies', []);

        if (is_string($configured)) {
            $configured = preg_split('/[\r\n,]+/', $configured, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $result = [];
        foreach ((array) $configured as $key => $value) {
            if ($value instanceof ProxyConfig) {
                $result[] = $value;
                continue;
            }

            $id = is_string($key) ? $key : null;
            $url = is_array($value) ? ($value['url'] ?? null) : $value;
            if (! is_string($url)) {
                continue;
            }

            $proxy = ProxyConfig::fromString($url, $id);
            if ($proxy !== null) {
                $result[] = $proxy;
            }
        }

        return $result;
    }
}

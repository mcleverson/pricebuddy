<?php

namespace App\Services\Scraping\Proxy;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;

class ProxyPool
{
    public function __construct(
        protected ProxyProvider $provider,
        protected ?Repository $cache = null,
    ) {
        $this->cache ??= cache()->store();
    }

    public function acquire(array $exclude = []): ?ProxyConfig
    {
        $now = now()->timestamp;
        $excluded = array_fill_keys($exclude, true);

        return collect($this->provider->all())
            ->reject(fn (ProxyConfig $proxy): bool => isset($excluded[$proxy->id]))
            ->filter(function (ProxyConfig $proxy) use ($now): bool {
                $state = $this->state($proxy);

                return (int) ($state['cooldown_until'] ?? 0) <= $now;
            })
            ->sortBy(function (ProxyConfig $proxy): array {
                $state = $this->state($proxy);

                return [
                    (int) ($state['consecutive_failures'] ?? 0),
                    (int) ($state['last_used_at'] ?? 0),
                ];
            })
            ->first();
    }

    public function markSuccess(ProxyConfig $proxy): void
    {
        $state = $this->state($proxy);
        $state['success_count'] = (int) ($state['success_count'] ?? 0) + 1;
        $state['consecutive_failures'] = 0;
        $state['last_success_at'] = now()->timestamp;
        $state['cooldown_until'] = null;
        $this->saveState($proxy, $state);
    }

    public function markFailure(ProxyConfig $proxy): void
    {
        $state = $this->state($proxy);
        $consecutive = (int) ($state['consecutive_failures'] ?? 0) + 1;
        $state['failure_count'] = (int) ($state['failure_count'] ?? 0) + 1;
        $state['consecutive_failures'] = $consecutive;
        $state['last_failure_at'] = now()->timestamp;

        $threshold = max(1, (int) config('scraping.proxy.failure_threshold', 2));
        if ($consecutive >= $threshold) {
            $state['cooldown_until'] = now()
                ->addSeconds((int) config('scraping.proxy.cooldown_seconds', 300))
                ->timestamp;
        }

        $this->saveState($proxy, $state);
    }

    /**
     * @return array<string, int|null>
     */
    public function state(ProxyConfig $proxy): array
    {
        return (array) $this->cache?->get($this->key($proxy), []);
    }

    protected function saveState(ProxyConfig $proxy, array $state): void
    {
        $ttl = max(60, (int) config('scraping.proxy.state_ttl_seconds', 86400));
        $this->cache?->put($this->key($proxy), $state, Carbon::now()->addSeconds($ttl));
    }

    protected function key(ProxyConfig $proxy): string
    {
        return 'scraping:proxy:'.hash('sha256', $proxy->id);
    }
}

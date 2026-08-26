<?php

namespace Tests\Unit\Services\Scraping;

use App\Services\Scraping\Proxy\ProxyConfig;
use App\Services\Scraping\Proxy\ProxyPool;
use App\Services\Scraping\Proxy\StaticProxyProvider;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProxyPoolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('scraping.proxy.failure_threshold', 1);
        config()->set('scraping.proxy.cooldown_seconds', 300);
        Cache::store('array')->flush();
    }

    public function test_selects_a_healthy_proxy_and_success_resets_failures(): void
    {
        $proxy = new ProxyConfig('one', 'proxy-one.test', 8080);
        $pool = new ProxyPool(new StaticProxyProvider([$proxy]), Cache::store('array'));

        $selected = $pool->acquire();
        $this->assertSame('one', $selected?->id);

        $pool->markFailure($proxy);
        $this->assertNull($pool->acquire());

        // A success received after the failure makes the proxy healthy again.
        $pool->markSuccess($proxy);
        $this->assertSame(0, $pool->state($proxy)['consecutive_failures']);
        $this->assertSame('one', $pool->acquire()?->id);
    }

    public function test_excludes_a_proxy_for_the_next_attempt(): void
    {
        $one = new ProxyConfig('one', 'proxy-one.test', 8080);
        $two = new ProxyConfig('two', 'proxy-two.test', 8080);
        $pool = new ProxyPool(new StaticProxyProvider([$one, $two]), Cache::store('array'));

        $this->assertSame('two', $pool->acquire(['one'])?->id);
    }

    public function test_proxy_credentials_are_not_part_of_log_context(): void
    {
        $proxy = ProxyConfig::fromString('http://secret-user:secret-pass@proxy.test:8080', 'one');

        $this->assertNotNull($proxy);
        $this->assertSame('http://secret-user:secret-pass@proxy.test:8080', $proxy->scraperOption());
        $this->assertArrayNotHasKey('username', $proxy->logContext());
        $this->assertArrayNotHasKey('password', $proxy->logContext());
        $this->assertArrayNotHasKey('proxy', $proxy->logContext());
    }
}

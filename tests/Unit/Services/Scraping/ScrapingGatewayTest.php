<?php

namespace Tests\Unit\Services\Scraping;

use App\Services\Scraping\MarketplaceStrategyResolver;
use App\Services\Scraping\Proxy\ProxyConfig;
use App\Services\Scraping\Proxy\ProxyPool;
use App\Services\Scraping\Proxy\StaticProxyProvider;
use App\Services\Scraping\ScrapingGateway;
use Illuminate\Support\Facades\Cache;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Jez500\WebScraperForLaravel\WebScraperFake;
use Jez500\WebScraperForLaravel\WebScraperInterface;
use Tests\TestCase;

class ScrapingGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::store('array')->flush();
    }

    public function test_store_options_override_marketplace_and_global_options(): void
    {
        config()->set('scraping.global_options', [
            'locale' => 'en-US',
            'device' => 'global-device',
        ]);

        $fake = (new WebScraperFake)->setBody('<html>valid</html>');
        WebScraper::shouldReceive('make')->once()->andReturn($fake);

        $result = $this->gateway()->fetch(
            'https://mercadolivre.com.br/p/1',
            'api',
            ['locale' => 'pt-PT', 'device' => 'store-device'],
        );

        $this->assertTrue($result->successful());
        $this->assertSame('pt-PT', $result->page?->getOptions()['locale']);
        $this->assertSame('store-device', $result->page?->getOptions()['device']);
        $this->assertSame('America/Sao_Paulo', $result->page?->getOptions()['timezone']);
    }

    public function test_blocked_proxy_is_penalized_and_replaced_once(): void
    {
        config()->set('scraping.proxy.mode', 'prefer');
        config()->set('scraping.proxy.max_attempts_per_scrape', 2);
        config()->set('scraping.proxy.failure_threshold', 2);

        $first = $this->scraperMock('/gz/account-verification');
        $second = $this->scraperMock('<html>valid</html>');
        WebScraper::shouldReceive('make')->twice()->andReturn($first, $second);

        $one = new ProxyConfig('one', 'proxy-one.test', 8080);
        $two = new ProxyConfig('two', 'proxy-two.test', 8080);
        $gateway = new ScrapingGateway(
            new MarketplaceStrategyResolver,
            new ProxyPool(new StaticProxyProvider([$one, $two]), Cache::store('array')),
        );

        $result = $gateway->fetch('https://mercadolivre.com.br/p/1', 'api');

        $this->assertTrue($result->successful());
        $this->assertSame(2, $result->meta['attempts']);
        $this->assertSame('two', $result->meta['proxy_id']);
        $state = (new ProxyPool(
            new StaticProxyProvider([$one, $two]),
            Cache::store('array'),
        ))->state($one);
        $this->assertSame(1, $state['failure_count']);
    }

    public function test_selected_proxy_is_forwarded_to_the_scraper_api_options(): void
    {
        config()->set('scraping.proxy.mode', 'required');

        $proxy = ProxyConfig::fromString('http://proxy-user:proxy-pass@proxy-one.test:8080', 'one');
        $scraper = \Mockery::mock(WebScraperInterface::class);
        $scraper->shouldReceive('setConnectTimeout', 'setRequestTimeout', 'setUrl', 'setUseCache', 'setCacheMinsTtl')
            ->andReturnSelf();
        $scraper->shouldReceive('setOptions')
            ->once()
            ->with(\Mockery::on(fn (array $options): bool => ($options['proxy'] ?? null) === $proxy->scraperOption()))
            ->andReturnSelf();
        $scraper->shouldReceive('get')->once()->andReturnSelf();
        $scraper->shouldReceive('getErrors')->andReturn([]);
        $scraper->shouldReceive('getBody')->andReturn('<html>valid</html>');
        WebScraper::shouldReceive('make')->once()->andReturn($scraper);

        $result = (new ScrapingGateway(
            new MarketplaceStrategyResolver,
            new ProxyPool(new StaticProxyProvider([$proxy]), Cache::store('array')),
        ))->fetch('https://mercadolivre.com.br/p/1', 'api');

        $this->assertTrue($result->successful());
    }

    protected function gateway(): ScrapingGateway
    {
        return new ScrapingGateway(
            new MarketplaceStrategyResolver,
            new ProxyPool(new StaticProxyProvider([]), Cache::store('array')),
        );
    }

    protected function scraperMock(string $body): WebScraperInterface
    {
        $scraper = \Mockery::mock(WebScraperInterface::class);
        $scraper->shouldReceive('setConnectTimeout', 'setRequestTimeout', 'setUrl', 'setOptions', 'setUseCache', 'setCacheMinsTtl')
            ->andReturnSelf();
        $scraper->shouldReceive('get')->once()->andReturnSelf();
        $scraper->shouldReceive('getErrors')->andReturn([]);
        $scraper->shouldReceive('getBody')->andReturn($body);

        return $scraper;
    }
}

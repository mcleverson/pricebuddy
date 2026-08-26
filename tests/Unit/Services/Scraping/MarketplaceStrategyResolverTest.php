<?php

namespace Tests\Unit\Services\Scraping;

use App\Services\Scraping\MarketplaceStrategyResolver;
use Tests\TestCase;

class MarketplaceStrategyResolverTest extends TestCase
{
    public static function marketplaceUrls(): array
    {
        return [
            'Mercado Livre' => ['https://www.mercadolivre.com.br/p/MLB123', 'mercado_livre'],
            'Mercado Livre subdomain' => ['https://produto.mercadolivre.com.br/MLB123', 'mercado_livre'],
            'Shopee' => ['https://shopee.com.br/product/1', 'shopee'],
            'Amazon Brasil' => ['https://www.amazon.com.br/dp/B123', 'amazon_br'],
            'Magalu' => ['https://www.magazineluiza.com.br/p/1', 'magalu'],
            'AliExpress' => ['https://pt.aliexpress.com/item/1.html', 'aliexpress'],
            'Default' => ['https://example.com/product', 'default'],
        ];
    }

    /** @dataProvider marketplaceUrls */
    public function test_resolves_marketplace_from_host(string $url, string $expected): void
    {
        $this->assertSame($expected, (new MarketplaceStrategyResolver)->resolve($url)->key());
    }

    public function test_does_not_match_a_domain_that_only_contains_a_marketplace_name(): void
    {
        $this->assertSame(
            'default',
            (new MarketplaceStrategyResolver)->resolve('https://notmercadolivre.com.br/product')->key(),
        );
    }

    public function test_mercado_livre_has_only_browser_configuration_and_block_detection(): void
    {
        $strategy = (new MarketplaceStrategyResolver)->resolve('https://mercadolivre.com.br/p/1');

        $this->assertSame([
            'locale' => 'pt-BR',
            'timezone' => 'America/Sao_Paulo',
        ], $strategy->browserOptions('https://mercadolivre.com.br/p/1'));
        $this->assertSame('account_verification', $strategy->detectBlockedResponse([], '/gz/account-verification', null));
        $this->assertSame('http_403', $strategy->detectBlockedResponse(['HTTP 403'], '', null));
        $this->assertSame('http_429', $strategy->detectBlockedResponse(['429 Too Many Requests'], '', null));
    }
}

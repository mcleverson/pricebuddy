<?php

namespace Tests\Unit\Services\Scraping;

use App\Contracts\MarketplaceBrowserStrategy;
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

    public function test_accepts_a_marketplace_strategy_from_the_configured_registry(): void
    {
        $strategy = new class implements MarketplaceBrowserStrategy
        {
            public function key(): string { return 'custom_marketplace'; }

            public function domains(): array { return ['custom-marketplace.test']; }

            public function browserOptions(string $url): array { return ['locale' => 'en-US']; }

            public function agentOptions(string $url): array { return ['headless' => false]; }

            public function extractListingImages(string $body): array { return []; }

            public function detectBlockedResponse(array $errors, string $body, ?string $finalUrl = null): ?string
            {
                return null;
            }
        };

        config()->set('scraping.marketplace_strategies', [$strategy]);
        $resolver = new MarketplaceStrategyResolver;

        $this->assertSame(
            'custom_marketplace',
            $resolver->resolve('https://www.custom-marketplace.test/product')->key(),
        );
    }

    public function test_mercado_livre_has_only_browser_configuration_and_block_detection(): void
    {
        $strategy = (new MarketplaceStrategyResolver)->resolve('https://mercadolivre.com.br/p/1');

        $this->assertSame([
            'locale' => 'pt-BR',
            'timezone' => 'America/Sao_Paulo',
        ], $strategy->browserOptions('https://mercadolivre.com.br/p/1'));
        $this->assertSame([
            'locale' => 'pt-BR',
            'timezone' => 'America/Sao_Paulo',
            'headless' => false,
            'native_user_agent' => true,
            'stealth_script' => false,
            'llm_page_segment_chars' => 4000,
            'llm_max_links_per_segment' => 30,
            'listing_image_enrichment' => true,
            'require_image' => true,
        ], $strategy->agentOptions('https://mercadolivre.com.br/p/1'));
        $this->assertSame('account_verification', $strategy->detectBlockedResponse([], '/gz/account-verification', null));
        $this->assertSame('http_403', $strategy->detectBlockedResponse(['HTTP 403'], '', null));
        $this->assertSame('http_429', $strategy->detectBlockedResponse(['429 Too Many Requests'], '', null));
    }

    public function test_mercado_livre_extracts_the_image_from_the_same_structured_product_card(): void
    {
        $strategy = (new MarketplaceStrategyResolver)->resolve('https://www.mercadolivre.com.br/ofertas');
        $productUrl = 'https://www.mercadolivre.com.br/smart-tv/p/MLB12345?pdp_filters=deal%3AX'
            .'&tracking_id=listing-run';
        $imageUrl = 'https://http2.mlstatic.com/D_Q_NP_2X_756409-MLA115252629948_082026-AB.webp';
        $html = <<<HTML
            <div class="product-card">
                <img src="{$imageUrl}" alt="Smart TV">
                <div><h3><a href="{$productUrl}">Smart TV</a></h3></div>
            </div>
            <div class="product-card">
                <img src="https://example.com/wrong.jpg" alt="Wrong product">
                <div><h3><a href="https://example.com/p/1">Wrong product</a></h3></div>
            </div>
            HTML;

        $this->assertSame([
            'mercadolivre.com.br/smart-tv/p/mlb12345?pdp_filters=deal%3ax' => $imageUrl,
        ], $strategy->extractListingImages($html));
    }
}

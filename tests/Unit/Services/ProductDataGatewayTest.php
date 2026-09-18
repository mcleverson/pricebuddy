<?php

namespace Tests\Unit\Services;

use App\Contracts\ProductDataProvider;
use App\Enums\AccessMode;
use App\Enums\ProductDataOperation;
use App\Exceptions\ProductDataAccessException;
use App\Models\Store;
use App\Services\ProductData\ApiProviderRegistry;
use App\Services\ProductData\MarketplaceRegistry;
use App\Services\ProductData\ProductDataGateway;
use App\Services\Scraping\MarketplaceStrategyResolver;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ProductDataGatewayTest extends TestCase
{
    public function test_marketplace_registry_resolves_amazon_and_shopee(): void
    {
        $registry = new MarketplaceRegistry(new MarketplaceStrategyResolver);

        $this->assertSame('amazon_br', $registry->resolve(null, 'https://www.amazon.com.br/dp/ABC'));
        $this->assertSame('shopee_br', $registry->resolve(null, 'https://shopee.com.br/product/1'));
        $this->assertSame('shopee_br', $registry->resolve('shopee'));
        $this->assertNull($registry->resolve(null, 'https://example.com/product'));
    }

    public function test_provider_registry_resolves_known_provider_and_returns_null_for_unknown_marketplace(): void
    {
        $amazon = $this->provider('amazon_br');
        $registry = new ApiProviderRegistry([$amazon]);

        $this->assertSame($amazon, $registry->resolve('amazon_br'));
        $this->assertNull($registry->resolve('shopee_br'));
    }

    public function test_agentic_mode_uses_configured_provider_with_capability(): void
    {
        $provider = $this->provider('amazon_br', configured: true, operations: [ProductDataOperation::ProductDetails]);
        $gateway = $this->gateway($provider);
        $store = $this->store(AccessMode::Agentic);

        $result = $gateway->productDetails($store, 'https://amazon.com.br/dp/ABC', fn (): array => ['title' => 'scraped']);

        $this->assertSame('api', $result['origin']);
    }

    public function test_agentic_mode_falls_back_when_provider_is_not_configured_or_lacks_capability(): void
    {
        foreach ([
            $this->provider('amazon_br'),
            $this->provider('amazon_br', configured: true),
        ] as $provider) {
            $gateway = $this->gateway($provider);
            $store = $this->store(AccessMode::Agentic);
            $result = $gateway->productDetails($store, 'https://amazon.com.br/dp/ABC', fn (): array => ['origin' => 'scraping']);

            $this->assertSame('scraping', $result['origin']);
        }
    }

    public function test_api_mode_fails_clearly_when_provider_is_unavailable(): void
    {
        $this->expectException(ProductDataAccessException::class);
        $this->expectExceptionMessage('API access is required');

        $this->gateway($this->provider('amazon_br'))
            ->productDetails($this->store(AccessMode::Api), 'https://amazon.com.br/dp/ABC', fn (): array => []);
    }

    public function test_scraping_mode_always_uses_the_existing_fallback(): void
    {
        $provider = $this->provider('amazon_br', configured: true, operations: [ProductDataOperation::ProductDetails]);

        $result = $this->gateway($provider)->productDetails(
            $this->store(AccessMode::Scraping),
            'https://amazon.com.br/dp/ABC',
            fn (): array => ['origin' => 'scraping'],
        );

        $this->assertSame('scraping', $result['origin']);
    }

    private function gateway(ProductDataProvider $provider): ProductDataGateway
    {
        return new ProductDataGateway(
            new MarketplaceRegistry(new MarketplaceStrategyResolver),
            new ApiProviderRegistry([$provider]),
        );
    }

    private function store(AccessMode $accessMode): Store
    {
        return new Store([
            'marketplace_id' => 'amazon_br',
            'access_mode' => $accessMode,
        ]);
    }

    /** @param list<ProductDataOperation> $operations */
    private function provider(string $marketplaceId, bool $configured = false, array $operations = []): ProductDataProvider
    {
        return new class($marketplaceId, $configured, $operations) implements ProductDataProvider
        {
            public function __construct(
                private string $id,
                private bool $configured,
                private array $operations,
            ) {}

            public function marketplaceId(): string
            {
                return $this->id;
            }

            public function isConfigured(Store $store): bool
            {
                return $this->configured;
            }

            public function supports(Store $store, ProductDataOperation $operation): bool
            {
                return in_array($operation, $this->operations, true);
            }

            public function fetch(Store $store, ProductDataOperation $operation, array $context): array|Collection
            {
                return ['origin' => 'api'];
            }

            public static function credentialFields(): array
            {
                return [];
            }
        };
    }
}

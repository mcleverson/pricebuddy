<?php

namespace App\Services\ProductData\Providers;

use App\Enums\AccessMode;
use App\Enums\ProductDataOperation;
use App\Exceptions\ProductDataAccessException;
use App\Models\Store;
use App\Services\ProductData\Providers\Shopee\ShopeeAffiliateClient;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Illuminate\Support\Collection;

/**
 * Shopee Affiliate Open API (GraphQL). Fields/params confirmed against
 * https://www.affiliateshopee.com.br/documentacao.
 *
 * Discovery deliberately only draws from productOfferV2 and shopOfferV2 —
 * shopeeOfferV2 (campaigns/collections) returns a collection-level offerLink
 * with no individual product price/title, so it doesn't fit the single-product
 * shape IngestProductCandidateAction expects; it's not used here.
 */
class ShopeeProvider extends ConfiguredProvider
{
    // sortType values, confirmed per query in the docs (they don't share a scale).
    protected const PRODUCT_SORT_COMMISSION = 5;

    protected const PRODUCT_LIST_HIGHEST_COMMISSION = 1;

    protected const SHOP_SORT_COMMISSION = 2;

    // How many of a niche's top-commission shops to also pull products from,
    // and how many products to take per shop.
    protected const DISCOVERY_SHOP_FANOUT = 5;

    protected const DISCOVERY_PRODUCTS_PER_SHOP = 10;

    protected const PRODUCT_OFFER_FIELDS = <<<'GRAPHQL'
        nodes {
            itemId
            shopId
            productName
            productLink
            offerLink
            imageUrl
            priceMin
            priceMax
            priceDiscountRate
            commissionRate
            sellerCommissionRate
        }
        GRAPHQL;

    protected const SHOP_OFFER_FIELDS = <<<'GRAPHQL'
        nodes {
            shopId
            shopName
            commissionRate
            sellerCommCoveRatio
        }
        GRAPHQL;

    public function marketplaceId(): string
    {
        return 'shopee_br';
    }

    public function supports(Store $store, ProductDataOperation $operation): bool
    {
        return in_array($operation, [
            ProductDataOperation::ProductDetails,
            ProductDataOperation::AffiliateLink,
            ProductDataOperation::Discovery,
        ], true);
    }

    public static function credentialFields(): array
    {
        $isApiMode = fn (Get $get): bool => $get('access_mode') === AccessMode::Api->value;

        return [
            TextInput::make('app_id')
                ->label('App ID')
                ->required($isApiMode),
            TextInput::make('secret')
                ->label('Secret')
                ->password()
                ->revealable()
                ->required($isApiMode),
        ];
    }

    public function fetch(Store $store, ProductDataOperation $operation, array $context): array|Collection
    {
        return match ($operation) {
            ProductDataOperation::ProductDetails => $this->fetchProductDetails($store, $context),
            ProductDataOperation::AffiliateLink => $this->fetchAffiliateLink($store, $context),
            ProductDataOperation::Discovery => $this->fetchDiscovery($store, $context),
            default => parent::fetch($store, $operation, $context),
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function fetchProductDetails(Store $store, array $context): array
    {
        $node = $this->fetchOfferNode($store, (string) $context['url']);

        return [
            'title' => data_get($node, 'productName'),
            'price' => data_get($node, 'priceMin'),
            'original_price' => $this->originalPrice($node),
            'image' => data_get($node, 'imageUrl'),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function fetchAffiliateLink(Store $store, array $context): array
    {
        $offerLink = data_get($this->fetchOfferNode($store, (string) $context['url']), 'offerLink');

        if (filled($offerLink)) {
            return ['affiliate_url' => $offerLink];
        }

        // Fall back to generateShortLink for URLs productOfferV2 couldn't match
        // directly (e.g. a listing/collection link rather than a single item).
        $query = <<<'GRAPHQL'
            mutation GenerateShortLink($originUrl: String) {
                generateShortLink(input: { originUrl: $originUrl }) {
                    shortLink
                }
            }
            GRAPHQL;

        $data = $this->client($store)->query($query, ['originUrl' => $context['url']]);

        return ['affiliate_url' => data_get($data, 'generateShortLink.shortLink')];
    }

    /**
     * Discovery combines two of Shopee's three offer categories:
     *   - productOfferV2(keyword: ...) — products directly matching the niche.
     *   - shopOfferV2(keyword: ...) — shops with strong additional (seller-paid)
     *     commission for the niche; we then pull each shop's own top products
     *     via productOfferV2(shopId: ...), since those often carry better total
     *     commission than a plain keyword search surfaces on its own.
     *
     * @param  array<string, mixed>  $context
     * @return Collection<int, array<string, mixed>>
     */
    protected function fetchDiscovery(Store $store, array $context): Collection
    {
        $tags = collect($context['tags'] ?? [])->filter()->values();
        $minDiscountPercentage = (float) ($context['min_discount_percentage'] ?? 0);
        $client = $this->client($store);
        $limit = min(50, max(10, (int) ($context['target_candidates'] ?? 20)));
        $results = collect();

        foreach ($tags as $tag) {
            $results = $results->merge($this->productsByKeyword($client, $store, $tag, $limit, $minDiscountPercentage));

            foreach ($this->shopsByKeyword($client, $tag) as $shop) {
                $shopId = data_get($shop, 'shopId');

                if (! is_numeric($shopId)) {
                    continue;
                }

                $results = $results->merge(
                    $this->productsByShop($client, $store, (int) $shopId, $tag, self::DISCOVERY_PRODUCTS_PER_SHOP, $minDiscountPercentage),
                );
            }
        }

        return $results->unique('url')->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function productsByKeyword(ShopeeAffiliateClient $client, Store $store, string $tag, int $limit, float $minDiscountPercentage): Collection
    {
        $query = <<<GRAPHQL
            query DiscoverProducts(\$keyword: String, \$sortType: Int, \$listType: Int, \$limit: Int) {
                productOfferV2(keyword: \$keyword, sortType: \$sortType, listType: \$listType, limit: \$limit) {
                    {$this->productOfferFields()}
                }
            }
            GRAPHQL;

        $data = $client->query($query, [
            'keyword' => $tag,
            'sortType' => self::PRODUCT_SORT_COMMISSION,
            'listType' => self::PRODUCT_LIST_HIGHEST_COMMISSION,
            'limit' => $limit,
        ]);

        return $this->mapProductNodes((array) data_get($data, 'productOfferV2.nodes', []), $store, $tag, $minDiscountPercentage);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function productsByShop(ShopeeAffiliateClient $client, Store $store, int $shopId, string $tag, int $limit, float $minDiscountPercentage): Collection
    {
        $query = <<<GRAPHQL
            query ProductsByShop(\$shopId: Int, \$sortType: Int, \$limit: Int) {
                productOfferV2(shopId: \$shopId, sortType: \$sortType, limit: \$limit) {
                    {$this->productOfferFields()}
                }
            }
            GRAPHQL;

        $data = $client->query($query, [
            'shopId' => $shopId,
            'sortType' => self::PRODUCT_SORT_COMMISSION,
            'limit' => $limit,
        ]);

        return $this->mapProductNodes((array) data_get($data, 'productOfferV2.nodes', []), $store, $tag, $minDiscountPercentage);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function shopsByKeyword(ShopeeAffiliateClient $client, string $tag): array
    {
        $query = <<<GRAPHQL
            query DiscoverShops(\$keyword: String, \$sortType: Int, \$limit: Int) {
                shopOfferV2(keyword: \$keyword, sortType: \$sortType, limit: \$limit) {
                    {$this->shopOfferFields()}
                }
            }
            GRAPHQL;

        $data = $client->query($query, [
            'keyword' => $tag,
            'sortType' => self::SHOP_SORT_COMMISSION,
            'limit' => self::DISCOVERY_SHOP_FANOUT,
        ]);

        return (array) data_get($data, 'shopOfferV2.nodes', []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @return Collection<int, array<string, mixed>>
     */
    protected function mapProductNodes(array $nodes, Store $store, string $tag, float $minDiscountPercentage): Collection
    {
        return collect($nodes)
            ->filter(fn (array $node): bool => filled(data_get($node, 'offerLink')) && filled(data_get($node, 'priceMin')))
            ->filter(fn (array $node): bool => $this->meetsMinimumDiscount($node, $minDiscountPercentage))
            ->map(fn (array $node): array => [
                'url' => data_get($node, 'offerLink'),
                'title' => data_get($node, 'productName'),
                'price' => data_get($node, 'priceMin'),
                'original_price' => $this->originalPrice($node),
                'image' => data_get($node, 'imageUrl'),
                'store_id' => $store->getKey(),
                'tags' => [$tag],
            ])
            ->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fetchOfferNode(Store $store, string $url): ?array
    {
        [$shopId, $itemId] = $this->parseProductUrl($url);

        if ($shopId === null || $itemId === null) {
            throw new ProductDataAccessException("Could not extract a Shopee shop/item id from: {$url}");
        }

        $query = <<<GRAPHQL
            query ProductOffer(\$itemId: Int, \$shopId: Int) {
                productOfferV2(itemId: \$itemId, shopId: \$shopId) {
                    {$this->productOfferFields()}
                }
            }
            GRAPHQL;

        $data = $this->client($store)->query($query, ['itemId' => $itemId, 'shopId' => $shopId]);

        return data_get($data, 'productOfferV2.nodes.0');
    }

    /**
     * Parse a Shopee product URL for its [shopId, itemId] pair. Supports both
     * "-i.{shopId}.{itemId}" and "/product/{shopId}/{itemId}" URL shapes.
     *
     * @return array{0: ?int, 1: ?int}
     */
    protected function parseProductUrl(string $url): array
    {
        if (preg_match('/-i\.(\d+)\.(\d+)/', $url, $matches) === 1) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        if (preg_match('#/product/(\d+)/(\d+)#', $url, $matches) === 1) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        return [null, null];
    }

    /**
     * Mirrors Hermes's _candidate_meets_minimum_discount: Shopee's API has no
     * "minimum discount" query parameter, so the Store's configured floor is
     * enforced client-side against priceDiscountRate. When no minimum is
     * configured (0), every node passes regardless of discount data.
     *
     * @param  array<string, mixed>  $node
     */
    protected function meetsMinimumDiscount(array $node, float $minDiscountPercentage): bool
    {
        if ($minDiscountPercentage <= 0) {
            return true;
        }

        $discountRate = data_get($node, 'priceDiscountRate');

        return is_numeric($discountRate) && (float) $discountRate >= $minDiscountPercentage;
    }

    /**
     * @param  array<string, mixed>|null  $node
     */
    protected function originalPrice(?array $node): ?float
    {
        $price = data_get($node, 'priceMin');
        $discountRate = data_get($node, 'priceDiscountRate');

        if (! is_numeric($price) || ! is_numeric($discountRate) || (float) $discountRate <= 0) {
            return null;
        }

        return round((float) $price / (1 - (float) $discountRate / 100), 2);
    }

    protected function productOfferFields(): string
    {
        return static::PRODUCT_OFFER_FIELDS;
    }

    protected function shopOfferFields(): string
    {
        return static::SHOP_OFFER_FIELDS;
    }

    protected function client(Store $store): ShopeeAffiliateClient
    {
        $credentials = (array) data_get($store->settings, 'api_credentials', []);

        return new ShopeeAffiliateClient(
            (string) ($credentials['app_id'] ?? ''),
            (string) ($credentials['secret'] ?? ''),
        );
    }
}

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
    // sortType/listType values, confirmed per query in the docs (each
    // parameter has its own scale, they don't share one).
    //
    // Discovery deliberately sorts by sales rather than commission: a high
    // commissionRate is often the seller paying more because the product
    // otherwise has no organic demand (unknown brand, no track record) —
    // optimizing for it is exactly why low-appeal candidates were surfacing.
    // Sales/"top performance" biases toward products that actually sell.
    protected const PRODUCT_SORT_SALES = 2;

    protected const PRODUCT_LIST_TOP_PERFORMANCE = 2;

    protected const SHOP_SORT_POPULAR = 3;

    // How many of a niche's top shops to also pull products from, and how
    // many products to take per shop.
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
            sales
            ratingStar
        }
        GRAPHQL;

    protected const SHOP_OFFER_FIELDS = <<<'GRAPHQL'
        nodes {
            shopId
            shopName
            commissionRate
            sellerCommCoveRatio
            ratingStar
            shopType
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
        $minSales = (float) ($context['min_sales'] ?? 0);
        $minRating = (float) ($context['min_rating'] ?? 0);
        $client = $this->client($store);
        $limit = min(50, max(10, (int) ($context['target_candidates'] ?? 20)));
        $results = collect();

        foreach ($tags as $tag) {
            $results = $results->merge($this->productsByKeyword($client, $store, $tag, $limit, $minDiscountPercentage, $minSales, $minRating));

            foreach ($this->shopsByKeyword($client, $tag) as $shop) {
                $shopId = data_get($shop, 'shopId');

                if (! is_numeric($shopId)) {
                    continue;
                }

                // Skip the fan-out entirely for shops that don't meet the
                // quality bar, rather than only filtering their products
                // after the fact — no point spending an extra query on them.
                if (! $this->meetsMinimumRating($shop, $minRating)) {
                    continue;
                }

                $results = $results->merge(
                    $this->productsByShop($client, $store, (int) $shopId, $tag, self::DISCOVERY_PRODUCTS_PER_SHOP, $minDiscountPercentage, $minSales, $minRating),
                );
            }
        }

        return $results->unique('url')->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function productsByKeyword(ShopeeAffiliateClient $client, Store $store, string $tag, int $limit, float $minDiscountPercentage, float $minSales, float $minRating): Collection
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
            'sortType' => self::PRODUCT_SORT_SALES,
            'listType' => self::PRODUCT_LIST_TOP_PERFORMANCE,
            'limit' => $limit,
        ]);

        return $this->mapProductNodes((array) data_get($data, 'productOfferV2.nodes', []), $store, $tag, $minDiscountPercentage, $minSales, $minRating);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function productsByShop(ShopeeAffiliateClient $client, Store $store, int $shopId, string $tag, int $limit, float $minDiscountPercentage, float $minSales, float $minRating): Collection
    {
        $query = <<<GRAPHQL
            query ProductsByShop(\$shopId: Int64, \$sortType: Int, \$limit: Int) {
                productOfferV2(shopId: \$shopId, sortType: \$sortType, limit: \$limit) {
                    {$this->productOfferFields()}
                }
            }
            GRAPHQL;

        $data = $client->query($query, [
            // Shopee's Int64 custom scalar must be sent as a JSON string, not a
            // bare number — confirmed against the live API ("wrong type" 10010
            // otherwise).
            'shopId' => (string) $shopId,
            'sortType' => self::PRODUCT_SORT_SALES,
            'limit' => $limit,
        ]);

        return $this->mapProductNodes((array) data_get($data, 'productOfferV2.nodes', []), $store, $tag, $minDiscountPercentage, $minSales, $minRating);
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
            'sortType' => self::SHOP_SORT_POPULAR,
            'limit' => self::DISCOVERY_SHOP_FANOUT,
        ]);

        return (array) data_get($data, 'shopOfferV2.nodes', []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @return Collection<int, array<string, mixed>>
     */
    protected function mapProductNodes(array $nodes, Store $store, string $tag, float $minDiscountPercentage, float $minSales, float $minRating): Collection
    {
        return collect($nodes)
            ->filter(fn (array $node): bool => filled(data_get($node, 'productLink'))
                && filled(data_get($node, 'offerLink'))
                && filled(data_get($node, 'priceMin')))
            ->filter(fn (array $node): bool => $this->meetsMinimumDiscount($node, $minDiscountPercentage))
            ->filter(fn (array $node): bool => $this->meetsMinimumSales($node, $minSales))
            ->filter(fn (array $node): bool => $this->meetsMinimumRating($node, $minRating))
            ->map(fn (array $node): array => [
                // The canonical product page — not the affiliate short link — is
                // what gets tracked as the product's url: it's the one
                // parseProductUrl() can re-resolve (shopId/itemId) on future price
                // refreshes. The short link is only good for one-time redirects,
                // it doesn't encode either id.
                'url' => data_get($node, 'productLink'),
                'affiliate_url' => data_get($node, 'offerLink'),
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
            query ProductOffer(\$itemId: Int64, \$shopId: Int64) {
                productOfferV2(itemId: \$itemId, shopId: \$shopId) {
                    {$this->productOfferFields()}
                }
            }
            GRAPHQL;

        // Int64 must be sent as a JSON string (see productsByShop for details).
        $data = $this->client($store)->query($query, ['itemId' => (string) $itemId, 'shopId' => (string) $shopId]);

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
     * Same pattern as meetsMinimumDiscount(): enforced client-side against
     * `sales`, since productOfferV2/shopOfferV2 have no minimum-sales query
     * param. When no minimum is configured (0), every node passes regardless
     * of sales data.
     *
     * @param  array<string, mixed>  $node
     */
    protected function meetsMinimumSales(array $node, float $minSales): bool
    {
        if ($minSales <= 0) {
            return true;
        }

        $sales = data_get($node, 'sales');

        return is_numeric($sales) && (float) $sales >= $minSales;
    }

    /**
     * Same pattern as meetsMinimumDiscount(), against `ratingStar`. Also
     * reused to gate shopOfferV2 nodes before fanning out into their
     * products, since shops expose the same field.
     *
     * @param  array<string, mixed>  $node
     */
    protected function meetsMinimumRating(array $node, float $minRating): bool
    {
        if ($minRating <= 0) {
            return true;
        }

        $rating = data_get($node, 'ratingStar');

        return is_numeric($rating) && (float) $rating >= $minRating;
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

<?php

namespace Tests\Feature\Services;

use App\Enums\AccessMode;
use App\Enums\ProductDataOperation;
use App\Models\Store;
use App\Models\Tag;
use App\Services\ProductData\Providers\ShopeeProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeDiscoveryKeywordsTest extends TestCase
{
    use RefreshDatabase;

    private function store(array $tags): Store
    {
        $store = Store::factory()->create([
            'access_mode' => AccessMode::Api,
            'marketplace_id' => 'shopee_br',
            'settings' => ['api_credentials' => ['app_id' => 'id', 'secret' => 'secret']],
        ]);
        foreach ($tags as $name => $profile) {
            $store->tags()->attach(Tag::factory()->create(['name' => $name, 'relevance_profile' => $profile])->id);
        }

        return $store->load('tags');
    }

    /**
     * Runs discovery against a faked Shopee API and returns the product-search
     * keywords (in order) and the shop-search keywords that were queried.
     *
     * @return array{products: list<string>, shops: list<string>, results: \Illuminate\Support\Collection}
     */
    private function discover(Store $store): array
    {
        $products = [];
        $shops = [];
        Http::fake(function (Request $request) use (&$products, &$shops) {
            $body = json_decode($request->body(), true);
            $keyword = $body['variables']['keyword'] ?? null;
            if (str_contains($body['query'], 'shopOfferV2')) {
                $shops[] = $keyword;

                return Http::response(['data' => ['shopOfferV2' => ['nodes' => []]]]);
            }
            $products[] = $keyword;

            return Http::response(['data' => ['productOfferV2' => ['nodes' => [[
                'productName' => "Produto {$keyword}",
                'productLink' => 'https://shopee.com.br/product/1/'.md5((string) $keyword),
                'offerLink' => 'https://s.shopee.com.br/x',
                'priceMin' => '100',
                'priceDiscountRate' => 0,
            ]]]]]);
        });

        $results = app(ShopeeProvider::class)->fetch($store, ProductDataOperation::Discovery, [
            'tags' => $store->tags->pluck('name')->all(),
            'target_candidates' => 20,
        ]);

        return ['products' => $products, 'shops' => $shops, 'results' => collect($results)];
    }

    public function test_niche_without_profile_searches_by_its_name(): void
    {
        $searched = $this->discover($this->store(['Eletrônicos' => null]));

        $this->assertSame(['Eletrônicos'], $searched['products']);
        $this->assertSame(['Eletrônicos'], $searched['shops']);
    }

    public function test_profile_terms_are_searched_and_rotate_across_runs(): void
    {
        Cache::flush();
        $store = $this->store(['Eletrônicos' => [
            'include_product_types' => ['Smart TVs', 'Notebooks', ' '],
            'include_products' => ['Fone Bluetooth', 'smart tvs', 'Soundbar'],
        ]]);

        $first = $this->discover($store);
        $second = $this->discover($store);

        $this->assertSame(['Smart TVs', 'Notebooks', 'Fone Bluetooth'], $first['products']);
        $this->assertSame(['Smart TVs'], $first['shops']);
        $this->assertSame(['Soundbar', 'Smart TVs', 'Notebooks'], $second['products']);
        $this->assertSame(['Eletrônicos'], $first['results']->pluck('tags')->flatten()->unique()->values()->all());
    }

    public function test_each_niche_uses_its_own_terms(): void
    {
        Cache::flush();
        $searched = $this->discover($this->store([
            'Beleza' => ['include_product_types' => ['Perfumes']],
            'Eletrônicos' => ['include_product_types' => ['Notebooks']],
        ]));

        $this->assertSame(['Perfumes', 'Notebooks'], $searched['products']);
        $this->assertSame(
            ['Beleza', 'Eletrônicos'],
            $searched['results']->map(fn (array $candidate) => $candidate['tags'][0])->all(),
        );
    }
}

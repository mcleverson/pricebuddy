<?php

namespace Tests\Feature\Services;

use App\Models\ProductSource;
use App\Services\MercadoLivreProductSourceSearchService;
use App\Services\ProductSourceSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoLivreProductSourceSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.mercado_livre.access_token', 'test-access-token');
    }

    public function test_scraper_source_uses_product_source_search_service(): void
    {
        $source = ProductSource::factory()->make([
            'settings' => ['search_driver' => ProductSource::SEARCH_DRIVER_SCRAPER],
        ]);

        $service = $source->getSearchService();

        $this->assertInstanceOf(ProductSourceSearchService::class, $service);
    }

    public function test_mercado_livre_api_source_uses_mercado_livre_service(): void
    {
        $source = ProductSource::factory()->make([
            'settings' => ['search_driver' => ProductSource::SEARCH_DRIVER_MERCADO_LIVRE_API],
        ]);

        $service = $source->getSearchService();

        $this->assertInstanceOf(MercadoLivreProductSourceSearchService::class, $service);
    }

    public function test_sends_query_to_products_search_with_expected_params(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/products/search*' => Http::response([
                'results' => [],
            ]),
        ]);

        $source = $this->makeMercadoLivreSource();
        $service = MercadoLivreProductSourceSearchService::new($source);
        $service->search('Nike Mercurial');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.mercadolibre.com/products/search?status=active&site_id=MLB&q=Nike+Mercurial&limit=10';
        });
    }

    public function test_uses_product_ids_to_fetch_items(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/products/search*' => Http::response([
                'results' => [
                    ['id' => 'MLB123'],
                    ['id' => 'MLB456'],
                ],
            ]),
            'https://api.mercadolibre.com/products/MLB123/items' => Http::response([
                'items' => [['id' => 'MLB123001']],
            ]),
            'https://api.mercadolibre.com/products/MLB456/items' => Http::response([
                'items' => [['id' => 'MLB456001']],
            ]),
            'https://api.mercadolibre.com/items*' => Http::response([
                ['code' => 200, 'body' => ['id' => 'MLB123001', 'title' => 'Product 1', 'permalink' => 'https://example.com/1']],
                ['code' => 200, 'body' => ['id' => 'MLB456001', 'title' => 'Product 2', 'permalink' => 'https://example.com/2']],
            ]),
        ]);

        $source = $this->makeMercadoLivreSource();
        $results = MercadoLivreProductSourceSearchService::new($source)->search('query');

        $this->assertCount(2, $results);
        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://api.mercadolibre.com/products/MLB123/items');
        });
        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://api.mercadolibre.com/products/MLB456/items');
        });
    }

    public function test_deduplicates_item_ids(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/products/search*' => Http::response([
                'results' => [
                    ['id' => 'MLB123'],
                    ['id' => 'MLB456'],
                ],
            ]),
            'https://api.mercadolibre.com/products/MLB123/items' => Http::response([
                'items' => [['id' => 'SAME001'], ['id' => 'SAME001']],
            ]),
            'https://api.mercadolibre.com/products/MLB456/items' => Http::response([
                'items' => [['id' => 'SAME001'], ['id' => 'OTHER001']],
            ]),
            'https://api.mercadolibre.com/items*' => Http::response([
                ['code' => 200, 'body' => ['id' => 'SAME001', 'title' => 'Same', 'permalink' => 'https://example.com/same']],
                ['code' => 200, 'body' => ['id' => 'OTHER001', 'title' => 'Other', 'permalink' => 'https://example.com/other']],
            ]),
        ]);

        $source = $this->makeMercadoLivreSource();
        $results = MercadoLivreProductSourceSearchService::new($source)->search('query');

        $this->assertCount(2, $results);
        $this->assertSame(['https://example.com/same', 'https://example.com/other'], $results->pluck('url')->all());
    }

    public function test_limits_multiget_to_twenty_item_ids(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/products/search*' => Http::response([
                'results' => [['id' => 'MLB123']],
            ]),
            'https://api.mercadolibre.com/products/MLB123/items' => Http::response([
                'items' => collect(range(1, 25))->map(fn ($i) => ['id' => 'MLBITEM'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)])->all(),
            ]),
            'https://api.mercadolibre.com/items*' => Http::response([]),
        ]);

        $source = $this->makeMercadoLivreSource();
        MercadoLivreProductSourceSearchService::new($source)->search('query');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/items?')) {
                return false;
            }

            $ids = explode(',', $request->data('ids') ?? '');

            return count($ids) === 20;
        });
    }

    public function test_parses_verbose_multiget_response(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/products/search*' => Http::response([
                'results' => [['id' => 'MLB123']],
            ]),
            'https://api.mercadolibre.com/products/MLB123/items' => Http::response([
                'items' => [['id' => 'MLB123001']],
            ]),
            'https://api.mercadolibre.com/items*' => Http::response([
                ['code' => 200, 'body' => ['id' => 'MLB123001', 'title' => 'Nike Shoe', 'permalink' => 'https://example.com/shoe', 'thumbnail' => 'https://example.com/thumb.jpg']],
            ]),
        ]);

        $source = $this->makeMercadoLivreSource();
        $results = MercadoLivreProductSourceSearchService::new($source)->search('query');

        $this->assertCount(1, $results);
        $first = $results->first();
        $this->assertSame('Nike Shoe', $first['title']);
        $this->assertSame('https://example.com/shoe', $first['url']);
        $this->assertSame('https://example.com/thumb.jpg', $first['thumbnail']);
    }

    public function test_skips_multiget_entries_with_non_200_code(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/products/search*' => Http::response([
                'results' => [['id' => 'MLB123']],
            ]),
            'https://api.mercadolibre.com/products/MLB123/items' => Http::response([
                'items' => [['id' => 'MLB123001'], ['id' => 'MLB123002']],
            ]),
            'https://api.mercadolibre.com/items*' => Http::response([
                ['code' => 200, 'body' => ['id' => 'MLB123001', 'title' => 'Valid', 'permalink' => 'https://example.com/valid']],
                ['code' => 404, 'body' => ['id' => 'MLB123002', 'title' => 'Not Found', 'permalink' => 'https://example.com/not-found']],
            ]),
        ]);

        $source = $this->makeMercadoLivreSource();
        $results = MercadoLivreProductSourceSearchService::new($source)->search('query');

        $this->assertCount(1, $results);
        $this->assertSame('https://example.com/valid', $results->first()['url']);
    }

    public function test_skips_items_without_permalink(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/products/search*' => Http::response([
                'results' => [['id' => 'MLB123']],
            ]),
            'https://api.mercadolibre.com/products/MLB123/items' => Http::response([
                'items' => [['id' => 'MLB123001'], ['id' => 'MLB123002']],
            ]),
            'https://api.mercadolibre.com/items*' => Http::response([
                ['code' => 200, 'body' => ['id' => 'MLB123001', 'title' => 'Missing URL', 'permalink' => null]],
                ['code' => 200, 'body' => ['id' => 'MLB123002', 'title' => 'Has URL', 'permalink' => 'https://example.com/has-url']],
            ]),
        ]);

        $source = $this->makeMercadoLivreSource();
        $results = MercadoLivreProductSourceSearchService::new($source)->search('query');

        $this->assertCount(1, $results);
        $this->assertSame('https://example.com/has-url', $results->first()['url']);
    }

    public function test_returns_empty_collection_when_access_token_is_missing(): void
    {
        config()->set('services.mercado_livre.access_token', null);

        Http::fake([]);

        $source = $this->makeMercadoLivreSource();
        $results = MercadoLivreProductSourceSearchService::new($source)->search('query');

        $this->assertTrue($results->isEmpty());
        Http::assertNothingSent();
    }

    public function test_api_failure_returns_empty_collection_without_throwing(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/products/search*' => Http::response('Internal Server Error', 500),
        ]);

        $source = $this->makeMercadoLivreSource();
        $results = MercadoLivreProductSourceSearchService::new($source)->search('query');

        $this->assertTrue($results->isEmpty());
    }

    public function test_result_matches_search_service_expected_format(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/products/search*' => Http::response([
                'results' => [['id' => 'MLB123']],
            ]),
            'https://api.mercadolibre.com/products/MLB123/items' => Http::response([
                'items' => [['id' => 'MLB123001']],
            ]),
            'https://api.mercadolibre.com/items*' => Http::response([
                ['code' => 200, 'body' => ['id' => 'MLB123001', 'title' => 'Nike Shoe', 'permalink' => 'https://example.com/shoe']],
            ]),
        ]);

        $source = $this->makeMercadoLivreSource();
        $results = MercadoLivreProductSourceSearchService::new($source)->search('query');

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('title', $results->first());
        $this->assertArrayHasKey('url', $results->first());
        $this->assertArrayHasKey('content', $results->first());
        $this->assertArrayHasKey('thumbnail', $results->first());
    }

    public function test_existing_scraper_source_still_searches_with_existing_behavior(): void
    {
        $source = ProductSource::factory()->make([
            'search_url' => 'https://example.com/search?q=:search_term',
            'extraction_strategy' => [
                'list_container' => ['type' => 'selector', 'value' => '.item'],
                'product_title' => ['type' => 'selector', 'value' => 'h2'],
                'product_url' => ['type' => 'selector', 'value' => 'a|href'],
            ],
        ]);

        $service = $source->getSearchService();

        $this->assertInstanceOf(ProductSourceSearchService::class, $service);
        $this->assertSame('https://example.com/search?q=laptop', $service->buildSearchUrl('laptop'));
    }

    protected function makeMercadoLivreSource(): ProductSource
    {
        return ProductSource::factory()->make([
            'settings' => ['search_driver' => ProductSource::SEARCH_DRIVER_MERCADO_LIVRE_API],
        ]);
    }
}

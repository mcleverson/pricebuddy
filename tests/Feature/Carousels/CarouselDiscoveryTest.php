<?php

namespace Tests\Feature\Carousels;

use App\Contracts\ProductDataProvider;
use App\Enums\AccessMode;
use App\Enums\ProductDataOperation;
use App\Jobs\DiscoverCarouselProductsJob;
use App\Models\Carousel;
use App\Models\CarouselCandidate;
use App\Models\Store;
use App\Models\User;
use App\Services\Carousels\CarouselCurator;
use App\Services\Carousels\CarouselDiscovery;
use App\Services\Helpers\SettingsHelper;
use App\Services\ProductData\ApiProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CarouselDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $apiContexts = [];

    private function carousel(string $niche = 'Cantinho do café'): Carousel
    {
        return Carousel::create([
            'user_id' => User::factory()->create()->id,
            'niche' => $niche,
            'status' => Carousel::STATUS_DISCOVERING,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    private function apiStore(array $candidates): Store
    {
        $test = $this;
        $provider = new class($candidates, $test) implements ProductDataProvider
        {
            public function __construct(private array $candidates, private CarouselDiscoveryTest $test) {}

            public function marketplaceId(): string
            {
                return 'fake_api';
            }

            public function isConfigured(Store $store): bool
            {
                return true;
            }

            public function supports(Store $store, ProductDataOperation $operation): bool
            {
                return $operation === ProductDataOperation::Discovery;
            }

            public function fetch(Store $store, ProductDataOperation $operation, array $context): array
            {
                $this->test->recordApiContext($context);

                return $this->candidates;
            }

            public static function credentialFields(): array
            {
                return [];
            }
        };
        $this->app->instance(ApiProviderRegistry::class, new ApiProviderRegistry([$provider]));

        return Store::factory()->create(['name' => 'Shopee', 'access_mode' => AccessMode::Api, 'marketplace_id' => 'fake_api']);
    }

    public function recordApiContext(array $context): void
    {
        $this->apiContexts[] = $context;
    }

    private function amazonStore(): Store
    {
        return Store::factory()->create([
            'name' => 'Amazon.com.br',
            'access_mode' => AccessMode::Agentic,
            'domains' => [['domain' => 'www.amazon.com.br']],
            'agent_urls' => [['url' => 'https://www.amazon.com.br/deals']],
        ]);
    }

    private function product(string $title, array $overrides = []): array
    {
        return array_merge([
            'url' => 'https://shopee.com.br/'.md5($title),
            'affiliate_url' => 'https://s.shopee.com.br/'.substr(md5($title), 0, 6),
            'title' => $title,
            'image' => 'https://cf.shopee.com.br/'.md5($title).'.jpg',
            'price' => 49.9,
            'original_price' => null,
        ], $overrides);
    }

    public function test_api_store_searches_the_niche_text_without_deal_floors(): void
    {
        $store = $this->apiStore([$this->product('Moedor de café manual de cerâmica')]);
        $carousel = $this->carousel();

        app(CarouselDiscovery::class)->run($carousel);

        $this->assertSame(['Cantinho do café'], $this->apiContexts[0]['tags']);
        $this->assertSame(0, $this->apiContexts[0]['min_discount_percentage']);
        $candidate = $carousel->candidates()->sole();
        $this->assertSame($store->id, $candidate->store_id);
        $this->assertSame(CarouselCandidate::SOURCE_API, $candidate->source);
        $this->assertNotNull($candidate->affiliate_url);
        $this->assertSame(CarouselCandidate::STATUS_PENDING, $candidate->status);
        $this->assertSame('completed', $carousel->fresh()->store_reports[0]['status']);
    }

    public function test_hermes_store_browses_the_search_page_without_ingesting(): void
    {
        $this->amazonStore();
        Http::fake(['*/discover' => Http::response(['status' => 'incomplete', 'abort_reason' => 'Run timeout reached', 'candidates' => [[
            'url' => 'https://www.amazon.com.br/Prensa-Francesa/dp/B000000001',
            'title' => 'Prensa francesa de vidro 600ml',
            'image_url' => 'https://m.media-amazon.com/images/I/1.jpg',
            'price' => '89,90', 'price_value' => 89.9, 'original_price_value' => null,
        ]]])]);
        $carousel = $this->carousel();

        app(CarouselDiscovery::class)->run($carousel);

        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/discover')
                && $request['ingest'] === false
                && $request['min_discount_percentage'] === 0
                && $request['urls'] === ['https://www.amazon.com.br/s?k=Cantinho%20do%20caf%C3%A9']
                && json_decode($request->body(), true)['agent_options']['require_image'] === true;
        });
        $this->assertSame('89.90', $carousel->candidates()->sole()->price);
        $this->assertSame('Run timeout reached', $carousel->fresh()->store_reports[0]['note']);
    }

    public function test_a_failing_store_does_not_stop_the_others(): void
    {
        $this->amazonStore();
        $this->apiStore([$this->product('Suporte de cápsulas giratório')]);
        Http::fake(['*/discover' => Http::response(['status' => 'error', 'error' => 'A discovery run is already active'], 409)]);
        $carousel = $this->carousel();

        app(CarouselDiscovery::class)->run($carousel);

        $reports = collect($carousel->fresh()->store_reports)->keyBy('store');
        $this->assertSame('failed', $reports['Amazon.com.br']['status']);
        $this->assertStringContainsString('409', $reports['Amazon.com.br']['error']);
        $this->assertSame('completed', $reports['Shopee']['status']);
        $this->assertSame(1, $carousel->candidates()->count());
    }

    public function test_ineligible_and_duplicate_products_are_skipped(): void
    {
        $this->apiStore([
            $this->product('Caneca de cerâmica artesanal azul'),
            $this->product('Caneca de cerâmica artesanal azul!', ['url' => 'https://shopee.com.br/other']),
            $this->product('Caneca de cerâmica artesanal azul', ['affiliate_url' => null]),
            $this->product('Sem imagem', ['image' => null]),
            $this->product('Sem preço', ['price' => 0]),
            $this->product('URL inválida', ['url' => 'javascript:alert(1)']),
            $this->product('Balança digital para café com timer'),
        ]);
        $carousel = $this->carousel();

        app(CarouselDiscovery::class)->run($carousel);

        $this->assertEqualsCanonicalizing(
            ['Caneca de cerâmica artesanal azul', 'Balança digital para café com timer'],
            $carousel->candidates()->pluck('title')->all(),
        );
        $this->assertSame(7, $carousel->fresh()->store_reports[0]['found']);
        $this->assertSame(2, $carousel->fresh()->store_reports[0]['kept']);
    }

    public function test_stores_can_be_limited_in_settings(): void
    {
        $this->amazonStore();
        $shopee = $this->apiStore([]);
        SettingsHelper::setSetting('carousel_settings', ['store_ids' => [(string) $shopee->id]]);

        $this->assertSame(['Shopee'], app(CarouselDiscovery::class)->stores()->pluck('name')->all());
    }

    public function test_the_job_leaves_the_carousel_ready_for_review(): void
    {
        $this->apiStore([$this->product('Cafeteira italiana de alumínio')]);
        $carousel = $this->carousel();

        (new DiscoverCarouselProductsJob($carousel->id))->handle(app(CarouselDiscovery::class), app(CarouselCurator::class));

        $carousel->refresh();
        $this->assertSame(Carousel::STATUS_REVIEW, $carousel->status);
        // No AI provider in this test: the candidate is kept, unscored and never pre-selected.
        $this->assertStringContainsString('No AI provider', $carousel->error);
        $this->assertSame(CarouselCandidate::STATUS_UNSCORED, $carousel->candidates()->sole()->status);
        $this->assertSame(0, $carousel->selection()->count());
    }

    public function test_the_job_fails_when_no_store_returns_anything(): void
    {
        $this->apiStore([]);
        $carousel = $this->carousel();

        (new DiscoverCarouselProductsJob($carousel->id))->handle(app(CarouselDiscovery::class), app(CarouselCurator::class));

        $this->assertSame(Carousel::STATUS_FAILED, $carousel->fresh()->status);
    }

    public function test_a_store_without_a_search_page_is_not_searched(): void
    {
        Store::factory()->create([
            'access_mode' => AccessMode::Agentic,
            'domains' => [['domain' => 'example.com']],
            'agent_urls' => [['url' => 'https://example.com/offers']],
        ]);

        $this->assertCount(0, app(CarouselDiscovery::class)->stores());
    }
}

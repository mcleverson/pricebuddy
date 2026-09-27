<?php

namespace Tests\Feature\Console;

use App\Contracts\ProductDataProvider;
use App\Enums\AccessMode;
use App\Enums\ProductDataOperation;
use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Models\Store;
use App\Models\Tag;
use App\Models\User;
use App\Services\ProductData\ApiProviderRegistry;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class RunAgentStrategyTest extends TestCase
{
    use RefreshDatabase;

    private ?ProductDataProvider $apiProvider = null;

    private function agenticStore(array $overrides = []): Store
    {
        return Store::factory()->create(array_merge([
            'access_mode' => AccessMode::Agentic,
            'agent_urls' => [['url' => 'https://example.com/offers']],
            'agent_max_products' => 12,
            'agent_min_discount_percentage' => 20,
        ], $overrides));
    }

    public function test_minimum_uses_existing_storage_and_is_sent_to_hermes(): void
    {
        Storage::fake('local');
        $store = $this->agenticStore();
        $this->assertContains('example.com', $store->allowedHosts());
        Http::fake(['*' => Http::response(['status' => 'completed', 'target_reached' => true], 200)]);

        $this->artisan('buddy:agent-strategy-run', ['store' => $store->id])->assertSuccessful();

        Http::assertSent(fn ($request) => $request['min_products'] === 12
            && $request['marketplace_strategy'] === 'default'
            // agent_options is cast to (object) before sending (so an empty
            // marketplace profile still serializes as `{}`, not `[]`); the
            // fake keeps that original value rather than round-tripping it
            // through JSON, so compare it back as an array here.
            && (array) $request['agent_options'] === []
            && ! isset($request['max_selected_candidates'])
            && ! isset($request['max_raw_candidates']));
        $reports = Storage::disk('local')->allFiles('hermes/reports');
        $this->assertCount(1, $reports);
        $saved = json_decode(Storage::disk('local')->get($reports[0]), true);
        $this->assertSame($store->id, $saved['store_id']);
    }

    public function test_mercado_livre_agent_profile_is_sent_without_affecting_other_stores(): void
    {
        Storage::fake('local');
        $store = $this->agenticStore([
            'name' => 'Mercado Livre',
            'domains' => [['domain' => 'mercadolivre.com.br']],
            'agent_urls' => [['url' => 'https://www.mercadolivre.com.br/ofertas']],
            'agent_max_products' => 5,
        ]);
        Http::fake(['*' => Http::response(['status' => 'completed', 'target_reached' => true], 200)]);

        $this->artisan('buddy:agent-strategy-run', ['store' => $store->id])->assertSuccessful();

        Http::assertSent(fn ($request) => $request['marketplace_strategy'] === 'mercado_livre'
            && (array) $request['agent_options'] === [
                'locale' => 'pt-BR',
                'timezone' => 'America/Sao_Paulo',
                'headless' => false,
                'native_user_agent' => true,
                'stealth_script' => false,
                'llm_page_segment_chars' => 4000,
                'llm_max_links_per_segment' => 30,
                'listing_image_enrichment' => true,
                'require_image' => true,
            ]);
    }

    public function test_relevance_profile_is_omitted_when_nothing_is_configured(): void
    {
        Storage::fake('local');
        $store = $this->agenticStore();
        $store->tags()->attach(Tag::factory()->create(['relevance_profile' => [
            'instructions' => ' ', 'include_brands' => [], 'exclude_terms' => [''], 'min_price' => null,
        ]]));
        Http::fake(['*' => Http::response(['status' => 'completed', 'target_reached' => true], 200)]);

        $this->artisan('buddy:agent-strategy-run', ['store' => $store->id])->assertSuccessful();

        Http::assertSent(fn ($request) => ! isset($request['relevance_profile']));
    }

    public function test_relevance_profile_contains_only_the_store_niches(): void
    {
        Storage::fake('local');
        $store = $this->agenticStore();
        $phones = Tag::factory()->create(['name' => 'Celulares', 'relevance_profile' => [
            'instructions' => ' Buscar smartphones. ',
            'max_price' => '1500',
            'min_price' => null,
            'include_brands' => [' Samsung ', ''],
            'include_product_types' => ['smartphone'],
            'exclude_terms' => ['capa'],
        ]]);
        $home = Tag::factory()->create(['name' => 'Casa', 'relevance_profile' => null]);
        Tag::factory()->create(['name' => 'Unrelated', 'relevance_profile' => ['include_product_types' => ['tv']]]);
        $store->tags()->attach([$phones->id, $home->id]);
        Http::fake(['*' => Http::response(['status' => 'completed', 'target_reached' => true], 200)]);

        $this->artisan('buddy:agent-strategy-run', ['store' => $store->id])->assertSuccessful();

        Http::assertSent(fn ($request) => json_decode(json_encode($request['relevance_profile']), true) === [
            'niches' => [
                'Celulares' => [
                    'instructions' => 'Buscar smartphones.',
                    'max_price' => 1500, // float 1500.0 before the JSON round-trip
                    'include_brands' => ['Samsung'],
                    'include_product_types' => ['smartphone'],
                    'exclude_terms' => ['capa'],
                ],
            ],
        ]);
    }

    public function test_brand_requirement_is_sent_only_when_the_store_sets_it(): void
    {
        Storage::fake('local');
        $niche = Tag::factory()->create(['relevance_profile' => ['include_brands' => ['Samsung']]]);
        $strict = $this->agenticStore(['settings' => ['discovery_brand_policy' => 'priority']]);
        $default = $this->agenticStore();
        $strict->tags()->attach($niche);
        $default->tags()->attach($niche);
        Http::fake(['*' => Http::response(['status' => 'completed', 'target_reached' => true], 200)]);

        $this->artisan('buddy:agent-strategy-run', ['store' => $strict->id])->assertSuccessful();
        $this->artisan('buddy:agent-strategy-run', ['store' => $default->id])->assertSuccessful();

        Http::assertSent(fn ($request) => $request['store_id'] === $strict->id
            && $request['relevance_profile']['brand_policy'] === 'priority');
        Http::assertSent(fn ($request) => $request['store_id'] === $default->id
            && ! array_key_exists('brand_policy', $request['relevance_profile']));
    }

    public function test_edit_form_saves_the_brand_requirement(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $store = $this->agenticStore();
        $store->tags()->attach(Tag::factory()->create(['user_id' => $user->id]));

        Livewire::test(EditStore::class, ['record' => $store->getRouteKey()])
            ->fillForm([
                'settings.discovery_brand_policy' => 'any',
                'settings.locale_settings.locale' => 'pt_BR',
                'settings.locale_settings.currency' => 'BRL',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('any', $store->fresh()->settings['discovery_brand_policy']);
    }

    /**
     * An Api store (like Shopee) whose provider returns the given candidates on
     * the first fetch and nothing on later rounds — or, with $rounds, one batch
     * per round (like the real search-term rotation).
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  array<int, array<int, array<string, mixed>>>|null  $rounds
     */
    private function apiStoreReturning(array $candidates, array $overrides = [], ?array $rounds = null): Store
    {
        $provider = new class($rounds ?? [$candidates]) implements ProductDataProvider
        {
            /** @var list<array<string, mixed>> */
            public array $fetchedTags = [];

            public function __construct(private array $rounds) {}

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
                return true;
            }

            public function fetch(Store $store, ProductDataOperation $operation, array $context): array
            {
                $this->fetchedTags[] = $context['tags'];

                return array_shift($this->rounds) ?? [];
            }

            public static function credentialFields(): array
            {
                return [];
            }
        };
        $this->app->instance(ApiProviderRegistry::class, new ApiProviderRegistry([$provider]));
        $this->apiProvider = $provider;
        config(['services.pricebuddy.api_token' => 'test-token', 'services.pricebuddy.api_base_url' => 'http://app/api']);

        $store = Store::factory()->create(array_merge([
            'access_mode' => AccessMode::Api,
            'marketplace_id' => 'fake_api',
            // High enough that the target never cuts ingestion short in these tests.
            'agent_max_products' => 10,
        ], $overrides));
        $store->tags()->attach([
            Tag::factory()->create(['name' => 'Beleza'])->id,
            Tag::factory()->create(['name' => 'Eletrônicos', 'relevance_profile' => ['include_product_types' => ['smartphone']]])->id,
        ]);

        return $store;
    }

    private function apiCandidate(string $slug, string $title, string $tag): array
    {
        return ['url' => "https://shop.example/{$slug}", 'title' => $title, 'price' => 50, 'store_id' => 1, 'tags' => [$tag]];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $decisions  null makes /evaluate fail
     */
    private function fakeDiscoveryEndpoints(array $existingSlugs, ?array $decisions): void
    {
        Http::fake(function (HttpRequest $request) use ($existingSlugs, $decisions) {
            if (str_ends_with($request->url(), '/discovery/candidates/check')) {
                return Http::response(['results' => array_map(fn (string $url): array => [
                    'url' => $url,
                    'key' => 'key:'.basename($url),
                    'exists' => in_array(basename($url), $existingSlugs, true),
                    'has_image' => false,
                ], $request['urls'])]);
            }
            if (str_ends_with($request->url(), '/evaluate')) {
                return $decisions === null
                    ? Http::response(['status' => 'error'], 500)
                    : Http::response(['enabled' => true, 'decisions' => $decisions]);
            }

            return Http::response(['created' => true], 201);
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function ingestedCandidates(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/discovery/candidates'))
            ->map(fn (HttpRequest $request): array => $request->data())
            ->values()
            ->all();
    }

    public function test_api_discovery_ingests_only_admitted_candidates_under_the_evaluated_niche(): void
    {
        Storage::fake('local');
        $store = $this->apiStoreReturning([
            $this->apiCandidate('phone', 'Smartphone Galaxy A36', 'Eletrônicos'),
            $this->apiCandidate('nail-file', 'Removedor de Cutículas e Lixa Eletrônica', 'Eletrônicos'),
            $this->apiCandidate('bags', 'Kit 100 Saquinhos Autocolantes Eletrônicos', 'Eletrônicos'),
            $this->apiCandidate('known', 'Produto já cadastrado', 'Beleza'),
        ]);
        $this->fakeDiscoveryEndpoints(['known'], [
            ['key' => 'key:phone', 'ingest' => true, 'classification' => 'relevant', 'niche' => 'Eletrônicos', 'confidence' => 0.95, 'reason' => 'smartphone'],
            ['key' => 'key:nail-file', 'ingest' => true, 'classification' => 'relevant', 'niche' => 'Beleza', 'confidence' => 0.9, 'reason' => 'unhas'],
            ['key' => 'key:bags', 'ingest' => false, 'classification' => 'off_niche', 'niche' => null, 'confidence' => 0.95, 'reason' => 'embalagem'],
        ]);

        // Exit code reflects the (unreached) target; this test is about what gets ingested.
        $this->artisan('buddy:agent-strategy-run', ['store' => $store->id])->run();

        $ingested = collect($this->ingestedCandidates())->mapWithKeys(fn (array $c) => [basename($c['url']) => $c['tags']]);
        $this->assertSame(['known' => ['Beleza'], 'phone' => ['Eletrônicos'], 'nail-file' => ['Beleza']], $ingested->all());
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/evaluate')
            && collect($request['candidates'])->pluck('key')->all() === ['key:phone', 'key:nail-file', 'key:bags']
            && array_keys(json_decode(json_encode($request['relevance_profile']), true)['niches']) === ['Eletrônicos']);

        $reports = Storage::disk('local')->allFiles('hermes/reports');
        $saved = json_decode(Storage::disk('local')->get($reports[0]), true);
        $this->assertSame(['relevant' => 2, 'off_niche' => 1], $saved['report']['relevance']['decisions_by_classification']);
        $this->assertCount(3, $saved['report']['relevance']['decisions']);
    }

    public function test_api_discovery_runs_more_rounds_until_the_target_is_reached(): void
    {
        Storage::fake('local');
        $store = $this->apiStoreReturning([], ['agent_max_products' => 3], rounds: [
            [$this->apiCandidate('phone-1', 'Galaxy A15', 'Eletrônicos')],
            [$this->apiCandidate('phone-2', 'Galaxy A25', 'Eletrônicos'), $this->apiCandidate('phone-3', 'Galaxy A35', 'Eletrônicos')],
            [$this->apiCandidate('phone-4', 'never fetched', 'Eletrônicos')],
        ]);
        Tag::query()->update(['relevance_profile' => null]);
        $this->fakeDiscoveryEndpoints([], []);

        $this->artisan('buddy:agent-strategy-run', ['store' => $store->id])->assertSuccessful();

        $this->assertCount(3, $this->ingestedCandidates());
        $this->assertCount(2, $this->apiProvider->fetchedTags);
        $saved = json_decode(Storage::disk('local')->get(Storage::disk('local')->allFiles('hermes/reports')[0]), true);
        $this->assertSame([1, 2], array_column($saved['report']['rounds'], 'created'));
    }

    public function test_api_discovery_rounds_keep_room_for_a_niche_below_its_floor(): void
    {
        Storage::fake('local');
        $beauty = array_map(fn (int $i) => $this->apiCandidate("beauty-{$i}", "Perfume {$i}", 'Beleza'), range(1, 6));
        $store = $this->apiStoreReturning([], ['agent_max_products' => 4, 'discovery_min_percentage_per_tag' => 50], rounds: [
            [...$beauty, $this->apiCandidate('phone-1', 'Galaxy A15', 'Eletrônicos')],
            [$this->apiCandidate('phone-2', 'Galaxy A25', 'Eletrônicos')],
        ]);
        Tag::query()->update(['relevance_profile' => null]);
        $this->fakeDiscoveryEndpoints([], []);

        $this->artisan('buddy:agent-strategy-run', ['store' => $store->id])->assertSuccessful();

        $ingested = collect($this->ingestedCandidates())->groupBy(fn (array $c) => $c['tags'][0])->map->count()->all();
        $this->assertSame(['Beleza' => 2, 'Eletrônicos' => 2], $ingested);
        // Round 2 searched only the niche still below its floor.
        $this->assertSame(['Eletrônicos'], $this->apiProvider->fetchedTags[1]);
    }

    public function test_api_discovery_without_profile_does_not_call_the_evaluator(): void
    {
        Storage::fake('local');
        $store = $this->apiStoreReturning([$this->apiCandidate('phone', 'Smartphone Galaxy A36', 'Eletrônicos')]);
        Tag::query()->update(['relevance_profile' => null]);
        $this->fakeDiscoveryEndpoints([], []);

        $this->artisan('buddy:agent-strategy-run', ['store' => $store->id]);

        Http::assertNotSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/evaluate')
            || str_ends_with($request->url(), '/discovery/candidates/check'));
        $this->assertCount(1, $this->ingestedCandidates());
    }

    public function test_api_discovery_fails_closed_for_new_products_when_evaluation_is_unavailable(): void
    {
        Storage::fake('local');
        $store = $this->apiStoreReturning([
            $this->apiCandidate('phone', 'Smartphone Galaxy A36', 'Eletrônicos'),
            $this->apiCandidate('known', 'Produto já cadastrado', 'Beleza'),
        ]);
        $this->fakeDiscoveryEndpoints(['known'], null);

        $this->artisan('buddy:agent-strategy-run', ['store' => $store->id]);

        $this->assertSame(['https://shop.example/known'], collect($this->ingestedCandidates())->pluck('url')->all());
        $saved = json_decode(Storage::disk('local')->get(Storage::disk('local')->allFiles('hermes/reports')[0]), true);
        $this->assertStringContainsString('relevance evaluation failed', $saved['report']['relevance']['error']);
    }

    public function test_edit_form_preserves_and_updates_an_existing_minimum(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $store = $this->agenticStore(['agent_max_products' => 17]);
        $tag = Tag::factory()->create(['user_id' => $user->id]);
        $store->tags()->attach($tag);

        Livewire::test(EditStore::class, ['record' => $store->getRouteKey()])
            ->assertFormSet(['agent_max_products' => 17])
            ->fillForm([
                'agent_max_products' => 23,
                'settings.locale_settings.locale' => 'pt_BR',
                'settings.locale_settings.currency' => 'BRL',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(23, $store->fresh()->agent_max_products);
    }

    public function test_incomplete_run_is_saved_and_returns_failure(): void
    {
        Storage::fake('local');
        $store = $this->agenticStore(['agent_max_products' => 7]);
        Http::fake(['*' => Http::response([
            'status' => 'incomplete', 'target_reached' => false,
            'abort_reason' => 'Run timeout reached',
        ], 200)]);

        $this->artisan('buddy:agent-strategy-run', ['store' => $store->id])->assertFailed();
        $this->assertCount(1, Storage::disk('local')->allFiles('hermes/reports'));
    }

    public function test_all_runs_every_store_even_after_incomplete(): void
    {
        Storage::fake('local');
        $first = $this->agenticStore(['name' => 'First store', 'agent_max_products' => 5]);
        $second = $this->agenticStore(['name' => 'Second store', 'agent_max_products' => 5]);

        Http::fake([
            '*' => Http::sequence()
                ->push(['status' => 'incomplete', 'target_reached' => false, 'abort_reason' => 'sources exhausted', 'total_candidates' => 0], 200)
                ->push(['status' => 'completed', 'target_reached' => true, 'total_candidates' => 5, 'min_products' => 5], 200),
        ]);

        $this->artisan('buddy:agent-strategy-run', ['--all' => true])
            ->assertFailed()
            ->expectsOutputToContain('First store: incomplete')
            ->expectsOutputToContain('Second store: completed')
            ->expectsOutputToContain('Total: 2 stores, 1 failed.');

        $this->assertCount(2, Storage::disk('local')->allFiles('hermes/reports'));
    }

    public function test_all_reports_summary_with_both_statuses(): void
    {
        Storage::fake('local');
        $this->agenticStore([
            'name' => 'Amazon store',
            'agent_urls' => [['url' => 'https://amazon.com/offers']],
            'agent_max_products' => 10,
        ]);
        $this->agenticStore([
            'name' => 'Mercado Livre store',
            'agent_urls' => [['url' => 'https://mercadolivre.com/offers']],
            'agent_max_products' => 10,
        ]);

        Http::fake([
            '*' => Http::sequence()
                ->push(['status' => 'incomplete', 'target_reached' => false, 'abort_reason' => 'sources exhausted', 'total_candidates' => 4, 'min_products' => 10], 200)
                ->push(['status' => 'completed', 'target_reached' => true, 'total_candidates' => 10, 'min_products' => 10], 200),
        ]);

        $this->artisan('buddy:agent-strategy-run', ['--all' => true])
            ->assertFailed()
            ->expectsOutputToContain('Amazon store: incomplete')
            ->expectsOutputToContain('Mercado Livre store: completed')
            ->expectsOutputToContain('Total: 2 stores, 1 failed.');
    }
}

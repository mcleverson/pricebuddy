<?php

namespace Tests\Feature\Console;

use App\Enums\AccessMode;
use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Models\Store;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class RunAgentStrategyTest extends TestCase
{
    use RefreshDatabase;

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
            && $request['agent_options'] === []
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
            && $request['agent_options'] === [
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

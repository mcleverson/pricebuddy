<?php

namespace Tests\Feature\Carousels;

use App\Enums\AccessMode;
use App\Filament\Pages\AppSettingsPage;
use App\Filament\Resources\CarouselResource;
use App\Filament\Resources\CarouselResource\Pages\ListCarousels;
use App\Filament\Resources\CarouselResource\Pages\ViewCarousel;
use App\Jobs\DiscoverCarouselProductsJob;
use App\Models\Carousel;
use App\Models\CarouselCandidate;
use App\Models\Store;
use App\Models\Tag;
use App\Models\User;
use App\Services\Carousels\CarouselSettings;
use App\Services\Helpers\SettingsHelper;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;

class CarouselPagesTest extends TestCase
{
    use RefreshDatabase;

    private function freshSettings(): void
    {
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
        app()->forgetInstance(AppSettings::class);
    }

    private function carousel(User $user, string $niche = 'Cantinho do café', string $status = Carousel::STATUS_REVIEW): Carousel
    {
        $carousel = Carousel::create(['user_id' => $user->id, 'niche' => $niche, 'status' => $status]);
        $carousel->candidates()->create([
            'store_id' => Store::factory()->create()->id, 'source' => CarouselCandidate::SOURCE_API,
            'url' => 'https://example.com/'.md5($niche), 'url_normalized' => 'example.com/'.md5($niche),
            'title' => 'Produto '.$niche, 'image' => 'https://example.com/'.md5($niche).'.jpg', 'price' => 10,
            'status' => CarouselCandidate::STATUS_SCORED, 'position' => 1,
        ]);

        return $carousel;
    }

    public function test_guests_cannot_open_carousels(): void
    {
        $this->get(CarouselResource::getUrl())->assertRedirect();
    }

    public function test_the_list_shows_only_the_users_carousels_in_a_grid(): void
    {
        $user = User::factory()->create();
        $mine = $this->carousel($user, 'Cantinho do café');
        $theirs = $this->carousel(User::factory()->create(), 'Privado');
        $this->actingAs($user);

        Livewire::test(ListCarousels::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_a_new_carousel_starts_discovery_in_the_background(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(ListCarousels::class)
            ->callAction('newCarousel', ['niche' => '  Luminárias criativas '])
            ->assertHasNoActionErrors();

        $carousel = Carousel::sole();
        $this->assertSame($user->id, $carousel->user_id);
        $this->assertSame('Luminárias criativas', $carousel->niche);
        $this->assertSame(Carousel::STATUS_DISCOVERING, $carousel->status);
        $this->assertSame('pt-BR', $carousel->language);
        Queue::assertPushedOn(DiscoverCarouselProductsJob::QUEUE, DiscoverCarouselProductsJob::class,
            fn (DiscoverCarouselProductsJob $job): bool => $job->carouselId === $carousel->id);
    }

    public function test_suggestions_come_from_settings_and_tags_without_pinterest(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Tag::factory()->create(['name' => 'Beleza', 'user_id' => $user->id]);
        Tag::factory()->create(['name' => 'Someone else', 'user_id' => User::factory()->create()->id]);

        $this->assertSame([...CarouselSettings::DEFAULT_NICHES, 'Beleza'], ListCarousels::nicheSuggestions());
    }

    public function test_custom_niches_can_be_disabled(): void
    {
        Queue::fake();
        SettingsHelper::setSetting('carousel_settings', ['custom_niche_enabled' => false, 'suggested_niches' => ['Cantinho do café']]);
        $this->freshSettings();
        $this->actingAs(User::factory()->create());

        Livewire::test(ListCarousels::class)
            ->callAction('newCarousel', ['niche' => 'Qualquer coisa'])
            ->assertHasActionErrors(['niche']);
        Livewire::test(ListCarousels::class)
            ->callAction('newCarousel', ['niche' => 'Cantinho do café'])
            ->assertHasNoActionErrors();

        $this->assertSame(['Cantinho do café'], Carousel::pluck('niche')->all());
    }

    public function test_users_cannot_open_another_users_carousel(): void
    {
        $carousel = $this->carousel(User::factory()->create());
        $this->actingAs(User::factory()->create());

        $this->get(CarouselResource::getUrl('view', ['record' => $carousel]))->assertNotFound();
    }

    public function test_the_carousel_page_changes_the_selection(): void
    {
        $user = User::factory()->create();
        $carousel = $this->carousel($user);
        $candidate = $carousel->candidates()->sole();
        $this->actingAs($user);

        Livewire::test(ViewCarousel::class, ['record' => $carousel->id])
            ->call('unselect', $candidate->id)
            ->assertSee('Produto Cantinho do café');

        $this->assertNull($candidate->fresh()->position);
    }

    public function test_duplicate_and_delete_from_the_list(): void
    {
        $user = User::factory()->create();
        $carousel = $this->carousel($user, 'Cantinho do café', Carousel::STATUS_APPROVED);
        $this->actingAs($user);

        Livewire::test(ListCarousels::class)->callTableAction('duplicate', $carousel);
        $copy = Carousel::whereKeyNot($carousel->id)->sole();
        $this->assertSame(Carousel::STATUS_REVIEW, $copy->status);

        Livewire::test(ListCarousels::class)->callTableAction('delete', $carousel);
        $this->assertModelMissing($carousel);
        $this->assertModelExists($copy);
    }

    public function test_a_busy_carousel_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $carousel = $this->carousel($user, 'Em busca', Carousel::STATUS_DISCOVERING);
        $this->actingAs($user);

        Livewire::test(ListCarousels::class)->assertTableActionHidden('delete', $carousel);
    }

    public function test_settings_has_a_carousels_section_that_is_saved(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $store = Store::factory()->create(['access_mode' => AccessMode::Agentic, 'agent_urls' => [['url' => 'https://www.amazon.com.br/deals']]]);

        Livewire::test(AppSettingsPage::class)
            ->assertSee('Carousels')
            ->fillForm([
                'carousel_settings.candidates_per_store' => 6,
                'carousel_settings.store_ids' => [(string) $store->id],
                'carousel_settings.suggested_niches' => ['Cantinho do café'],
                'carousel_settings.min_products' => 3,
                'carousel_settings.max_products' => 8,
            ])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->freshSettings();

        $this->assertSame(6, CarouselSettings::get('candidates_per_store'));
        $this->assertSame(['Cantinho do café'], CarouselSettings::get('suggested_niches'));
        $this->assertSame([3, 8], CarouselSettings::productRange());
        $this->assertSame(25.0, CarouselSettings::get('weights')['visual_appeal']);
    }

    public function test_scoring_weights_must_add_up_to_100(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(AppSettingsPage::class)
            ->fillForm(['carousel_settings.weights.visual_appeal' => 50])
            ->call('save')
            ->assertHasFormErrors(['carousel_settings.weights.visual_appeal']);
    }
}

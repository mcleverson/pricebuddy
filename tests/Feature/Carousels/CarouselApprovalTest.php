<?php

namespace Tests\Feature\Carousels;

use App\Models\Carousel;
use App\Models\CarouselCandidate;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Carousels\CarouselApproval;
use App\Services\Helpers\SettingsHelper;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CarouselApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Carousel $carousel;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->store = Store::factory()->create();
        $this->carousel = Carousel::create(['user_id' => $this->user->id, 'niche' => 'Cantinho do café', 'status' => Carousel::STATUS_REVIEW]);
    }

    private function candidate(string $title, array $overrides = []): CarouselCandidate
    {
        return $this->carousel->candidates()->create(array_merge([
            'store_id' => $this->store->id,
            'source' => CarouselCandidate::SOURCE_HERMES,
            'url' => 'https://example.com/p/'.md5($title),
            'url_normalized' => 'example.com/p/'.md5($title),
            'title' => $title,
            'image' => 'https://example.com/'.md5($title).'.jpg',
            'price' => 59.9,
            'status' => CarouselCandidate::STATUS_SCORED,
        ], $overrides));
    }

    /**
     * @return list<CarouselCandidate>
     */
    private function selectFive(): array
    {
        $approval = app(CarouselApproval::class);
        $candidates = [];
        foreach (['Caneca térmica', 'Moedor manual', 'Balança com timer', 'Prensa francesa', 'Porta-cápsulas giratório'] as $title) {
            $candidates[] = $candidate = $this->candidate($title);
            $approval->select($candidate);
        }

        return $candidates;
    }

    private function assertRefused(callable $change, string $message): void
    {
        try {
            $change();
            $this->fail('Expected the change to be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function test_selection_is_capped_at_the_configured_maximum(): void
    {
        SettingsHelper::setSetting('carousel_settings', ['max_products' => 5]);
        SettingsHelper::$settings = null;
        app()->forgetInstance(AppSettings::class);
        $this->selectFive();

        $this->assertRefused(fn () => app(CarouselApproval::class)->select($this->candidate('Filtro de pano')), 'already has 5 products');
        $this->assertSame([1, 2, 3, 4, 5], $this->carousel->selection()->pluck('position')->all());
    }

    public function test_nearly_identical_and_rejected_products_cannot_be_selected(): void
    {
        $approval = app(CarouselApproval::class);
        $approval->select($this->candidate('Caneca térmica de inox 500ml'));
        $rejected = $this->candidate('Moedor manual');
        $approval->reject($rejected);

        $this->assertRefused(fn () => $approval->select($this->candidate('Caneca Térmica de Inox 500 ml')), 'nearly identical');
        $this->assertRefused(fn () => $approval->select($rejected->fresh()), 'Restore this product');
    }

    public function test_removing_and_moving_keep_the_slides_in_order(): void
    {
        [$first, $second, $third] = $this->selectFive();
        $approval = app(CarouselApproval::class);

        $approval->unselect($second->fresh());
        $approval->move($first->fresh(), 1);

        $this->assertSame(
            ['Balança com timer', 'Caneca térmica', 'Prensa francesa', 'Porta-cápsulas giratório'],
            $this->carousel->selection()->pluck('title')->all(),
        );
        $this->assertSame([1, 2, 3, 4], $this->carousel->selection()->pluck('position')->all());
        $this->assertSame(1, $third->fresh()->position);
    }

    public function test_approval_requires_a_count_within_the_range(): void
    {
        $this->candidate('Caneca térmica');
        app(CarouselApproval::class)->select($this->carousel->candidates()->first());

        $this->assertRefused(fn () => app(CarouselApproval::class)->approve($this->carousel), 'Select between 3 and 10');
        $this->assertSame(Carousel::STATUS_REVIEW, $this->carousel->fresh()->status);
        $this->assertSame(0, Product::count());
    }

    public function test_approval_adds_the_selection_to_the_catalog_and_freezes_it(): void
    {
        $candidates = $this->selectFive();
        $candidates[0]->update(['affiliate_url' => 'https://s.example.com/aff']);

        app(CarouselApproval::class)->approve($this->carousel);

        $carousel = $this->carousel->fresh();
        $this->assertSame(Carousel::STATUS_APPROVED, $carousel->status);
        $this->assertNotNull($carousel->approved_at);
        $this->assertSame(5, Product::where('user_id', $this->user->id)->count());
        $this->assertSame(5, $carousel->selection()->whereNotNull('product_id')->count());
        $this->assertSame('https://s.example.com/aff', $candidates[0]->fresh()->product->urls()->sole()->affiliate_url);
        $this->assertRefused(fn () => app(CarouselApproval::class)->unselect($candidates[1]->fresh()), 'can no longer be changed');
    }

    public function test_three_products_can_be_approved(): void
    {
        $approval = app(CarouselApproval::class);
        foreach (['Caneca', 'Moedor', 'Balança'] as $title) {
            $approval->select($this->candidate($title));
        }

        $approval->approve($this->carousel);

        $this->assertSame(Carousel::STATUS_APPROVED, $this->carousel->fresh()->status);
        $this->assertSame(3, Product::count());
    }

    public function test_duplicate_reuses_the_products_in_a_new_carousel(): void
    {
        $this->selectFive();
        $this->candidate('Fora da seleção');
        app(CarouselApproval::class)->approve($this->carousel);

        $copy = app(CarouselApproval::class)->duplicate($this->carousel->fresh());

        $this->assertSame(Carousel::STATUS_REVIEW, $copy->status);
        $this->assertSame($this->user->id, $copy->user_id);
        $this->assertNull($copy->approved_at);
        $this->assertSame(6, $copy->candidates()->count());
        $this->assertSame(
            $this->carousel->selection()->pluck('title')->all(),
            $copy->selection()->pluck('title')->all(),
        );
        $this->assertSame(0, $copy->candidates()->whereNotNull('product_id')->count());
        // The original keeps its approval and selection.
        $this->assertSame(Carousel::STATUS_APPROVED, $this->carousel->fresh()->status);

        // The copy can be changed and approved again without duplicating catalog products.
        $approval = app(CarouselApproval::class);
        $approval->unselect($copy->selection()->first());
        $approval->approve($copy->fresh());
        $this->assertSame(5, Product::count());
    }

    public function test_deleting_keeps_catalog_products(): void
    {
        $this->selectFive();
        app(CarouselApproval::class)->approve($this->carousel);

        $this->carousel->fresh()->delete();

        $this->assertSame(0, CarouselCandidate::count());
        $this->assertSame(5, Product::count());
    }
}

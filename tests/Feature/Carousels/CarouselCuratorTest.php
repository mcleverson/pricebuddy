<?php

namespace Tests\Feature\Carousels;

use App\Exceptions\AiProviderException;
use App\Models\Carousel;
use App\Models\CarouselCandidate;
use App\Models\Store;
use App\Models\User;
use App\Services\AiService;
use App\Services\Carousels\CarouselCurator;
use App\Services\Carousels\CarouselSettings;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Tests\TestCase;

class CarouselCuratorTest extends TestCase
{
    use RefreshDatabase;

    private Carousel $carousel;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create();
        $this->carousel = Carousel::create([
            'user_id' => User::factory()->create()->id,
            'niche' => 'Cantinho do café',
            'status' => Carousel::STATUS_DISCOVERING,
        ]);
    }

    private function enableAi(): void
    {
        SettingsHelper::$settings = null;
        SettingsHelper::setSetting('integrated_services', ['ai' => [
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [['id' => 'p1', 'name' => 'Local', 'type' => 'ollama', 'base_url' => 'http://ai.example:11434', 'model' => 'm']],
        ]]);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    private function candidate(string $title, array $overrides = []): CarouselCandidate
    {
        return $this->carousel->candidates()->create(array_merge([
            'store_id' => $this->store->id,
            'source' => CarouselCandidate::SOURCE_API,
            'url' => 'https://example.com/'.md5($title),
            'url_normalized' => 'example.com/'.md5($title),
            'title' => $title,
            'price' => 50,
            'status' => CarouselCandidate::STATUS_PENDING,
        ], $overrides));
    }

    private function answer(int $id, int $score, string $type): array
    {
        return [
            'id' => $id, 'visual_appeal' => $score, 'originality' => $score, 'niche_relevance' => $score,
            'usefulness' => $score, 'commercial_potential' => $score, 'product_type' => $type,
            'reasons' => ['Distinctive design'], 'description' => 'Uma descrição curta.',
        ];
    }

    public function test_weighted_score_spreads_the_unscored_image_weight(): void
    {
        $scores = ['visual_appeal' => 80, 'originality' => 60, 'niche_relevance' => 100, 'usefulness' => 40, 'image_quality' => null, 'commercial_potential' => 50];

        // (80*25 + 60*20 + 100*20 + 40*15 + 50*10) / 90
        $this->assertSame(70.0, CarouselCurator::weightedScore($scores, CarouselSettings::DEFAULT_WEIGHTS));
        $this->assertNull(CarouselCurator::weightedScore(['image_quality' => null], CarouselSettings::DEFAULT_WEIGHTS));
    }

    public function test_candidates_are_scored_from_text_only(): void
    {
        $this->enableAi();
        $mug = $this->candidate('Caneca térmica de cerâmica');
        $prompt = null;
        $this->mock(AiService::class, function ($mock) use ($mug, &$prompt) {
            $mock->shouldReceive('structured')->once()->andReturnUsing(function ($instructions, $schema, $userPrompt) use ($mug, &$prompt) {
                $prompt = json_decode($userPrompt, true);

                return ['products' => [$this->answer($mug->id, 90, 'mug')]];
            });
        });

        $this->assertNull(app(CarouselCurator::class)->score($this->carousel));

        $mug->refresh();
        $this->assertSame(CarouselCandidate::STATUS_SCORED, $mug->status);
        $this->assertSame('90.00', $mug->score);
        $this->assertNull($mug->scores['image_quality']);
        $this->assertSame('mug', $mug->product_type);
        $this->assertSame(['Distinctive design'], $mug->reasons);
        $this->assertSame('Cantinho do café', $prompt['niche']);
        $this->assertSame(['id', 'title', 'store', 'price'], array_keys($prompt['products'][0]));
    }

    public function test_candidates_stay_selectable_when_the_provider_fails(): void
    {
        $this->enableAi();
        $candidate = $this->candidate('Prensa francesa');
        $this->mock(AiService::class, fn ($mock) => $mock->shouldReceive('structured')->andThrow(new AiProviderException('down')));

        $problem = app(CarouselCurator::class)->score($this->carousel);

        $this->assertStringContainsString('could not be scored', $problem);
        $this->assertSame(CarouselCandidate::STATUS_UNSCORED, $candidate->fresh()->status);
    }

    public function test_without_an_ai_provider_nothing_is_scored(): void
    {
        $candidate = $this->candidate('Prensa francesa');

        $problem = app(CarouselCurator::class)->score($this->carousel);

        $this->assertStringContainsString('No AI provider', $problem);
        $this->assertSame(CarouselCandidate::STATUS_UNSCORED, $candidate->fresh()->status);
    }

    public function test_suggested_selection_prefers_different_kinds_of_product(): void
    {
        $scored = fn (string $title, float $score, string $type) => $this->candidate($title, [
            'status' => CarouselCandidate::STATUS_SCORED, 'score' => $score, 'product_type' => $type,
        ]);
        $scored('Caneca A', 95, 'mug');
        $scored('Caneca B', 94, 'mug');
        $scored('Caneca C', 93, 'mug');
        $scored('Moedor', 80, 'grinder');
        $scored('Balança', 75, 'scale');
        $scored('Prensa', 70, 'french press');
        $scored('Porta-cápsulas', 40, 'capsule holder');

        app(CarouselCurator::class)->suggestSelection($this->carousel);

        // One mug first, then one of each other kind; the second mug only fills
        // the last slot, and the low score (below 60) is never suggested.
        $this->assertSame(
            ['Caneca A', 'Moedor', 'Balança', 'Prensa', 'Caneca B'],
            $this->carousel->selection()->pluck('title')->all(),
        );
    }
}

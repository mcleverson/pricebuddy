<?php

namespace App\Services\Carousels;

use App\Enums\AiFeature;
use App\Exceptions\AiProviderException;
use App\Models\Carousel;
use App\Models\CarouselCandidate;
use App\Services\AiService;
use App\Services\Helpers\IntegrationHelper;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Editorial scoring of carousel candidates and the suggested selection.
 *
 * The model only receives text (title, store, price, rating and sales when
 * the marketplace shows them): it never sees the
 * image, so image_quality is left unscored and its weight is spread over the
 * other criteria. Eligibility (valid URL, price, image) was already checked
 * at discovery and is never part of the score.
 */
class CarouselCurator
{
    /** Candidates per LLM call, to keep each answer small. */
    public const BATCH_SIZE = 8;

    /** Criteria the model scores from text; image_quality would need the image. */
    public const TEXT_CRITERIA = ['visual_appeal', 'originality', 'niche_relevance', 'usefulness', 'commercial_potential'];

    public const INSTRUCTIONS = <<<'TEXT'
        You curate products for an Instagram carousel about one niche. The carousel shows products that sell well
        but that people would not come across or think of when casually browsing the niche: genuinely useful,
        clever or surprising products worth sharing, that make people ask for the link. Discounts do not matter.
        Ordinary items that everyone already has or finds first (generic chargers, phone holders, LED strips,
        basic cables) score low on originality even when they sell well.
        You only see each product's title, store, price and, when the marketplace shows them, its star rating and
        sales count; never its image. Base every judgement on that; never invent materials, features, availability
        or exclusivity. Score each criterion from 0 to 100:
        originality (how unexpected it is for this niche: a shopper browsing the niche would not think of it, yet
        it makes sense once seen), usefulness (a concrete everyday problem it solves),
        commercial_potential (proven demand: a high sales count and a good rating raise it; without them, estimate
        conservatively how likely people are to buy it after seeing it in a post, including as a gift),
        niche_relevance (how well it fits the niche), visual_appeal (how well it shows in a photo, from what the
        title says about it).
        product_type: the broad kind of product in one or two lowercase words, in the requested language, used to
        avoid repeating the same kind of product. Use the same words for every product of that kind, ignoring
        style, material, size and brand: every sideboard, buffet or cabinet is the same kind; every mug or cup is
        the same kind. reasons: up to 3 short phrases explaining why it is interesting.
        description: one short, factual sentence for the slide, written in the requested language, using only the title.
        TEXT;

    /**
     * Score every pending candidate. Candidates the provider could not score
     * stay selectable by hand, marked unscored.
     *
     * @return ?string a problem worth showing to the user, or null
     */
    public function score(Carousel $carousel): ?string
    {
        $pending = $carousel->candidates()->where('status', CarouselCandidate::STATUS_PENDING)->with('store')->get();
        if ($pending->isEmpty()) {
            return null;
        }

        $provider = IntegrationHelper::resolveFeatureProvider(AiFeature::CarouselCuration);
        if ($provider === null) {
            $this->markUnscored($pending);

            return 'No AI provider is enabled for Carousel curation (Settings > AI), so candidates were not scored.';
        }

        $weights = (array) CarouselSettings::get('weights');
        $failed = 0;

        foreach ($pending->chunk(self::BATCH_SIZE) as $batch) {
            try {
                $result = AiService::new()->structured(
                    self::INSTRUCTIONS,
                    fn (JsonSchema $schema): array => [
                        'products' => $schema->array()->items($schema->object([
                            'id' => $schema->integer()->required(),
                            ...collect(self::TEXT_CRITERIA)->mapWithKeys(fn (string $criterion): array => [
                                $criterion => $schema->integer()->min(0)->max(100)->required(),
                            ])->all(),
                            'product_type' => $schema->string()->required(),
                            'reasons' => $schema->array()->items($schema->string())->max(3)->required(),
                            'description' => $schema->string()->required(),
                        ]))->required(),
                    ],
                    $this->prompt($carousel, $batch),
                    $provider,
                );
            } catch (AiProviderException) {
                $result = null;
            }

            $answers = collect((array) ($result['products'] ?? []))->filter(fn (mixed $product): bool => is_array($product))->keyBy('id');
            foreach ($batch as $candidate) {
                $answer = $answers->get($candidate->getKey());
                if ($answer === null) {
                    $candidate->update(['status' => CarouselCandidate::STATUS_UNSCORED]);
                    $failed++;

                    continue;
                }
                $this->applyAnswer($candidate, $answer, $weights);
            }
        }

        return $failed > 0 ? "{$failed} candidate(s) could not be scored by the AI provider." : null;
    }

    /**
     * Pre-select the best scored candidates, preferring a different kind of
     * product for each slide so the collection is coherent but not repetitive.
     * The selection is only a suggestion; the user approves it.
     */
    public function suggestSelection(Carousel $carousel): void
    {
        $minScore = (float) CarouselSettings::get('min_score');
        $count = CarouselSettings::suggestedProducts();
        $ranked = $carousel->candidates()
            ->where('status', CarouselCandidate::STATUS_SCORED)
            ->where('score', '>=', $minScore)
            ->orderByDesc('score')
            ->get();

        $picked = collect();
        $types = [];
        // First pass: one product per kind; second pass fills what is left.
        foreach ([true, false] as $distinctTypes) {
            foreach ($ranked as $candidate) {
                if ($picked->count() >= $count) {
                    break 2;
                }
                $type = mb_strtolower(trim((string) $candidate->product_type));
                if ($picked->contains($candidate) || ($distinctTypes && $type !== '' && isset($types[$type]))) {
                    continue;
                }
                $picked->push($candidate);
                $types[$type] = true;
            }
        }

        $carousel->candidates()->update(['position' => null]);
        $picked->values()->each(fn (CarouselCandidate $candidate, int $index) => $candidate->update(['position' => $index + 1]));
    }

    /**
     * Weighted score over the criteria that were actually scored.
     *
     * @param  array<string, int|null>  $scores
     * @param  array<string, float|int>  $weights
     */
    public static function weightedScore(array $scores, array $weights): ?float
    {
        $total = 0.0;
        $weightSum = 0.0;
        foreach ($weights as $criterion => $weight) {
            if (! isset($scores[$criterion]) || $weight <= 0) {
                continue;
            }
            $total += $scores[$criterion] * $weight;
            $weightSum += $weight;
        }

        return $weightSum > 0 ? round($total / $weightSum, 2) : null;
    }

    /**
     * @param  Collection<int, CarouselCandidate>  $batch
     */
    protected function prompt(Carousel $carousel, Collection $batch): string
    {
        return json_encode([
            'niche' => $carousel->niche,
            'language' => $carousel->language ?? 'pt-BR',
            'products' => $batch->map(fn (CarouselCandidate $candidate): array => [
                'id' => $candidate->getKey(),
                'title' => $candidate->title,
                'store' => $candidate->store?->name,
                'price' => $candidate->price !== null ? (float) $candidate->price : null,
                'rating' => $candidate->rating !== null ? (float) $candidate->rating : null,
                'sales_count' => $candidate->sales_count,
            ])->values()->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  array<string, float|int>  $weights
     */
    protected function applyAnswer(CarouselCandidate $candidate, array $answer, array $weights): void
    {
        $scores = ['image_quality' => null];
        foreach (self::TEXT_CRITERIA as $criterion) {
            $value = $answer[$criterion] ?? null;
            $scores[$criterion] = is_numeric($value) ? max(0, min(100, (int) $value)) : null;
        }

        $candidate->update([
            'scores' => $scores,
            'score' => self::weightedScore($scores, $weights),
            'product_type' => Str::limit(trim((string) ($answer['product_type'] ?? '')), 120, '') ?: null,
            'reasons' => collect((array) ($answer['reasons'] ?? []))
                ->filter(fn (mixed $reason): bool => is_string($reason) && trim($reason) !== '')
                ->map(fn (string $reason): string => Str::limit(trim($reason), 160))
                ->take(3)->values()->all(),
            'description' => Str::limit(trim((string) ($answer['description'] ?? '')), 300) ?: null,
            'status' => CarouselCandidate::STATUS_SCORED,
        ]);
    }

    /**
     * @param  Collection<int, CarouselCandidate>  $candidates
     */
    protected function markUnscored(Collection $candidates): void
    {
        CarouselCandidate::query()->whereKey($candidates->modelKeys())->update(['status' => CarouselCandidate::STATUS_UNSCORED]);
    }
}

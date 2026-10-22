<?php

namespace App\Services\Carousels;

use App\Actions\IngestProductCandidateAction;
use App\Models\Carousel;
use App\Models\CarouselCandidate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The user's choices on a carousel: which candidates make the final slides,
 * in which order, and the explicit approval. Approval is mandatory before
 * the Canva AI prompt is used, and it is the only step that writes to the catalog.
 */
class CarouselApproval
{
    public function __construct(protected IngestProductCandidateAction $ingest) {}

    public function select(CarouselCandidate $candidate): void
    {
        $carousel = $this->editable($candidate->carousel);
        $selection = $carousel->selection()->get();

        if ($candidate->position !== null) {
            return;
        }
        if ($candidate->status === CarouselCandidate::STATUS_REJECTED) {
            $this->fail('Restore this product before selecting it.');
        }
        [, $max] = CarouselSettings::productRange();
        if ($selection->count() >= $max) {
            $this->fail("The carousel already has {$max} products, the most allowed. Remove one first.");
        }

        $comparable = CarouselDiscovery::comparableTitle($candidate->title);
        $selectedTitles = $selection->map(fn (CarouselCandidate $selected): string => CarouselDiscovery::comparableTitle($selected->title))->all();
        if (CarouselDiscovery::isNearDuplicate($comparable, $selectedTitles)) {
            $this->fail('A nearly identical product is already selected.');
        }

        $candidate->update(['position' => (int) $selection->max('position') + 1]);
    }

    public function unselect(CarouselCandidate $candidate): void
    {
        $carousel = $this->editable($candidate->carousel);
        $candidate->update(['position' => null]);
        $this->renumber($carousel);
    }

    /** Move a selected product one slide earlier (-1) or later (+1). */
    public function move(CarouselCandidate $candidate, int $direction): void
    {
        $carousel = $this->editable($candidate->carousel);
        if ($candidate->position === null) {
            return;
        }

        $neighbour = $carousel->selection()->where('position', $candidate->position + ($direction < 0 ? -1 : 1))->first();
        if ($neighbour === null) {
            return;
        }

        DB::transaction(function () use ($candidate, $neighbour): void {
            [$from, $to] = [$candidate->position, $neighbour->position];
            $candidate->update(['position' => $to]);
            $neighbour->update(['position' => $from]);
        });
    }

    public function reject(CarouselCandidate $candidate): void
    {
        $carousel = $this->editable($candidate->carousel);
        $candidate->update(['status' => CarouselCandidate::STATUS_REJECTED, 'position' => null]);
        $this->renumber($carousel);
    }

    public function restore(CarouselCandidate $candidate): void
    {
        $this->editable($candidate->carousel);
        $candidate->update(['status' => $candidate->score !== null ? CarouselCandidate::STATUS_SCORED : CarouselCandidate::STATUS_UNSCORED]);
    }

    /**
     * Approve the selection (within the allowed product range) and add it to
     * the catalog (or link it to the products already there), keeping each
     * one's store, affiliate link and price.
     */
    public function approve(Carousel $carousel): void
    {
        $this->editable($carousel);
        $selection = $carousel->selection()->get();

        [$min, $max] = CarouselSettings::productRange();
        if ($selection->count() < $min || $selection->count() > $max) {
            $this->fail("Select between {$min} and {$max} products before approving.");
        }

        DB::transaction(function () use ($carousel, $selection): void {
            foreach ($selection as $candidate) {
                $result = ($this->ingest)([
                    'url' => $candidate->url,
                    'title' => $candidate->title,
                    'price' => (float) $candidate->price,
                    'original_price' => $candidate->original_price !== null ? (float) $candidate->original_price : null,
                    'image' => $candidate->image,
                    'store_id' => $candidate->store_id,
                    'affiliate_url' => $candidate->affiliate_url,
                ]);
                $candidate->update(['product_id' => $result['product']?->getKey()]);
            }

            $carousel->update(['status' => Carousel::STATUS_APPROVED, 'approved_at' => now()]);
        });
    }

    /**
     * A new carousel in review with the same niche, candidates, scores and
     * selection, to build another carousel from the same products. The
     * original keeps its approval.
     */
    public function duplicate(Carousel $carousel): Carousel
    {
        if ($carousel->status === Carousel::STATUS_DISCOVERING) {
            $this->fail('Wait for the search to finish before duplicating.');
        }

        return DB::transaction(function () use ($carousel): Carousel {
            // Re-read so attributes added by the list query (counts) are not copied.
            $copy = Carousel::query()->findOrFail($carousel->getKey())->replicate(['approved_at', 'error']);
            $copy->status = Carousel::STATUS_REVIEW;
            $copy->save();

            foreach ($carousel->candidates()->get() as $candidate) {
                $copy->candidates()->save($candidate->replicate(['carousel_id', 'product_id']));
            }

            return $copy;
        });
    }

    protected function editable(Carousel $carousel): Carousel
    {
        if (! $carousel->isEditable()) {
            $this->fail('This carousel can no longer be changed.');
        }

        return $carousel;
    }

    protected function renumber(Carousel $carousel): void
    {
        $carousel->selection()->get()->values()
            ->each(fn (CarouselCandidate $candidate, int $index) => $candidate->update(['position' => $index + 1]));
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['carousel' => $message]);
    }
}

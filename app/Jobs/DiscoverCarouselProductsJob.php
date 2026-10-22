<?php

namespace App\Jobs;

use App\Models\Carousel;
use App\Services\Carousels\CarouselCurator;
use App\Services\Carousels\CarouselDiscovery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Search the stores, score the candidates and suggest a selection. Runs on
 * its own queue: Hermes searches take many minutes per store and must not
 * hold up price updates on the default queue.
 */
class DiscoverCarouselProductsJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public const QUEUE = 'carousels';

    public $timeout = 3600;

    /** A retried run would repeat every marketplace search; failures are shown instead. */
    public $tries = 1;

    public function __construct(public int $carouselId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(CarouselDiscovery $discovery, CarouselCurator $curator): void
    {
        $carousel = Carousel::find($this->carouselId);
        if ($carousel === null || $carousel->status !== Carousel::STATUS_DISCOVERING) {
            return;
        }

        $discovery->run($carousel);
        $problem = $curator->score($carousel);
        $curator->suggestSelection($carousel);

        $carousel->update([
            'status' => $carousel->candidates()->exists() ? Carousel::STATUS_REVIEW : Carousel::STATUS_FAILED,
            'error' => $carousel->candidates()->exists() ? $problem : 'No store returned a usable product for this niche.',
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Carousel::query()
            ->whereKey($this->carouselId)
            ->where('status', Carousel::STATUS_DISCOVERING)
            ->update([
                'status' => Carousel::STATUS_FAILED,
                'error' => Str::limit($exception?->getMessage() ?? 'Discovery failed.', 500),
            ]);
    }
}

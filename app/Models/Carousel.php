<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One carousel curation run for a niche: candidates found in the marketplaces,
 * scored for editorial appeal, and a manually approved selection.
 *
 * @property int $user_id
 * @property string $niche
 * @property ?string $language
 * @property ?string $region
 * @property string $status
 * @property ?array<int, array<string, mixed>> $store_reports
 * @property ?string $error
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property ?string $profile_id id of a profile in Settings > Carousels
 * @property ?string $headline
 * @property ?string $cta
 */
class Carousel extends Model
{
    public const STATUS_DISCOVERING = 'discovering';

    /** Candidates are ready; the user is choosing the final products. */
    public const STATUS_REVIEW = 'review';

    /** The final selection was approved and ingested; nothing can change it anymore. */
    public const STATUS_APPROVED = 'approved';

    public const STATUS_FAILED = 'failed';

    /** Most products in one carousel; with the cover and closing page it stays well within Instagram's limit. */
    public const MAX_PRODUCT_SLOTS = 10;

    protected $guarded = [];

    /** Discovery runs in the background and must finish first. */
    public function isBusy(): bool
    {
        return $this->status === self::STATUS_DISCOVERING;
    }

    protected function casts(): array
    {
        return [
            'store_reports' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CarouselCandidate, $this>
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(CarouselCandidate::class);
    }

    /**
     * The selected candidates, in slide order.
     *
     * @return HasMany<CarouselCandidate, $this>
     */
    public function selection(): HasMany
    {
        return $this->candidates()->whereNotNull('position')->orderBy('position');
    }

    public function scopeCurrentUser(Builder $query): Builder
    {
        return $query->where('user_id', auth()->id());
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_REVIEW;
    }

    /** The first selected (or found) product image. */
    public function coverImage(): ?string
    {
        return $this->candidates()->whereNotNull('image')->orderByRaw('position IS NULL')->orderBy('position')->orderByDesc('score')->value('image');
    }
}

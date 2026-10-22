<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product found for a carousel. It stays outside the catalog until the
 * carousel's selection is approved, then product_id points to the ingested Product.
 *
 * Everything listed here comes from the marketplace (verified data) except
 * scores, reasons, description and product_type, which are the LLM's opinion
 * from the title and price only (no image is sent to the model).
 *
 * @property int $carousel_id
 * @property int $store_id
 * @property ?int $product_id
 * @property string $source
 * @property string $url
 * @property string $url_normalized
 * @property ?string $affiliate_url
 * @property string $title
 * @property ?string $image
 * @property ?string $price
 * @property ?string $original_price
 * @property ?string $rating
 * @property ?int $sales_count
 * @property ?string $product_type
 * @property ?array<string, int|null> $scores
 * @property ?string $score
 * @property ?array<int, string> $reasons
 * @property ?string $description
 * @property string $status
 * @property ?int $position
 */
class CarouselCandidate extends Model
{
    /** Found by the store's API provider (e.g. Shopee), browsed by Hermes or added by URL. */
    public const SOURCE_API = 'api';

    public const SOURCE_HERMES = 'hermes';

    public const SOURCE_MANUAL = 'manual';

    /** Waiting for the editorial score. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_SCORED = 'scored';

    /** Not scored: the AI provider failed or is not configured. */
    public const STATUS_UNSCORED = 'unscored';

    public const STATUS_REJECTED = 'rejected';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'original_price' => 'decimal:2',
            'rating' => 'decimal:1',
            'sales_count' => 'integer',
            'scores' => 'array',
            'score' => 'decimal:2',
            'reasons' => 'array',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Carousel, $this>
     */
    public function carousel(): BelongsTo
    {
        return $this->belongsTo(Carousel::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

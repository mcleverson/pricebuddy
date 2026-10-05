<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A coupon collected by Hermes. Which coupon to publish is decided later,
 * outside the collection flow.
 *
 * @property int $store_id
 * @property string $source
 * @property string $scope
 * @property string $fingerprint
 * @property ?string $code
 * @property string $title
 * @property string $discount_type
 * @property string $status
 * @property ?string $discount_value
 * @property ?string $minimum_order_value
 * @property ?\Illuminate\Support\Carbon $valid_until
 * @property ?array<int, string> $restrictions
 */
class Coupon extends Model
{
    /** Where the coupon was found: shown with the product during discovery. */
    public const SOURCE_PRODUCT_PAGE = 'product_page';

    /** What the coupon applies to. */
    public const SCOPE_STORE = 'store';

    public const SCOPE_NICHE = 'niche';

    public const SCOPE_PRODUCT = 'product';

    public const DISCOUNT_TYPES = ['percentage', 'fixed', 'free_shipping', 'other'];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_EXPIRED = 'expired';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'minimum_order_value' => 'decimal:2',
            'valid_until' => 'date',
            'categories' => 'array',
            'restrictions' => 'array',
            'confidence' => 'decimal:2',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Short badge text, e.g. "Cupom 10%", "Cupom R$ 20", "Cupom frete grátis".
     */
    public function badgeLabel(): string
    {
        $value = $this->discount_value !== null ? (float) $this->discount_value : null;

        return __('Coupon').' '.match (true) {
            $this->discount_type === 'percentage' && $value !== null => rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',').'%',
            $this->discount_type === 'fixed' && $value !== null => 'R$ '.rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ','),
            $this->discount_type === 'free_shipping' => __('free shipping'),
            default => $this->title,
        };
    }

    /**
     * Hover text with what was read on the page: title, code and conditions.
     */
    public function hoverText(): string
    {
        return collect([
            $this->title,
            $this->code ? __('Code').': '.$this->code : __('No code — activate it on the product page'),
            $this->minimum_order_value !== null ? __('Minimum order').': R$ '.number_format((float) $this->minimum_order_value, 2, ',', '.') : null,
            $this->valid_until ? __('Valid until').' '.$this->valid_until->format('d/m/Y') : null,
            ...((array) $this->restrictions),
        ])->filter()->implode(' · ');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'coupon_tag')->withTimestamps();
    }
}

<?php

namespace App\Http\Requests;

use App\Models\Coupon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DiscoveryCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'url:http,https'],
            'title' => ['required', 'string', 'max:1024'],
            'price' => ['required', 'numeric', 'gt:0'],
            'original_price' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            // Affiliate commission as a % of the price, when the source knows it;
            // seller_commission is the part of it paid by the seller.
            'product_commission' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'seller_commission' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'image' => ['sometimes', 'nullable', 'url:http,https', 'max:1024'],
            // A ready-to-use affiliate link, when the discovery source already has
            // one (e.g. Shopee's API returns it alongside the product itself) —
            // saves waiting for the next price-refresh cycle to populate it.
            'affiliate_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'product_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('user_id', auth()->id())),
            ],

            // Tags / niche to attach to the newly created product.
            // Accepts an array of tag IDs or tag names (strings).
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['sometimes', 'nullable', 'string'],

            // A coupon shown on the product page itself (e.g. Amazon's "Resgatar
            // cupom"), stored as a product-scoped coupon of this store.
            'coupon' => ['sometimes', 'nullable', 'array'],
            'coupon.title' => ['required_with:coupon', 'string', 'max:300'],
            'coupon.code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'coupon.discount_type' => ['required_with:coupon', Rule::in(Coupon::DISCOUNT_TYPES)],
            'coupon.discount_value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'coupon.minimum_order_value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'coupon.valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'coupon.restrictions' => ['sometimes', 'array'],
            'coupon.restrictions.*' => ['string', 'max:300'],
            'coupon.evidence' => ['required_with:coupon', 'string', 'max:2000'],
            'coupon.confidence' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],

            // Accepted for forward compatibility with external discovery clients.
            // The current schema has no fields for these values, so they are not persisted yet.
            'rating' => ['sometimes', 'nullable', 'numeric', 'between:0,5'],
            'seller' => ['sometimes', 'nullable', 'string', 'max:255'],
            'external_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}

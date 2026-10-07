<?php

namespace App\Actions;

use App\Models\Coupon;
use App\Models\Price;
use App\Models\Product;
use App\Models\Tag;
use App\Models\Url;
use App\Services\ScrapeUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IngestProductCandidateAction
{
    /**
     * @param  array<string, mixed>  $candidate
     * @return array{product: Product|null, url: Url|null, created: bool, url_created: bool, conflict: bool}
     */
    public function __invoke(array $candidate): array
    {
        $url = (string) $candidate['url'];
        $normalizedUrl = Url::normalizeForMatch($url);
        $requestedProduct = null;

        if (isset($candidate['product_id'])) {
            $requestedProduct = Product::query()
                ->whereKey($candidate['product_id'])
                ->where('user_id', auth()->id())
                ->firstOrFail();
        }

        $existingUrl = Url::query()
            ->where('url_normalized', $normalizedUrl)
            ->whereHas('product', fn ($query) => $query->where('user_id', auth()->id()))
            ->with('product')
            ->first();

        if ($existingUrl?->product) {
            if ($requestedProduct && (int) $existingUrl->product_id !== (int) $requestedProduct->getKey()) {
                return [
                    'product' => $existingUrl->product,
                    'url' => $existingUrl,
                    'created' => false,
                    'url_created' => false,
                    'conflict' => true,
                ];
            }

            $this->createPrice($existingUrl, $candidate);

            // Update product image if it is missing and the candidate provides one.
            $product = $existingUrl->product;
            if (! empty($candidate['image']) && empty($product->image)) {
                $product->image = Str::limit($candidate['image'], ScrapeUrl::MAX_STR_LENGTH - 1, '');
                $product->saveQuietly();
            }

            $existingUrl->product->updatePriceCache();

            return [
                'product' => $existingUrl->product->fresh(),
                'url' => $existingUrl,
                'created' => false,
                'url_created' => false,
                'conflict' => false,
            ];
        }

        return DB::transaction(function () use ($candidate, $url, $requestedProduct): array {
            $product = $requestedProduct ?? (new CreateProductAction)([
                'title' => $candidate['title'],
                'image' => $candidate['image'] ?? null,
                'user_id' => auth()->id(),
            ]);

            $urlModel = $product->urls()->create([
                'url' => $url,
                'store_id' => $candidate['store_id'],
                'affiliate_url' => $candidate['affiliate_url'] ?? null,
                'affiliate_url_synced_at' => filled($candidate['affiliate_url'] ?? null) ? now() : null,
            ]);

            $this->createPrice($urlModel, $candidate);

            // Ensure the price cache is rebuilt immediately so the card shows the price.
            $product->updatePriceCache();

            // Attach tags/niche if provided (e.g., "eletronicos").
            $this->syncTags($product, $candidate['tags'] ?? []);

            if (! empty($candidate['coupon'])) {
                $this->createCoupon($product, $urlModel, $candidate['coupon']);
            }

            return [
                'product' => $product->fresh(),
                'url' => $urlModel,
                'created' => $requestedProduct === null,
                'url_created' => true,
                'conflict' => false,
            ];
        });
    }

    /**
     * Store a coupon seen on the product page as a product-scoped coupon; its
     * niches are the product's own.
     *
     * @param  array<string, mixed>  $coupon
     */
    private function createCoupon(Product $product, Url $url, array $coupon): void
    {
        $validUntil = filled($coupon['valid_until'] ?? null) ? Carbon::parse($coupon['valid_until']) : null;

        $model = Coupon::updateOrCreate([
            'store_id' => $url->store_id,
            'source' => Coupon::SOURCE_PRODUCT_PAGE,
            'fingerprint' => hash('sha256', 'product:'.$product->id.'|'.Str::lower(Str::squish(
                ($coupon['code'] ?? '').'|'.$coupon['title'].'|'.$coupon['discount_type'].'|'.($coupon['discount_value'] ?? '')
            ))),
        ], [
            'product_id' => $product->id,
            'scope' => Coupon::SCOPE_PRODUCT,
            'code' => $coupon['code'] ?? null,
            'title' => $coupon['title'],
            'discount_type' => $coupon['discount_type'],
            'discount_value' => $coupon['discount_value'] ?? null,
            'minimum_order_value' => $coupon['minimum_order_value'] ?? null,
            'valid_until' => $validUntil,
            'activation_url' => $url->url,
            'restrictions' => array_values((array) ($coupon['restrictions'] ?? [])),
            'status' => $validUntil !== null && $validUntil->endOfDay()->isPast() ? Coupon::STATUS_EXPIRED : Coupon::STATUS_ACTIVE,
            'confidence' => $coupon['confidence'] ?? null,
            'source_url' => $url->url,
            'evidence' => $coupon['evidence'],
            'last_seen_at' => now(),
        ]);
        $model->tags()->sync($product->tags()->pluck('tags.id')->all());
    }

    /**
     * Create a price record directly from a numeric discovery candidate.
     */
    private function createPrice(Url $url, array $candidate): void
    {
        $priceFactor = $url->price_factor ?: 1;

        Price::create([
            'url_id' => $url->id,
            'store_id' => $url->store_id,
            'price' => (float) $candidate['price'],
            'original_price' => isset($candidate['original_price']) ? (float) $candidate['original_price'] : null,
            'product_commission' => isset($candidate['product_commission']) ? (float) $candidate['product_commission'] : null,
            'seller_commission' => isset($candidate['seller_commission']) ? (float) $candidate['seller_commission'] : null,
            'unit_price' => (float) $candidate['price'] / $priceFactor,
            'price_factor' => $priceFactor,
        ]);
    }

    /**
     * Resolve or create tags by name and attach them to the product.
     *
     * @param  array<int, string>  $tagNames
     */
    private function syncTags(Product $product, array $tagNames): void
    {
        if ($tagNames === []) {
            return;
        }

        $userId = auth()->id();
        $tagIds = [];

        foreach ($tagNames as $name) {
            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            $name = trim($name);
            $tag = Tag::query()
                ->where('user_id', $userId)
                ->where('name', $name)
                ->first();

            if ($tag === null) {
                $tag = Tag::create([
                    'name' => $name,
                    'user_id' => $userId,
                ]);
            }

            $tagIds[] = $tag->getKey();
        }

        if ($tagIds !== []) {
            $product->tags()->syncWithoutDetaching($tagIds);
        }
    }
}

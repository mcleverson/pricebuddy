<?php

namespace App\Actions;

use App\Models\Price;
use App\Models\Product;
use App\Models\Tag;
use App\Models\Url;
use App\Services\ScrapeUrl;
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

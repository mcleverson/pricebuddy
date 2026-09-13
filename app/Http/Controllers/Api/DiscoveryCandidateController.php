<?php

namespace App\Http\Controllers\Api;

use App\Actions\IngestProductCandidateAction;
use App\Filament\Resources\ProductResource\Api\Transformers\ProductTransformer;
use App\Http\Controllers\Controller;
use App\Http\Requests\DiscoveryCandidateRequest;
use App\Models\Url;
use App\Services\Scraping\MarketplaceStrategyResolver;
use App\Services\Scraping\ScrapingGateway;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Discovery')]
class DiscoveryCandidateController extends Controller
{
    public function __construct(
        protected IngestProductCandidateAction $ingestCandidate,
    ) {}

    /** Check a page of candidate URLs against the authenticated user's entire catalog. */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'urls' => ['required', 'array', 'min:1', 'max:100'],
            'urls.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ]);
        $normalized = array_map(fn (string $url): string => Url::normalizeForMatch($url), $data['urls']);
        $existing = Url::query()
            ->whereIn('url_normalized', $normalized)
            ->whereHas('product', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with('product:id,image')
            ->get()
            ->keyBy('url_normalized');

        return response()->json(['results' => array_map(
            fn (string $url, string $key): array => [
                'url' => $url,
                'key' => $key,
                'exists' => $existing->has($key),
                'has_image' => filled($existing->get($key)?->product?->image),
            ],
            $data['urls'],
            $normalized,
        )]);
    }

    /** Resolve candidate images from a marketplace listing via the structured scraper. */
    public function images(
        Request $request,
        MarketplaceStrategyResolver $strategies,
        ScrapingGateway $scraping,
    ): JsonResponse {
        $data = $request->validate([
            'listing_url' => ['required', 'string', 'url:http,https', 'max:2048'],
            'urls' => ['required', 'array', 'min:1', 'max:100'],
            'urls.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ]);

        $strategy = $strategies->resolve($data['listing_url']);
        if ($strategy->key() === 'default'
            || collect($data['urls'])->contains(
                fn (string $url): bool => $strategies->resolve($url)->key() !== $strategy->key()
            )) {
            return response()->json(['message' => 'Listing and candidate marketplace must match.'], 422);
        }

        $fetch = $scraping->fetch(
            url: $data['listing_url'],
            scraperService: 'api',
            useCache: true,
            cacheTtlMinutes: 2,
            connectTimeout: 20,
            requestTimeout: 60,
        );

        if (! $fetch->successful()) {
            return response()->json(['message' => 'Could not read marketplace listing.'], 502);
        }

        $images = $strategy->extractListingImages($fetch->page->getBody());

        return response()->json(['results' => array_map(
            fn (string $url): array => [
                'url' => $url,
                'image' => $images[Url::normalizeForMatch($url)] ?? null,
            ],
            $data['urls'],
        )]);
    }

    /**
     * Ingest a product candidate collected by an external discovery source.
     */
    public function __invoke(DiscoveryCandidateRequest $request): JsonResponse
    {
        $result = ($this->ingestCandidate)($request->validated());

        if ($result['conflict']) {
            return response()->json([
                'message' => 'The URL already belongs to another product.',
            ], 409);
        }

        return response()->json([
            'data' => new ProductTransformer($result['product']),
            'created' => $result['created'],
            'url_created' => $result['url_created'],
            'message' => $result['created'] ? 'Product candidate ingested' : 'Product already exists',
        ], $result['created'] ? 201 : 200);
    }
}

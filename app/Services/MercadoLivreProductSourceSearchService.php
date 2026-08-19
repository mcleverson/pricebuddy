<?php

namespace App\Services;

use App\Models\ProductSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class MercadoLivreProductSourceSearchService
{
    protected LoggerInterface $logger;

    protected ?string $accessToken = null;

    protected string $baseUrl;

    public function __construct(
        protected ProductSource $source,
        protected MercadoLivreAuthService $authService,
    ) {
        // @phpstan-ignore-next-line - withContext is valid.
        $this->logger = Log::channel('db')->withContext(['source' => $source->getKey()]);
        $this->baseUrl = rtrim((string) config('services.mercado_livre.base_url', 'https://api.mercadolibre.com'), '/');
    }

    public static function new(ProductSource $source): self
    {
        return resolve(static::class, ['source' => $source]);
    }

    public function search(string $query): Collection
    {
        try {
            $this->accessToken = $this->authService->getAccessToken();
        } catch (RuntimeException $e) {
            $this->logger->warning('Mercado Livre access token is not available, skipping search', [
                'source' => $this->source->getKey(),
                'error' => $e->getMessage(),
            ]);

            return collect();
        }

        $catalogProducts = $this->fetchCatalogProducts($query);

        if ($catalogProducts->isEmpty()) {
            return collect();
        }

        $itemIds = $this->collectItemIds($catalogProducts);

        if ($itemIds->isEmpty()) {
            $this->logger->info('No Mercado Livre item IDs found for query', [
                'query' => $query,
                'source' => $this->source->getKey(),
            ]);

            return collect();
        }

        return $this->fetchItems($itemIds);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function fetchCatalogProducts(string $query): Collection
    {
        try {
            $this->logger->info('Mercado Livre catalog search started', [
                'query' => $query,
                'source' => $this->source->getKey(),
            ]);

            $response = Http::timeout(10)
                ->withToken($this->accessToken)
                ->get("{$this->baseUrl}/products/search", [
                    'status' => 'active',
                    'site_id' => 'MLB',
                    'q' => $query,
                    'limit' => 10,
                ])
                ->throw();

            $results = $response->json('results', []);

            $this->logger->info('Mercado Livre catalog search completed', [
                'query' => $query,
                'catalog_products_count' => count($results),
            ]);

            return collect($results);
        } catch (ConnectionException $e) {
            $this->logger->error('Mercado Livre catalog search timed out', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
        } catch (RequestException $e) {
            $this->logger->error('Mercado Livre catalog search request failed', [
                'query' => $query,
                'status' => $e->response?->status(),
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Mercado Livre catalog search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
        }

        return collect();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $catalogProducts
     * @return Collection<int, string>
     */
    protected function collectItemIds(Collection $catalogProducts): Collection
    {
        $itemIds = collect();

        foreach ($catalogProducts as $product) {
            $productId = data_get($product, 'id');

            if (empty($productId)) {
                continue;
            }

            try {
                $response = Http::timeout(10)
                    ->withToken($this->accessToken)
                    ->get("{$this->baseUrl}/products/{$productId}/items")
                    ->throw();

                $itemId = data_get($item, 'id');

                foreach ($items as $item) {
                    $itemId = data_get($item, 'item_id');

                    if (is_string($itemId) && $itemId !== '') {
                        $itemIds->push($itemId);
                    }
                }
            } catch (ConnectionException $e) {
                $this->logger->warning('Mercado Livre product items request timed out', [
                    'product_id' => $productId,
                    'error' => $e->getMessage(),
                ]);
            } catch (RequestException $e) {
                $this->logger->warning('Mercado Livre product items request failed', [
                    'product_id' => $productId,
                    'status' => $e->response?->status(),
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable $e) {
                $this->logger->warning('Mercado Livre product items request failed', [
                    'product_id' => $productId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $uniqueItemIds = $itemIds->unique()->values();
        $limitedItemIds = $uniqueItemIds->take(20)->values();

        $this->logger->info('Mercado Livre item IDs collected', [
            'total_item_ids' => $itemIds->count(),
            'unique_item_ids' => $uniqueItemIds->count(),
            'final_item_ids' => $limitedItemIds->count(),
        ]);

        return $limitedItemIds;
    }

    /**
     * @param  Collection<int, string>  $itemIds
     * @return Collection<int, array<string, mixed>>
     */
    protected function fetchItems(Collection $itemIds): Collection
    {
        if ($itemIds->isEmpty()) {
            return collect();
        }

        try {
            $response = Http::timeout(10)
                ->withToken($this->accessToken)
                ->get("{$this->baseUrl}/items", [
                    'ids' => $itemIds->implode(','),
                ])
                ->throw();

            $entries = $response->json() ?? [];

            if (! is_array($entries)) {
                $this->logger->warning('Mercado Livre items multiget returned unexpected format');

                return collect();
            }

            $results = collect($entries)
                ->filter(fn ($entry) => is_array($entry) && data_get($entry, 'code') === 200)
                ->map(function ($entry) {
                    $body = data_get($entry, 'body', []);

                    if (! is_array($body)) {
                        return null;
                    }

                    $title = data_get($body, 'title');
                    $url = data_get($body, 'permalink');
                    $thumbnail = data_get($body, 'thumbnail');
                    $catalogProductId = data_get($body, 'catalog_product_id');

                    if (! is_string($title) || $title === '' || ! is_string($url) || $url === '') {
                        if (! is_string($url) || $url === '') {
                            $this->logger->info('Mercado Livre item skipped, missing permalink', [
                                'item_id' => data_get($body, 'id'),
                            ]);
                        }

                        return null;
                    }

                    return [
                        'title' => $title,
                        'url' => $url,
                        'content' => null,
                        'thumbnail' => $thumbnail,
                        'catalog_product_id' => $catalogProductId,
                    ];
                })
                ->filter()
                ->values();

            $this->logger->info('Mercado Livre items processed', [
                'valid_urls' => $results->count(),
            ]);

            return $results;
        } catch (ConnectionException $e) {
            $this->logger->error('Mercado Livre items multiget timed out', [
                'error' => $e->getMessage(),
            ]);
        } catch (RequestException $e) {
            $this->logger->error('Mercado Livre items multiget request failed', [
                'status' => $e->response?->status(),
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Mercado Livre items multiget failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return collect();
    }
}

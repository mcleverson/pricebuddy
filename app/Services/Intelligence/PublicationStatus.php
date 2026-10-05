<?php

namespace App\Services\Intelligence;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Publication status of each product in pricebuddy-intelligence (in queue /
 * published), fetched once per minute for the whole dashboard. When the
 * service is unreachable no badge is shown — it never breaks the page.
 */
class PublicationStatus
{
    public const CACHE_KEY = 'intelligence.publication_status';

    /**
     * @return array{status: string, at: ?string}|null
     */
    public static function for(int $productId): ?array
    {
        return once(fn (): array => self::all())[(string) $productId] ?? null;
    }

    /**
     * @return array<string, array{status: string, at: ?string}>
     */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, function (): array {
            try {
                $response = Http::timeout(2)->acceptJson()
                    ->get(config('services.intelligence.url').'/v1/publications/status');

                return $response->successful() ? (array) $response->json('data', []) : [];
            } catch (Throwable) {
                return [];
            }
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}

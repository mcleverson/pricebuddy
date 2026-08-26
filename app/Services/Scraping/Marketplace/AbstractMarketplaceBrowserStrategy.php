<?php

namespace App\Services\Scraping\Marketplace;

use App\Contracts\MarketplaceBrowserStrategy;

abstract class AbstractMarketplaceBrowserStrategy implements MarketplaceBrowserStrategy
{
    public function browserOptions(string $url): array
    {
        return [];
    }

    public function detectBlockedResponse(array $errors, string $body, ?string $finalUrl = null): ?string
    {
        $signals = strtolower(implode(' ', array_map(
            static function (mixed $error): string {
                if (is_scalar($error)) {
                    return (string) $error;
                }

                $encoded = json_encode($error);

                return is_string($encoded) ? $encoded : '';
            },
            $errors,
        )));

        if (preg_match('/\b(?:403|forbidden)\b/i', $signals) === 1) {
            return 'http_403';
        }

        if (preg_match('/\b(?:429|too many requests)\b/i', $signals) === 1) {
            return 'http_429';
        }

        return null;
    }
}

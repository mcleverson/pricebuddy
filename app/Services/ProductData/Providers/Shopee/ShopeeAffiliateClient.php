<?php

namespace App\Services\ProductData\Providers\Shopee;

use App\Exceptions\ProductDataAccessException;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the Shopee Affiliate Open API (GraphQL, HMAC-SHA256 signed).
 *
 * Auth scheme (confirmed against public Shopee Affiliate API references):
 *   Authorization: SHA256 Credential={appId}, Timestamp={unix seconds}, Signature={sig}
 *   sig = sha256(appId . timestamp . payload . secret)
 * where `payload` must be the exact JSON body bytes sent — so the request body
 * is built once as a string and reused for both the signature and the request,
 * rather than letting the HTTP client re-encode an array (which risks a byte
 * mismatch and an "Invalid Signature" error).
 */
class ShopeeAffiliateClient
{
    /** GraphQL error code of the API's transient "System Error". */
    protected const SYSTEM_ERROR_CODE = 10000;

    /** Attempts per query when the API answers with SYSTEM_ERROR_CODE. */
    protected const MAX_ATTEMPTS = 5;

    public function __construct(
        protected string $appId,
        protected string $secret,
        protected string $endpoint = 'https://open-api.affiliate.shopee.com.br/graphql',
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function query(string $query, array $variables = []): array
    {
        // The API intermittently answers "error [10000]: System Error" to a query
        // that succeeds when sent again later. The failures come in bursts (6 in a
        // row seen on 2026-11-26), so only that case is retried, with a growing
        // pause (2s, 4s, 6s, 8s) long enough to outlast a burst.
        for ($attempt = 1; ; $attempt++) {
            $json = $this->send($query, $variables);
            $transient = collect((array) ($json['errors'] ?? []))
                ->contains(fn (mixed $error): bool => (int) data_get($error, 'extensions.code') === self::SYSTEM_ERROR_CODE);

            if (! $transient || $attempt >= self::MAX_ATTEMPTS) {
                break;
            }

            sleep(2 * $attempt);
        }

        if (! empty($json['errors'])) {
            throw new ProductDataAccessException(
                'Shopee affiliate API returned errors: '.json_encode($json['errors'], JSON_UNESCAPED_UNICODE),
            );
        }

        return (array) data_get($json, 'data', []);
    }

    /**
     * One signed request (a fresh timestamp each time).
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    protected function send(string $query, array $variables): array
    {
        $payload = json_encode(['query' => $query, 'variables' => $variables], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = hash('sha256', $this->appId.$timestamp.$payload.$this->secret);

        $response = Http::withHeaders([
            'Authorization' => sprintf(
                'SHA256 Credential=%s, Timestamp=%d, Signature=%s',
                $this->appId,
                $timestamp,
                $signature,
            ),
        ])->withBody($payload, 'application/json')->post($this->endpoint);

        if ($response->failed()) {
            throw new ProductDataAccessException(
                'Shopee affiliate API request failed with HTTP '.$response->status(),
            );
        }

        return (array) $response->json();
    }
}

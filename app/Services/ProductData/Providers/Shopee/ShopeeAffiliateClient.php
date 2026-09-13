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

        $json = $response->json();

        if (! empty($json['errors'])) {
            throw new ProductDataAccessException(
                'Shopee affiliate API returned errors: '.json_encode($json['errors'], JSON_UNESCAPED_UNICODE),
            );
        }

        return (array) data_get($json, 'data', []);
    }
}

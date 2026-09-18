<?php

namespace App\Contracts;

interface MarketplaceBrowserStrategy
{
    public function key(): string;

    /**
     * @return list<string>
     */
    public function domains(): array;

    /**
     * Options understood by the configured scraper service.
     *
     * @return array<string, scalar>
     */
    public function browserOptions(string $url): array;

    /**
     * Options understood by the Hermes browser agent.
     *
     * @return array<string, scalar>
     */
    public function agentOptions(string $url): array;

    /**
     * Extract product images from a listing fetched by the structured scraper.
     *
     * The returned array is keyed by Url::normalizeForMatch(). Agent code must
     * not parse marketplace HTML itself.
     *
     * @return array<string, string>
     */
    public function extractListingImages(string $body): array;

    /**
     * Return a stable, non-sensitive reason when the page is not valid.
     *
     * @param  array<int|string, mixed>  $errors
     */
    public function detectBlockedResponse(array $errors, string $body, ?string $finalUrl = null): ?string;
}

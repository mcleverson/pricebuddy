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
     * Return a stable, non-sensitive reason when the page is not valid.
     *
     * @param  array<int|string, mixed>  $errors
     */
    public function detectBlockedResponse(array $errors, string $body, ?string $finalUrl = null): ?string;
}

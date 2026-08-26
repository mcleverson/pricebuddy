<?php

namespace App\Services\Scraping;

use Jez500\WebScraperForLaravel\WebScraperInterface;

final class ScrapeFetchResult
{
    public function __construct(
        public readonly ?WebScraperInterface $page,
        public readonly array $errors = [],
        public readonly ?string $blockedReason = null,
        public readonly array $meta = [],
    ) {}

    public function successful(): bool
    {
        return $this->page !== null && $this->errors === [] && $this->blockedReason === null;
    }
}

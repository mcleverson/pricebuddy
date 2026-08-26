<?php

use App\Enums\ProxyMode;

return [
    /* Browser options applied before marketplace and Store options. */
    'global_options' => [],

    'proxy' => [
        'mode' => env('SCRAPER_PROXY_MODE', ProxyMode::Disabled->value),
        'proxies' => env('SCRAPER_PROXIES', ''),
        // A browser fetch may use at most this many proxies inside one ScrapeUrl attempt.
        'max_attempts_per_scrape' => (int) env('SCRAPER_PROXY_MAX_ATTEMPTS', 2),
        'failure_threshold' => (int) env('SCRAPER_PROXY_FAILURE_THRESHOLD', 2),
        'cooldown_seconds' => (int) env('SCRAPER_PROXY_COOLDOWN_SECONDS', 300),
        'state_ttl_seconds' => (int) env('SCRAPER_PROXY_STATE_TTL_SECONDS', 86400),
    ],
];

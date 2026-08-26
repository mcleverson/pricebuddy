# Scraping gateway

All page fetches go through `App\Services\Scraping\ScrapingGateway`. `ScrapeUrl` still owns the PriceBuddy retry loop and still delegates price, title, image, original price, and availability extraction to the Store strategy.

The resolver matches normalized hosts, including subdomains:

| Host family | Strategy |
| --- | --- |
| `mercadolivre.com.br`, `mercadolibre.com` | Mercado Livre |
| `shopee.com.br`, `shopee.com` | Shopee |
| `amazon.com.br` | Amazon Brasil |
| `magazineluiza.com.br`, `magalu.com` | Magalu |
| `aliexpress.com` | AliExpress |
| anything else | Default |

The initial strategies for Shopee, Amazon, Magalu, and AliExpress intentionally only provide domain registration and default block handling. They do not contain extraction selectors.

## Configuration

Proxy use is disabled by default for backward compatibility:

```dotenv
SCRAPER_PROXY_MODE=disabled
SCRAPER_PROXIES=
```

Modes are `disabled`, `prefer`, and `required`. Proxies may be supplied as a comma/newline-separated list of URLs, for example:

```dotenv
SCRAPER_PROXY_MODE=prefer
SCRAPER_PROXIES=http://user:password@proxy-a.example:8080,http://proxy-b.example:8080
```

A Store can override the mode in its existing `scraper_service_settings` field with `proxy_mode=required`. Browser options follow this precedence:

```text
global options < marketplace strategy < Store scraper options
```

The proxy pool stores health counters and cooldown state in the configured Laravel cache; no migration is required. One `ScrapeUrl` attempt allows at most `SCRAPER_PROXY_MAX_ATTEMPTS` browser fetches (default 2), so it does not create a retry multiplication loop.

Proxy credentials are used only to build the scraper request. Logs contain the proxy id and a hash of its host, never the URL or credentials.

## Current scraper-service boundary

The Compose service builds `pricebuddy-scraper:latest` from `jez500/seleniumbase-scrapper:latest` and applies the small compatibility layer in `docker/scraper/apply_article_patch.py`.

The endpoint now maps the implemented options as follows:

| Scraper API option | SeleniumBase application |
| --- | --- |
| `proxy` | `Driver(proxy=...)` |
| `locale` | `Driver(locale=...)` |
| `user-agent` | `Driver(agent=...)` |
| `wait-until` | `Driver(page_load_strategy=...)` where compatible |
| `timezone` | `Emulation.setTimezoneOverride` through CDP |
| `extra-http-headers` | `Network.setExtraHTTPHeaders` through CDP |
| `timeout`, viewport | Existing Driver methods |

The endpoint remains compatible with `/api/article`. Its response now includes a safe `scrapeMeta` diagnostic. When a proxy is configured, compare `proxyHostHash` with `effectiveProxyHostHash` and check `proxyEffective=true`; both values are SHA-256 host hashes and never include proxy credentials. The same host hash is available in PriceBuddy's proxy log context. The response omits proxy, HTTP credentials, and extra-header values from its echoed query object.

Example diagnostic shape:

```json
{
  "scrapeMeta": {
    "proxyConfigured": true,
    "proxyEffective": true,
    "proxyHostHash": "…",
    "effectiveProxyHostHash": "…",
    "browserOptionsApplied": ["timezone", "extra_http_headers", "proxy", "locale", "user_agent"]
  }
}
```

The stock upstream image is intentionally not modified in place; deployments should use the Compose-built image so the PriceBuddy proxy pool reaches SeleniumBase `Driver`.

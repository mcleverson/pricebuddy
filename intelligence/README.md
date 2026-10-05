# pricebuddy-intelligence

Decides which offers already imported by PriceBuddy are worth posting today, and publishes them to Telegram.
It stores only its own data (analyses, daily search budget, publications) in SQLite; offers and price
history are always read from PriceBuddy.

## Flow

1. Takes the products imported today (or today and yesterday) from `GET /api/products`, newest first, up to
   the batch size.
2. The offer is the product's cheapest store in PriceBuddy; the price history comes from the same API.
3. References: other stores of the same PriceBuddy product, other PriceBuddy products with an equivalent
   title, and — only for offers that could be published — Google Shopping via Hermes `POST /search`.
4. Each reference is checked for same model and variant (`analysis/equivalence.py`: storage, RAM, volume,
   weight, voltage, screen, quantity/kit, subscription months, OLED/Pro/Max…, bundled extras, used).
   Only `same` counts; `uncertain` is shown but never confirms.
5. `analysis/decision.py` applies deterministic rules and returns `publish_now`, `schedule`,
   `wait_confirmation`, `monitor`, `ignore` or `repost`, with prices, reasons, risks and references.
   Shipping is ignored; coupons, subscriptions and payment-method prices are reported as conditions, never
   subtracted. Without a completed market check or enough confirmed references, an offer is never confirmed.
6. Publications (Telegram) are scheduled, de-duplicated and revalidated against PriceBuddy right before
   sending. Dry-run is the default; a real send needs Telegram enabled, a bot token, a chat id and dry-run off.

No LLM is used to approve offers or produce prices. Hermes' LLM only reads the Google Shopping page.

## Configuration

- Settings > Intelligence in PriceBuddy (batch size, window, search budget, rules, posting hours, Telegram).
  Read through `GET /api/intelligence/settings`.
- `INTELLIGENCE_PRICEBUDDY_API_TOKEN`: PriceBuddy API key with *product pagination*, *product detail* and
  *Read the Intelligence settings*.
- `PRICEBUDDY_API_BASE_URL` (default `http://app/api`), `HERMES_URL` (default `http://hermes:8000`),
  `INTELLIGENCE_DB_PATH` (default `/data/intelligence.sqlite`), `TZ` (default `America/Sao_Paulo`).

## API (v1, JSON)

| Method | Path | |
|---|---|---|
| GET | `/health` | liveness |
| GET | `/v1/today` | latest analysis of each product in the window, in priority order |
| POST | `/v1/runs` `{limit?}` | start a batch in the background (409 if one is running) |
| GET | `/v1/runs/latest` | status/summary of the last batch |
| POST | `/v1/products/{id}/analyze` | analyze one product now |
| GET | `/v1/products/{id}/analysis` | latest analysis of a product |
| POST | `/v1/publications` `{product_id, scheduled_for?}` | publish now or schedule (ISO 8601) |
| GET | `/v1/publications` | publication log |
| POST | `/v1/publications/{id}/cancel` | cancel a scheduled publication |
| GET | `/v1/settings` | effective settings (token masked) |

The PriceBuddy menu *Intelligence* is a view over this API.

## Run and test

```sh
docker compose build intelligence && docker compose up -d intelligence
docker compose exec intelligence python -m unittest discover -s ../tests -t ..   # no network, no real sends
```

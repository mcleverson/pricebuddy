"""Environment configuration. Operational knobs come from PriceBuddy's
Settings > Intelligence tab at run time (see settings())."""

from __future__ import annotations

import os

PRICEBUDDY_API_BASE_URL = os.environ.get("PRICEBUDDY_API_BASE_URL", "http://app/api").rstrip("/")
PRICEBUDDY_API_TOKEN = os.environ.get("INTELLIGENCE_PRICEBUDDY_API_TOKEN", "")
HERMES_URL = os.environ.get("HERMES_URL", "http://hermes:8000").rstrip("/")
DB_PATH = os.environ.get("INTELLIGENCE_DB_PATH", "/data/intelligence.sqlite")
HOST = os.environ.get("INTELLIGENCE_HOST", "0.0.0.0")
PORT = int(os.environ.get("INTELLIGENCE_PORT", "8100"))
TIMEZONE = os.environ.get("TZ", "America/Sao_Paulo")

# Defaults used when PriceBuddy has no Intelligence settings saved yet.
DEFAULT_SETTINGS = {
    "window": "today",                 # today | today_yesterday
    "batch_size": 20,
    "market_searches_per_day": 30,
    "market_search_delay_seconds": 20,
    "min_references": 2,
    "max_market_gap_percent": 3.0,
    "ignore_market_gap_percent": 10.0,
    "min_discount_percent": 15.0,
    "history_days": 60,
    "posting_start_hour": 8,
    "posting_end_hour": 22,
    "repost_min_drop_percent": 5.0,
    "duplicate_window_hours": 24,
    "auto_run_interval_minutes": 0,
    "telegram_bot_token": "",
    "telegram_chat_id": "",
    "dry_run": True,
}

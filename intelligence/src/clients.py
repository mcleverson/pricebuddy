"""HTTP clients for the two services this one reads from. PriceBuddy owns the
offers and their price history; Hermes only searches. Nothing is copied."""

from __future__ import annotations

from datetime import datetime
from typing import Any, Iterator

import requests

import config


class ClientError(RuntimeError):
    pass


class PriceBuddyClient:
    def __init__(self, base_url: str = config.PRICEBUDDY_API_BASE_URL, token: str = config.PRICEBUDDY_API_TOKEN,
                 session: requests.Session | None = None) -> None:
        self.base_url = base_url
        self.session = session or requests.Session()
        self.session.headers.update({"Authorization": f"Bearer {token}", "Accept": "application/json"})

    def _get(self, path: str, params: dict[str, Any] | None = None) -> Any:
        response = self.session.get(self.base_url + path, params=params, timeout=30)
        if response.status_code in (401, 403):
            raise ClientError(f"PriceBuddy rejected the intelligence API token ({response.status_code})")
        response.raise_for_status()
        return response.json()

    def settings(self) -> dict[str, Any]:
        return (self._get("/intelligence/settings").get("data") or {})

    def products(self, created_since: datetime | None = None) -> Iterator[dict[str, Any]]:
        """Products newest first; stops once older than created_since."""
        page = 1
        while True:
            body = self._get("/products", {"sort": "-created_at", "per_page": 100, "page": page,
                                           "include": "tags,urls,coupons"})
            items = body.get("data") or []
            for item in items:
                if created_since and datetime.fromisoformat(item["created_at"].replace("Z", "+00:00")) < created_since:
                    return
                yield item
            meta = body.get("meta") or {}
            if not items or page >= int(meta.get("last_page") or page):
                return
            page += 1

    def product(self, product_id: int) -> dict[str, Any]:
        return self._get(f"/products/{product_id}", {"include": "tags,urls,coupons"}).get("data") or {}


class HermesClient:
    def __init__(self, base_url: str = config.HERMES_URL, session: requests.Session | None = None) -> None:
        self.base_url = base_url
        self.session = session or requests.Session()

    def search(self, query: str) -> dict[str, Any]:
        try:
            response = self.session.post(self.base_url + "/search", json={"query": query}, timeout=240)
        except requests.RequestException as exc:
            return {"status": "error", "error": str(exc), "results": []}
        if response.status_code == 409:
            return {"status": "busy", "error": "another search is running", "results": []}
        try:
            body = response.json()
        except ValueError:
            return {"status": "error", "error": f"HTTP {response.status_code}", "results": []}
        return body if isinstance(body, dict) else {"status": "error", "error": "invalid response", "results": []}

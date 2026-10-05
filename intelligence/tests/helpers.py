import os
import sys
from datetime import datetime, timedelta

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "src"))

from analysis.service import now_local  # noqa: E402


def iso(dt: datetime) -> str:
    return dt.isoformat()


def product(pid: int, title: str, price: float, original: float | None = None, store: str = "Amazon.com.br",
            history: dict | None = None, extra_stores: list | None = None, created: datetime | None = None,
            coupons: list | None = None) -> dict:
    now = now_local()
    cache = [{"url": f"https://loja/{pid}", "price": price, "original_price": original, "store_id": 21,
              "store_name": store, "history": history or {}, "last_scrape": iso(now - timedelta(hours=1))}]
    for name, value in extra_stores or []:
        cache.append({"url": f"https://{name}/{pid}", "price": value, "original_price": None, "store_id": 99,
                      "store_name": name, "history": {}, "last_scrape": iso(now - timedelta(hours=2))})
    return {"id": pid, "title": title, "image": None, "price_cache": cache,
            "created_at": iso(created or now - timedelta(hours=1)), "coupons": coupons or []}


class FakePriceBuddy:
    def __init__(self, products: list, settings: dict | None = None):
        self.items = {p["id"]: p for p in products}
        self.saved = settings or {}

    def settings(self):
        return self.saved

    def products(self, created_since=None):
        return iter(sorted(self.items.values(), key=lambda p: p["created_at"], reverse=True))

    def product(self, pid):
        return self.items.get(pid)


class FakeHermes:
    def __init__(self, results: list | None = None, status: str = "completed"):
        self.results, self.status, self.queries = results or [], status, []

    def search(self, query):
        self.queries.append(query)
        return {"status": self.status, "results": self.results, "searched_at": iso(now_local())}


class FakeSender:
    def __init__(self):
        self.sent = []

    def send(self, text, *, bot_token, chat_id, dry_run):
        self.sent.append({"text": text, "dry_run": dry_run})
        return {"ok": True, "dry_run": dry_run}

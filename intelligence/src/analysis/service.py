"""Analysis orchestration: PriceBuddy offer -> references -> deterministic decision."""

from __future__ import annotations

import logging
import time
from datetime import datetime, timedelta
from typing import Any
from zoneinfo import ZoneInfo

import config
from analysis import decision as rules
from analysis.equivalence import SAME, compare
from clients import HermesClient, PriceBuddyClient
from store import Store

CURRENT_HOURS = 48          # a PriceBuddy price older than this is not a current reference
MARKET_REUSE_HOURS = 6      # a market search this recent is reused instead of searching again
PUBLISHABLE = {rules.PUBLISH_NOW, rules.SCHEDULE, rules.REPOST}


def now_local() -> datetime:
    return datetime.now(ZoneInfo(config.TIMEZONE)).replace(microsecond=0)


def _parse(value: str | None) -> datetime | None:
    if not value:
        return None
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None
    return parsed if parsed.tzinfo else parsed.replace(tzinfo=ZoneInfo(config.TIMEZONE))


def _is_current(last_scrape: str | None, now: datetime) -> bool:
    scraped = _parse(last_scrape)
    return scraped is not None and now - scraped <= timedelta(hours=CURRENT_HOURS)


def build_offer(product: dict[str, Any]) -> rules.Offer | None:
    cache = [entry for entry in product.get("price_cache") or [] if entry.get("price")]
    if not cache:
        return None
    best = min(cache, key=lambda entry: entry["price"])
    conditions = []
    for coupon in product.get("coupons") or []:
        if coupon.get("status") == "active" and coupon.get("store_id") == best.get("store_id"):
            code = f" (code {coupon['code']})" if coupon.get("code") else " (activate on the product page)"
            conditions.append(f"coupon: {coupon.get('title')}{code}")
    # PriceBuddy copies a lone price to the previous day (for its chart); days
    # before the import are not real history. Both use PriceBuddy's UTC dates.
    imported = _parse(product.get("created_at"))
    first_day = imported.astimezone(ZoneInfo("UTC")).date().isoformat() if imported else ""
    return rules.Offer(
        product_id=int(product["id"]), title=product.get("title") or "", store=best.get("store_name"),
        price=float(best["price"]), original_price=float(best["original_price"]) if best.get("original_price") else None,
        url=best.get("url"), history={day: float(price) for day, price in (best.get("history") or {}).items()
                                      if price and day >= first_day},
        image=product.get("image"), imported_at=product.get("created_at"), conditions=conditions,
        commission=float(best["product_commission"]) if best.get("product_commission") else None,
        seller_commission=float(best["seller_commission"]) if best.get("seller_commission") else None,
    )


def internal_references(product: dict[str, Any], offer: rules.Offer, catalogue: list[dict[str, Any]],
                        now: datetime) -> list[rules.Reference]:
    refs = []
    # Other stores grouped into the same PriceBuddy product are the same item by definition.
    for entry in product.get("price_cache") or []:
        if entry.get("url") == offer.url or not entry.get("price") or not _is_current(entry.get("last_scrape"), now):
            continue
        refs.append(rules.Reference("pricebuddy_store", entry.get("store_name"), offer.title, float(entry["price"]),
                                    entry.get("last_scrape"), entry.get("url"), SAME, ["same PriceBuddy product"]))
    for other in catalogue:
        if other.get("id") == product.get("id"):
            continue
        cache = [e for e in other.get("price_cache") or [] if e.get("price") and _is_current(e.get("last_scrape"), now)]
        if not cache:
            continue
        verdict = compare(offer.title, other.get("title") or "")
        if verdict.status == "different":
            continue
        best = min(cache, key=lambda e: e["price"])
        refs.append(rules.Reference("pricebuddy_product", best.get("store_name"), other.get("title") or "",
                                    float(best["price"]), best.get("last_scrape"), best.get("url"),
                                    verdict.status, verdict.reasons))
    return refs


def market_references(offer: rules.Offer, report: dict[str, Any]) -> list[rules.Reference]:
    refs = []
    for item in report.get("results") or []:
        try:
            price = float(item["price"])
        except (KeyError, TypeError, ValueError):
            continue
        verdict = compare(offer.title, item.get("title") or "")
        refs.append(rules.Reference("google_shopping", item.get("store"), item.get("title") or "", price,
                                    item.get("observed_at"), item.get("url"), verdict.status, verdict.reasons,
                                    item.get("conditions") or []))
    return refs


def search_query(title: str) -> str:
    return " ".join(title.replace("|", " ").split())[:100]


class Analyzer:
    def __init__(self, store: Store, pricebuddy: PriceBuddyClient | None = None, hermes: HermesClient | None = None,
                 sleep=time.sleep) -> None:
        self.store = store
        self.pricebuddy = pricebuddy or PriceBuddyClient()
        self.hermes = hermes or HermesClient()
        self.sleep = sleep
        self._last_search = 0.0

    def settings(self) -> dict[str, Any]:
        try:
            saved = self.pricebuddy.settings()
        except Exception as exc:  # noqa: BLE001 - fall back to defaults, keep running
            logging.warning("Could not read Intelligence settings from PriceBuddy: %s", exc)
            saved = {}
        merged = {**config.DEFAULT_SETTINGS, **{k: v for k, v in saved.items() if v is not None and v != ""}}
        return merged

    def analyze(self, product: dict[str, Any], catalogue: list[dict[str, Any]], settings: dict[str, Any],
                allow_market_search: bool = True) -> dict[str, Any] | None:
        now = now_local()
        offer = build_offer(product)
        if offer is None:
            return None
        refs = internal_references(product, offer, catalogue, now)
        last = self.store.last_publication(offer.product_id)
        decision = rules.decide(offer, refs, last_publication=last, market_status="not_checked", now=now, rules=settings)

        market_status, searched_at = "not_needed", None
        candidate = decision["action"] in PUBLISHABLE or decision["action"] == rules.WAIT_CONFIRMATION
        if not candidate:
            decision = rules.decide(offer, refs, last_publication=last, market_status=market_status, now=now,
                                    rules=settings)
        else:
            market_status, market_refs, searched_at = self._market(offer, settings, now, allow_market_search)
            refs += market_refs
            decision = rules.decide(offer, refs, last_publication=last, market_status=market_status, now=now,
                                    rules=settings)

        payload = {
            "product_id": offer.product_id, "title": offer.title, "image": offer.image, "store": offer.store,
            "url": offer.url, "imported_at": offer.imported_at, "analyzed_at": now.isoformat(),
            "market_status": market_status, "market_searched_at": searched_at, "conditions": offer.conditions,
            **decision,
        }
        self.store.save_analysis(offer.product_id, now.isoformat(), decision["action"], payload)
        return payload

    def _market(self, offer: rules.Offer, settings: dict[str, Any], now: datetime, allowed: bool):
        previous = self.store.latest_analysis(offer.product_id)
        if previous and previous.get("market_status") == "completed" and previous.get("market_searched_at"):
            searched = _parse(previous["market_searched_at"])
            if searched and now - searched <= timedelta(hours=MARKET_REUSE_HOURS):
                refs = [rules.Reference(**ref) for ref in previous.get("references", []) if ref["source"] == "google_shopping"]
                return "completed", refs, previous["market_searched_at"]
        if not allowed:
            return "not_checked", [], None
        day = now.date().isoformat()
        if self.store.count_searches(day) >= int(settings["market_searches_per_day"]):
            return "budget_exhausted", [], None
        wait = float(settings["market_search_delay_seconds"]) - (time.monotonic() - self._last_search)
        if self._last_search and wait > 0:
            self.sleep(wait)
        query = search_query(offer.title)
        report = self.hermes.search(query)
        self._last_search = time.monotonic()
        status = report.get("status") or "error"
        self.store.record_search(day, query, status, now.isoformat())
        if status != "completed":
            return status, [], None
        return "completed", market_references(offer, report), report.get("searched_at") or now.isoformat()

    def run_batch(self, limit: int | None = None) -> dict[str, Any]:
        settings = self.settings()
        now = now_local()
        start = now.replace(hour=0, minute=0, second=0)
        if settings["window"] == "today_yesterday":
            start -= timedelta(days=1)
        catalogue = list(self.pricebuddy.products())
        window = [p for p in catalogue if (_parse(p.get("created_at")) or now) >= start]
        analyzed_today = {a["product_id"] for a in self.store.latest_analyses_since(now.replace(hour=0, minute=0, second=0).isoformat())}
        pending = [p for p in window if p["id"] not in analyzed_today][: int(limit or settings["batch_size"])]
        results = [self.analyze(product, catalogue, settings) for product in pending]
        summary: dict[str, int] = {}
        for result in filter(None, results):
            summary[result["action"]] = summary.get(result["action"], 0) + 1
        return {"window_start": start.isoformat(), "in_window": len(window), "analyzed": len(pending), "actions": summary}

    def run_selection(self) -> dict[str, Any]:
        """Analyze every product in the user's selection, even if already analyzed today."""
        settings = self.settings()
        selected = set(self.store.selection())
        catalogue = list(self.pricebuddy.products())
        products = [p for p in catalogue if p["id"] in selected]
        results = [self.analyze(product, catalogue, settings) for product in products]
        summary: dict[str, int] = {}
        for result in filter(None, results):
            summary[result["action"]] = summary.get(result["action"], 0) + 1
        return {"scope": "selection", "selected": len(selected), "analyzed": len(products), "actions": summary}

    def selection(self) -> dict[str, Any]:
        """Latest analysis of each selected product; the ones never analyzed are listed as pending."""
        selected = self.store.selection()
        analyses = [a for a in (self.store.latest_analysis(pid) for pid in selected) if a]
        analyzed = {a["product_id"] for a in analyses}
        return {"data": sorted(analyses, key=rules.priority),
                "meta": {"selected": len(selected), "pending": [pid for pid in selected if pid not in analyzed]}}

    def today(self) -> dict[str, Any]:
        """Latest analysis of each product imported in the window, plus how many
        products of the window still wait for an analysis."""
        settings = self.settings()
        now = now_local()
        start = now.replace(hour=0, minute=0, second=0)
        if settings["window"] == "today_yesterday":
            start -= timedelta(days=1)
        analyses = [a for a in self.store.latest_analyses_since(start.isoformat())
                    if (_parse(a.get("imported_at")) or now) >= start]
        in_window = [p for p in self.pricebuddy.products(created_since=start)]
        analyzed = {a["product_id"] for a in analyses}
        return {
            "data": sorted(analyses, key=rules.priority),
            "meta": {"window_start": start.isoformat(), "in_window": len(in_window),
                     "analyzed": len(analyzed), "pending": len([p for p in in_window if p["id"] not in analyzed])},
        }

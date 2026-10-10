"""Deterministic recommendation for one offer.

Prices are the displayed final prices of each listing (shipping ignored).
Coupons, subscriptions and payment-method prices are recorded as conditions
but never subtracted: a coupon may have caps or rules we cannot read.
No reference confirmation, no publish recommendation.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime, timedelta
from statistics import median
from typing import Any

PUBLISH_NOW = "publish_now"
SCHEDULE = "schedule"
WAIT_CONFIRMATION = "wait_confirmation"
MONITOR = "monitor"
IGNORE = "ignore"
REPOST = "repost"

ACTION_ORDER = [PUBLISH_NOW, REPOST, SCHEDULE, WAIT_CONFIRMATION, MONITOR, IGNORE]

DEFAULT_RULES = {
    "min_references": 2,
    "max_market_gap_percent": 3.0,
    "ignore_market_gap_percent": 10.0,
    "min_discount_percent": 15.0,
    "history_days": 60,
    "posting_start_hour": 8,
    "posting_end_hour": 22,
    "repost_min_drop_percent": 5.0,
    "duplicate_window_hours": 24,
}


@dataclass
class Reference:
    source: str          # pricebuddy_store | pricebuddy_product | google_shopping
    store: str | None
    title: str
    price: float
    observed_at: str | None
    url: str | None = None
    equivalence: str = "uncertain"
    equivalence_reasons: list[str] = field(default_factory=list)
    conditions: list[str] = field(default_factory=list)

    def as_dict(self) -> dict[str, Any]:
        return self.__dict__.copy()


@dataclass
class Offer:
    product_id: int
    title: str
    store: str | None
    price: float
    original_price: float | None
    url: str | None
    history: dict[str, float]
    image: str | None = None
    imported_at: str | None = None
    conditions: list[str] = field(default_factory=list)
    commission: float | None = None         # affiliate commission, % of the price (display only)
    seller_commission: float | None = None  # part of it paid by the seller, %


def _pct(part: float, whole: float) -> float:
    return round((whole - part) / whole * 100, 1) if whole else 0.0


def decide(offer: Offer, references: list[Reference], *, last_publication: dict[str, Any] | None,
           market_status: str, now: datetime, rules: dict[str, Any] | None = None) -> dict[str, Any]:
    r = {**DEFAULT_RULES, **(rules or {})}
    same = sorted((ref for ref in references if ref.equivalence == "same"), key=lambda ref: ref.price)
    reasons: list[str] = []
    risks: list[str] = []

    since = (now - timedelta(days=int(r["history_days"]))).date().isoformat()
    past = [price for day, price in offer.history.items() if since <= day < now.date().isoformat() and price]
    history_low = min(past) if past else None
    history_median = median(past) if past else None
    discount = _pct(offer.price, offer.original_price) if offer.original_price else None

    prices = {
        "offer": offer.price,
        "original": offer.original_price,
        "discount_percent": discount,
        "history_low": history_low,
        "history_median": history_median,
        "history_days": len(past),
        "cheapest_reference": same[0].price if same else None,
        "cheapest_reference_store": same[0].store if same else None,
        "references_confirmed": len(same),
        "commission": offer.commission,
        "seller_commission": offer.seller_commission,
    }

    # Only earlier days count: today's price is the offer itself, so on a newly
    # imported product any discount above 33% would look like an inflated 'De'.
    earlier = [price for day, price in offer.history.items() if day < now.date().isoformat() and price]
    if offer.original_price and earlier and offer.original_price > max(earlier) * 1.5:
        risks.append(f"store's original price {offer.original_price:.2f} is over 1.5x the highest price ever "
                     f"recorded ({max(earlier):.2f})")
        # An inflated 'De' price can't make the offer a deal on its own.
        discount = prices["discount_percent"] = None
    if market_status not in ("completed", "not_needed", "not_checked"):
        risks.append(f"market reference unavailable ({market_status})")
    risks += [f"condition: {c}" for c in offer.conditions]

    is_deal = (discount is not None and discount >= r["min_discount_percent"]) or (
        history_median is not None and offer.price <= history_median * (1 - r["min_discount_percent"] / 100))
    if history_low is not None and offer.price <= history_low:
        reasons.append(f"lowest price in {r['history_days']} days (previous low {history_low:.2f})")
    if discount is not None:
        reasons.append(f"{discount:.0f}% below the store's original price {offer.original_price:.2f}")

    def result(action: str, *why: str) -> dict[str, Any]:
        return {"action": action, "reasons": [*why, *reasons], "risks": risks, "prices": prices,
                "references": [ref.as_dict() for ref in references], "scheduled_for": None}

    if last_publication:
        published_price = float(last_publication["price"])
        published_at = datetime.fromisoformat(last_publication["published_at"])
        drop = _pct(offer.price, published_price)
        if drop >= r["repost_min_drop_percent"] and market_status == "completed" and not (
                same and same[0].price < offer.price * (1 - r["max_market_gap_percent"] / 100)):
            return result(REPOST, f"price dropped {drop:.0f}% since it was published at {published_price:.2f}")
        if now - published_at < timedelta(hours=int(r["duplicate_window_hours"])):
            return result(IGNORE, f"already published at {published_price:.2f} on {published_at:%d/%m %H:%M}")

    if not is_deal:
        return result(MONITOR, "no real price drop (below the minimum discount and not under the usual price)")

    if same and same[0].price < offer.price:
        gap = _pct(same[0].price, offer.price)
        where = f"{same[0].store or same[0].source} at {same[0].price:.2f}"
        if gap >= r["ignore_market_gap_percent"]:
            return result(IGNORE, f"same item is {gap:.0f}% cheaper at {where}")
        if gap > r["max_market_gap_percent"]:
            return result(MONITOR, f"same item is {gap:.0f}% cheaper at {where}")

    if market_status != "completed":
        return result(WAIT_CONFIRMATION,
                      "national market price not checked yet; a cheaper store could exist outside PriceBuddy")

    if len(same) < int(r["min_references"]):
        return result(WAIT_CONFIRMATION,
                      f"only {len(same)} confirmed current reference(s); {r['min_references']} required to confirm")

    reasons.insert(0, f"cheapest among {len(same)} confirmed references (next: {same[0].store} {same[0].price:.2f})"
                   if same[0].price >= offer.price else
                   f"within {r['max_market_gap_percent']}% of the cheapest reference ({same[0].store} {same[0].price:.2f})")
    start, end = int(r["posting_start_hour"]), int(r["posting_end_hour"])
    if start <= now.hour < end:
        return result(PUBLISH_NOW)
    slot = now.replace(hour=start, minute=0, second=0, microsecond=0)
    if now.hour >= end:
        slot += timedelta(days=1)
    decision = result(SCHEDULE, f"outside posting hours ({start}h-{end}h)")
    decision["scheduled_for"] = slot.isoformat()
    return decision


def priority(decision: dict[str, Any]) -> tuple:
    """Order for 'products to post today': action first, then the biggest saving."""
    p = decision["prices"]
    saving = p["discount_percent"] or 0
    if p["history_median"]:
        saving = max(saving, _pct(p["offer"], p["history_median"]))
    return ACTION_ORDER.index(decision["action"]), -saving

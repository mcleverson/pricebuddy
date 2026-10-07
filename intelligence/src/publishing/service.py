"""Publication workflow: schedule, duplicate prevention, revalidation, send.

Publishing is a manual decision: the message is written and approved in
PriceBuddy and sent exactly as approved. Every send still revalidates the
offer against PriceBuddy right before it goes out: if the product is gone,
has no current price or the price went up, the publication is cancelled.
"""

from __future__ import annotations

import logging
from datetime import datetime
from typing import Any

from analysis import decision as rules
from analysis.service import Analyzer, now_local
from publishing.content import to_telegram_html
from publishing.telegram import TelegramSender
from store import Store

PRICE_TOLERANCE = 0.01   # 1% price increase since the recommendation cancels the send
# wait_confirmation is publishable by hand: the user can check the market prices themselves.
SENDABLE = {rules.PUBLISH_NOW, rules.SCHEDULE, rules.REPOST, rules.WAIT_CONFIRMATION}


class PublicationError(ValueError):
    pass


class Publisher:
    def __init__(self, store: Store, analyzer: Analyzer, sender: TelegramSender | None = None) -> None:
        self.store = store
        self.analyzer = analyzer
        self.sender = sender or TelegramSender()

    def _dry_run(self, settings: dict[str, Any]) -> bool:
        configured = bool(settings.get("telegram_bot_token") and settings.get("telegram_chat_id"))
        return bool(settings.get("dry_run", True)) or not configured

    def create(self, product_id: int, message: str, scheduled_for: str | None = None,
               image: str | None = None) -> dict[str, Any]:
        """Queue the approved message; without scheduled_for it is sent right away."""
        if not message.strip():
            raise PublicationError("message is required")
        analysis = self.store.latest_analysis(product_id)
        if analysis is None:
            raise PublicationError("product has not been analyzed yet")
        if analysis["action"] not in SENDABLE:
            raise PublicationError(f"latest recommendation is '{analysis['action']}', not publishable")
        if self.store.pending_publication(product_id):
            raise PublicationError("a publication for this product is already scheduled")
        settings = self.analyzer.settings()
        when = scheduled_for or analysis.get("scheduled_for") if analysis["action"] == rules.SCHEDULE else scheduled_for
        when = when or now_local().isoformat()
        datetime.fromisoformat(when)  # validates the format
        publication_id = self.store.add_publication(
            product_id=product_id, channel="telegram", status="scheduled", price=analysis["prices"]["offer"],
            message=message.strip(), image=image or None, scheduled_for=when, dry_run=int(self._dry_run(settings)),
            detail=None, created_at=now_local().isoformat(),
        )
        if when <= now_local().isoformat():
            return self.send(publication_id)
        return self.store.publication(publication_id)

    def record_sent(self, product_id: int, message: str, channel: str, image: str | None = None) -> dict[str, Any]:
        """A message the user sent by hand (e.g. pasted in WhatsApp), recorded as published now."""
        if not message.strip():
            raise PublicationError("message is required")
        analysis = self.store.latest_analysis(product_id)
        if analysis is None:
            raise PublicationError("product has not been analyzed yet")
        now = now_local().isoformat()
        publication_id = self.store.add_publication(
            product_id=product_id, channel=channel, status="sent", price=analysis["prices"]["offer"],
            message=message.strip(), image=image or None, scheduled_for=now, published_at=now, dry_run=0,
            detail="sent manually", created_at=now,
        )
        return self.store.publication(publication_id)

    def cancel(self, publication_id: int, reason: str = "cancelled by user") -> dict[str, Any]:
        publication = self.store.publication(publication_id)
        if publication is None:
            raise PublicationError("publication not found")
        if publication["status"] != "scheduled":
            raise PublicationError(f"publication is already {publication['status']}")
        self.store.update_publication(publication_id, status="cancelled", detail=reason)
        return self.store.publication(publication_id)

    def send(self, publication_id: int) -> dict[str, Any]:
        publication = self.store.publication(publication_id)
        if publication is None or publication["status"] != "scheduled":
            raise PublicationError("publication is not scheduled")
        settings = self.analyzer.settings()
        reason = self._revalidate(publication, settings)
        if reason:
            self.store.update_publication(publication_id, status="cancelled", detail=f"revalidation: {reason}")
            return self.store.publication(publication_id)
        dry_run = self._dry_run(settings)
        result = self.sender.send(to_telegram_html(publication["message"]), bot_token=settings.get("telegram_bot_token", ""),
                                  chat_id=str(settings.get("telegram_chat_id", "")), dry_run=dry_run,
                                  image=publication.get("image"))
        status = ("dry_run" if dry_run else "sent") if result.get("ok") else "failed"
        self.store.update_publication(publication_id, status=status, dry_run=int(dry_run),
                                      published_at=now_local().isoformat() if result.get("ok") else None,
                                      detail=result.get("error"))
        return self.store.publication(publication_id)

    def _revalidate(self, publication: dict[str, Any], settings: dict[str, Any]) -> str | None:
        product = self.analyzer.pricebuddy.product(publication["product_id"])
        if not product:
            return "product no longer exists in PriceBuddy"
        catalogue = list(self.analyzer.pricebuddy.products())
        fresh = self.analyzer.analyze(product, catalogue, settings)
        if fresh is None:
            return "product has no current price"
        if fresh["prices"]["offer"] > publication["price"] * (1 + PRICE_TOLERANCE):
            return f"price went up from {publication['price']:.2f} to {fresh['prices']['offer']:.2f}"
        return None

    def process_due(self) -> list[dict[str, Any]]:
        sent = []
        for publication in self.store.due_publications(now_local().isoformat()):
            try:
                sent.append(self.send(publication["id"]))
            except Exception as exc:  # noqa: BLE001 - one failure must not stop the queue
                logging.exception("Publication %s failed", publication["id"])
                self.store.update_publication(publication["id"], status="failed", detail=str(exc)[:300])
        return sent

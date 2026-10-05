"""Telegram Bot API sender. Never sends in dry-run or without explicit configuration."""

from __future__ import annotations

from typing import Any

import requests


class TelegramSender:
    def __init__(self, session: requests.Session | None = None) -> None:
        self.session = session or requests.Session()

    def send(self, text: str, *, bot_token: str, chat_id: str, dry_run: bool) -> dict[str, Any]:
        if dry_run:
            return {"ok": True, "dry_run": True}
        if not bot_token or not chat_id:
            return {"ok": False, "error": "Telegram bot token and chat id are not configured"}
        try:
            response = self.session.post(f"https://api.telegram.org/bot{bot_token}/sendMessage", timeout=20, json={
                "chat_id": chat_id, "text": text, "parse_mode": "HTML", "disable_web_page_preview": False,
            })
            body = response.json()
        except (requests.RequestException, ValueError) as exc:
            return {"ok": False, "error": str(exc).replace(bot_token, "***")}
        if not body.get("ok"):
            return {"ok": False, "error": str(body.get("description") or f"HTTP {response.status_code}")}
        return {"ok": True, "message_id": body.get("result", {}).get("message_id")}

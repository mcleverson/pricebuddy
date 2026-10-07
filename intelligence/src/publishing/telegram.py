"""Telegram Bot API sender. Never sends in dry-run or without explicit configuration.

With an image the message goes as a photo caption. When the caption is too
long the photo is sent first and the text right after; when Telegram refuses
the photo (e.g. an unsupported format) only the text is sent."""

from __future__ import annotations

from typing import Any

import requests

CAPTION_LIMIT = 1024


class TelegramSender:
    def __init__(self, session: requests.Session | None = None) -> None:
        self.session = session or requests.Session()

    def send(self, text: str, *, bot_token: str, chat_id: str, dry_run: bool, image: str | None = None) -> dict[str, Any]:
        if dry_run:
            return {"ok": True, "dry_run": True}
        if not bot_token or not chat_id:
            return {"ok": False, "error": "Telegram bot token and chat id are not configured"}
        if image:
            fits = len(text) <= CAPTION_LIMIT
            photo = self._call(bot_token, "sendPhoto", {"chat_id": chat_id, "photo": image,
                                                         **({"caption": text, "parse_mode": "HTML"} if fits else {})})
            if photo["ok"] and fits:
                return photo
        return self._call(bot_token, "sendMessage", {
            "chat_id": chat_id, "text": text, "parse_mode": "HTML", "disable_web_page_preview": False,
        })

    def _call(self, bot_token: str, method: str, payload: dict[str, Any]) -> dict[str, Any]:
        try:
            response = self.session.post(f"https://api.telegram.org/bot{bot_token}/{method}", timeout=20, json=payload)
            body = response.json()
        except (requests.RequestException, ValueError) as exc:
            return {"ok": False, "error": str(exc).replace(bot_token, "***")}
        if not body.get("ok"):
            return {"ok": False, "error": str(body.get("description") or f"HTTP {response.status_code}")}
        return {"ok": True, "message_id": body.get("result", {}).get("message_id")}

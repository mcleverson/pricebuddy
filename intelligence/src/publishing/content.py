"""Message formatting for publication channels. The text itself is written
(and approved) in PriceBuddy; here it is only adapted to the channel."""

from __future__ import annotations

import html
import re

# WhatsApp markup used by the approved message: *bold* and ~strikethrough~.
_BOLD = re.compile(r"(?<![\w*])\*(?=\S)([^*\n]+?)(?<=\S)\*(?![\w*])")
_STRIKE = re.compile(r"(?<![\w~])~(?=\S)([^~\n]+?)(?<=\S)~(?![\w~])")
_URL = re.compile(r"(https?://\S+)")


def to_telegram_html(text: str) -> str:
    """Escape the message for Telegram's HTML parse mode, keeping WhatsApp bold/strike.
    Links are left untouched so affiliate parameters are never altered."""
    parts = _URL.split(text)
    for i, part in enumerate(parts):
        escaped = html.escape(part, quote=False)
        parts[i] = escaped if i % 2 else _STRIKE.sub(r"<s>\1</s>", _BOLD.sub(r"<b>\1</b>", escaped))
    return "".join(parts)

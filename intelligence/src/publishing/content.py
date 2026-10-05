"""Fixed message layout for a publication. No generated or suggested copy:
only the offer's own facts, as read from PriceBuddy at send time."""

from __future__ import annotations

import html
from typing import Any


def _brl(value: float) -> str:
    return "R$ " + f"{value:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")


def build_message(analysis: dict[str, Any]) -> str:
    prices = analysis["prices"]
    lines = [f"<b>{html.escape(analysis['title'])}</b>", ""]
    if prices.get("original") and prices.get("discount_percent"):
        lines.append(f"De <s>{_brl(prices['original'])}</s> por <b>{_brl(prices['offer'])}</b> "
                     f"(-{prices['discount_percent']:.0f}%)")
    else:
        lines.append(f"Por <b>{_brl(prices['offer'])}</b>")
    if analysis.get("store"):
        lines.append(f"Loja: {html.escape(analysis['store'])}")
    for condition in analysis.get("conditions") or []:
        lines.append(html.escape(condition))
    if analysis.get("url"):
        lines += ["", html.escape(analysis["url"])]
    return "\n".join(lines)

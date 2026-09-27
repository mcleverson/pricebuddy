"""Hermes HTTP service boundary.

Exposes health and discovery endpoints used by the PriceBuddy app to trigger
Hermes agent runs via the internal Docker network, plus /evaluate, which
applies the same relevance admission to candidates discovered elsewhere
(e.g. a marketplace API) so both discovery paths share one set of rules.
"""

import json
import logging
import threading
import os
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any

import config
from agent import Agent
from browser_tools import ProductCandidate
from llm_client import LLMClient
from relevance import RelevanceEvaluator, RelevanceProfile

MAX_EVALUATE_CANDIDATES = 300


DISCOVERY_LOCK = threading.Lock()


def evaluate_request(body: dict[str, Any], llm_client: LLMClient | None = None) -> tuple[int, dict[str, Any]]:
    """Admission decisions for externally discovered candidates.

    Returns one decision per candidate, keyed by the caller's normalized
    `key` (also the cache key). `enabled` is false when the profile has no
    usable rule for these niches, meaning the caller should not filter.
    """
    tags = body.get("tags") or []
    candidates = body.get("candidates")
    profile_raw = body.get("relevance_profile")
    if not isinstance(tags, list) or not all(isinstance(tag, str) for tag in tags):
        return 400, {"status": "error", "error": "tags must be a list of strings"}
    if profile_raw is not None and not isinstance(profile_raw, dict):
        return 400, {"status": "error", "error": "relevance_profile must be an object"}
    if not isinstance(candidates, list) or len(candidates) > MAX_EVALUATE_CANDIDATES:
        return 400, {"status": "error", "error": f"candidates must be a list of at most {MAX_EVALUATE_CANDIDATES} items"}

    items: list[tuple[str, ProductCandidate]] = []
    for item in candidates:
        if (not isinstance(item, dict) or not isinstance(item.get("key"), str) or not item["key"]
                or not isinstance(item.get("title"), str) or not item["title"].strip()):
            return 400, {"status": "error", "error": "each candidate needs a non-empty key and title"}
        price = item.get("price")
        original_price = item.get("original_price")
        items.append((item["key"], ProductCandidate(
            url=str(item.get("url") or item["key"]),
            title=item["title"],
            price=str(price) if price is not None else "",
            original_price=str(original_price) if original_price is not None else None,
            tag=item.get("tag") if item.get("tag") in tags else None,
        )))

    profile = RelevanceProfile(profile_raw, tags)
    if not profile.enabled:
        return 200, {"enabled": False, "decisions": []}

    evaluator = RelevanceEvaluator(llm_client or LLMClient(), profile)
    decisions = evaluator.evaluate(items, timeout_seconds=config.RUN_TIMEOUT_SECONDS)
    return 200, {
        "enabled": True,
        "decisions": [{"key": key, **decision.as_dict()} for (key, _), decision in zip(items, decisions)],
    }


class RequestHandler(BaseHTTPRequestHandler):
    def do_GET(self) -> None:  # noqa: N802 - required by BaseHTTPRequestHandler
        if self.path != "/health":
            self.send_response(404)
            self.end_headers()
            return

        payload = json.dumps({"status": "ok"}).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def do_POST(self) -> None:  # noqa: N802 - required by BaseHTTPRequestHandler
        if self.path not in ("/discover", "/evaluate"):
            self.send_response(404)
            self.end_headers()
            return

        content_length = int(self.headers.get("Content-Length", 0))
        if content_length == 0:
            self._send_json(400, {"status": "error", "error": "Empty request body"})
            return

        raw_body = self.rfile.read(content_length)
        try:
            body = json.loads(raw_body)
        except json.JSONDecodeError as exc:
            self._send_json(400, {"status": "error", "error": f"Invalid JSON: {exc}"})
            return

        if not isinstance(body, dict):
            self._send_json(400, {"status": "error", "error": "Expected a JSON object"})
            return

        if self.path == "/evaluate":
            try:
                status_code, payload = evaluate_request(body)
            except Exception as exc:  # noqa: BLE001 - we want to return error to caller
                logging.exception("Relevance evaluation request failed")
                status_code, payload = 500, {"status": "error", "error": config.redact_secrets(str(exc))}
            self._send_json(status_code, payload)
            return

        goal = body.get("goal")
        marketplace = body.get("marketplace")
        urls = body.get("urls") or []
        tags = body.get("tags") or []
        allowed_hosts = body.get("allowed_hosts") or body.get("allowedHosts")
        agent_options = body.get("agent_options", {})
        if agent_options is None:
            agent_options = {}
        relevance_profile = body.get("relevance_profile")
        min_products = body.get("min_products", config.MIN_PRODUCTS)
        min_discount_percentage = body.get("min_discount_percentage", config.HERMES_MIN_DISCOUNT_PERCENTAGE)
        min_rating = body.get("min_rating", config.HERMES_MIN_RATING)
        min_sales = body.get("min_sales", config.HERMES_MIN_SALES)
        store_id = body.get("store_id") or body.get("storeId")

        if not goal or not marketplace:
            self._send_json(400, {"status": "error", "error": "Missing required fields: marketplace, goal"})
            return

        if not isinstance(agent_options, dict):
            self._send_json(400, {"status": "error", "error": "agent_options must be an object"})
            return

        if relevance_profile is not None and not isinstance(relevance_profile, dict):
            self._send_json(400, {"status": "error", "error": "relevance_profile must be an object"})
            return

        try:
            min_products = int(min_products)
            if min_products < 1:
                raise ValueError("min_products must be at least 1")
            if min_discount_percentage is not None:
                min_discount_percentage = float(min_discount_percentage)
            if min_rating is not None:
                min_rating = float(min_rating)
            if min_sales is not None:
                min_sales = int(min_sales)
            if store_id is not None:
                store_id = int(store_id)
        except (ValueError, TypeError) as exc:
            self._send_json(400, {"status": "error", "error": f"Invalid numeric parameter: {exc}"})
            return

        if not DISCOVERY_LOCK.acquire(blocking=False):
            self._send_json(409, {"status": "error", "error": "A discovery run is already active"})
            return

        try:
            agent = Agent(
                marketplace=marketplace,
                goal=goal,
                headless=True,
                tags=tags,
                starting_urls=urls,
                allowed_hosts=allowed_hosts,
                store_id=store_id,
                min_products=min_products,
                min_discount_percentage=min_discount_percentage,
                min_rating=min_rating,
                min_sales=min_sales,
                browser_options=agent_options,
                relevance_profile=relevance_profile,
            )
            report = agent.run()
        except Exception as exc:  # noqa: BLE001 - we want to return error to caller
            self._send_json(500, {"status": "error", "error": str(exc)})
            return

        finally:
            DISCOVERY_LOCK.release()

        self._send_json(200, report)

    def _send_json(self, status_code: int, data: dict[str, Any]) -> None:
        payload = json.dumps(data, ensure_ascii=False, default=str).encode("utf-8")
        self.send_response(status_code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, format: str, *args: object) -> None:
        return


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    host = os.environ.get("HERMES_HOST", "0.0.0.0")
    port = int(os.environ.get("HERMES_PORT", "8000"))
    server = ThreadingHTTPServer((host, port), RequestHandler)
    server.serve_forever()


if __name__ == "__main__":
    main()

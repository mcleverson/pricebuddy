"""Hermes HTTP service boundary.

Exposes health and discovery endpoints used by the PriceBuddy app to trigger
Hermes agent runs via the internal Docker network.
"""

import json
import logging
import threading
import os
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any

import config
from agent import Agent


DISCOVERY_LOCK = threading.Lock()


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
        if self.path != "/discover":
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

        goal = body.get("goal")
        marketplace = body.get("marketplace")
        urls = body.get("urls") or []
        tags = body.get("tags") or []
        allowed_hosts = body.get("allowed_hosts") or body.get("allowedHosts")
        agent_options = body.get("agent_options", {})
        if agent_options is None:
            agent_options = {}
        min_products = body.get("min_products", config.MIN_PRODUCTS)
        min_discount_percentage = body.get("min_discount_percentage", config.HERMES_MIN_DISCOUNT_PERCENTAGE)
        store_id = body.get("store_id") or body.get("storeId")

        if not goal or not marketplace:
            self._send_json(400, {"status": "error", "error": "Missing required fields: marketplace, goal"})
            return

        if not isinstance(agent_options, dict):
            self._send_json(400, {"status": "error", "error": "agent_options must be an object"})
            return

        try:
            min_products = int(min_products)
            if min_products < 1:
                raise ValueError("min_products must be at least 1")
            if min_discount_percentage is not None:
                min_discount_percentage = float(min_discount_percentage)
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
                browser_options=agent_options,
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

"""pricebuddy-intelligence HTTP API (JSON, versioned under /v1)."""

from __future__ import annotations

import json
import logging
import re
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any

import config
from analysis.service import Analyzer, now_local
from publishing.service import PublicationError, Publisher
from store import Store


class App:
    def __init__(self, store: Store, analyzer: Analyzer, publisher: Publisher) -> None:
        self.store, self.analyzer, self.publisher = store, analyzer, publisher
        self.run_lock = threading.Lock()
        self.last_run: dict[str, Any] = {"status": "idle"}

    def start_run(self, limit: int | None = None) -> bool:
        if not self.run_lock.acquire(blocking=False):
            return False

        def work() -> None:
            self.last_run = {"status": "running", "started_at": now_local().isoformat()}
            try:
                summary = self.analyzer.run_batch(limit)
                self.last_run = {"status": "completed", "started_at": self.last_run["started_at"],
                                 "finished_at": now_local().isoformat(), **summary}
            except Exception as exc:  # noqa: BLE001 - surfaced through GET /v1/runs/latest
                logging.exception("Analysis run failed")
                self.last_run = {**self.last_run, "status": "error", "error": str(exc)[:300]}
            finally:
                self.run_lock.release()

        threading.Thread(target=work, daemon=True).start()
        return True

    def routes(self, method: str, path: str, body: dict[str, Any]) -> tuple[int, Any]:
        if method == "GET" and path == "/health":
            return 200, {"status": "ok"}
        if method == "GET" and path == "/v1/today":
            return 200, self.analyzer.today()
        if method == "POST" and path == "/v1/runs":
            started = self.start_run(body.get("limit"))
            return (202, {"status": "started"}) if started else (409, {"error": "an analysis run is already active"})
        if method == "GET" and path == "/v1/runs/latest":
            return 200, {"data": self.last_run}
        if method == "GET" and path == "/v1/settings":
            settings = self.analyzer.settings()
            return 200, {"data": {**settings, "telegram_bot_token": "***" if settings.get("telegram_bot_token") else ""}}
        if method == "GET" and path == "/v1/publications/status":
            return 200, {"data": {str(k): v for k, v in self.store.publication_statuses().items()}}
        if method == "GET" and path == "/v1/publications":
            return 200, {"data": self.store.publications()}
        if method == "POST" and path == "/v1/publications":
            if not isinstance(body.get("product_id"), int):
                return 422, {"error": "product_id (integer) is required"}
            return 201, {"data": self.publisher.create(body["product_id"], body.get("scheduled_for"))}
        if match := re.fullmatch(r"/v1/publications/(\d+)/cancel", path):
            if method == "POST":
                return 200, {"data": self.publisher.cancel(int(match.group(1)))}
        if match := re.fullmatch(r"/v1/products/(\d+)/(analysis|analyze)", path):
            product_id = int(match.group(1))
            if method == "GET" and match.group(2) == "analysis":
                analysis = self.store.latest_analysis(product_id)
                return (200, {"data": analysis}) if analysis else (404, {"error": "not analyzed yet"})
            if method == "POST" and match.group(2) == "analyze":
                product = self.analyzer.pricebuddy.product(product_id)
                if not product:
                    return 404, {"error": "product not found in PriceBuddy"}
                settings = self.analyzer.settings()
                result = self.analyzer.analyze(product, list(self.analyzer.pricebuddy.products()), settings)
                return (200, {"data": result}) if result else (422, {"error": "product has no price"})
        return 404, {"error": "not found"}

    def scheduler(self, stop: threading.Event, tick_seconds: int = 60) -> None:
        last_auto_run = 0.0
        while not stop.wait(tick_seconds):
            try:
                self.publisher.process_due()
                interval = int(self.analyzer.settings().get("auto_run_interval_minutes") or 0)
                if interval > 0 and time.monotonic() - last_auto_run >= interval * 60:
                    if self.start_run():
                        last_auto_run = time.monotonic()
            except Exception:  # noqa: BLE001 - keep the scheduler alive
                logging.exception("Scheduler tick failed")


def make_handler(app: App):
    class Handler(BaseHTTPRequestHandler):
        def _dispatch(self, method: str) -> None:
            body: dict[str, Any] = {}
            if method == "POST" and int(self.headers.get("Content-Length") or 0):
                try:
                    body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
                except json.JSONDecodeError:
                    return self._send(400, {"error": "invalid JSON"})
                if not isinstance(body, dict):
                    return self._send(400, {"error": "expected a JSON object"})
            try:
                status, payload = app.routes(method, self.path.split("?")[0], body)
            except PublicationError as exc:
                status, payload = 422, {"error": str(exc)}
            except Exception as exc:  # noqa: BLE001 - JSON error instead of a dropped connection
                logging.exception("Request failed: %s %s", method, self.path)
                status, payload = 500, {"error": str(exc)[:300]}
            self._send(status, payload)

        def do_GET(self) -> None:  # noqa: N802
            self._dispatch("GET")

        def do_POST(self) -> None:  # noqa: N802
            self._dispatch("POST")

        def _send(self, status: int, payload: Any) -> None:
            data = json.dumps(payload, ensure_ascii=False, default=str).encode()
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)

        def log_message(self, format: str, *args: object) -> None:
            return

    return Handler


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    store = Store(config.DB_PATH)
    analyzer = Analyzer(store)
    app = App(store, analyzer, Publisher(store, analyzer))
    stop = threading.Event()
    threading.Thread(target=app.scheduler, args=(stop,), daemon=True).start()
    ThreadingHTTPServer((config.HOST, config.PORT), make_handler(app)).serve_forever()


if __name__ == "__main__":
    main()

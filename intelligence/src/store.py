"""SQLite storage for this service's own data only: analyses, the daily
market-search budget and publications. Prices and offers stay in PriceBuddy."""

from __future__ import annotations

import json
import sqlite3
import threading
from typing import Any

SCHEMA = """
CREATE TABLE IF NOT EXISTS analyses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    analyzed_at TEXT NOT NULL,
    action TEXT NOT NULL,
    payload TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS analyses_product ON analyses (product_id, analyzed_at);
CREATE TABLE IF NOT EXISTS market_searches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    day TEXT NOT NULL,
    query TEXT NOT NULL,
    status TEXT NOT NULL,
    searched_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS market_searches_day ON market_searches (day);
CREATE TABLE IF NOT EXISTS publications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    channel TEXT NOT NULL,
    status TEXT NOT NULL,            -- scheduled | sent | dry_run | cancelled | failed
    price REAL NOT NULL,
    message TEXT NOT NULL,
    image TEXT,
    scheduled_for TEXT NOT NULL,
    published_at TEXT,
    dry_run INTEGER NOT NULL,
    detail TEXT,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS publications_product ON publications (product_id, status);
"""


class Store:
    def __init__(self, path: str) -> None:
        self._lock = threading.Lock()
        self._db = sqlite3.connect(path, check_same_thread=False)
        self._db.row_factory = sqlite3.Row
        self._db.executescript(SCHEMA)
        # Databases created before publications had an image.
        if "image" not in {row["name"] for row in self._db.execute("PRAGMA table_info(publications)")}:
            self._db.execute("ALTER TABLE publications ADD COLUMN image TEXT")
            self._db.commit()

    def _exec(self, sql: str, params: tuple = ()) -> sqlite3.Cursor:
        with self._lock:
            cursor = self._db.execute(sql, params)
            self._db.commit()
            return cursor

    def _all(self, sql: str, params: tuple = ()) -> list[dict[str, Any]]:
        with self._lock:
            return [dict(row) for row in self._db.execute(sql, params).fetchall()]

    # analyses
    def save_analysis(self, product_id: int, analyzed_at: str, action: str, payload: dict[str, Any]) -> None:
        self._exec("INSERT INTO analyses (product_id, analyzed_at, action, payload) VALUES (?, ?, ?, ?)",
                   (product_id, analyzed_at, action, json.dumps(payload, ensure_ascii=False)))

    def latest_analysis(self, product_id: int) -> dict[str, Any] | None:
        rows = self._all("SELECT payload FROM analyses WHERE product_id = ? ORDER BY id DESC LIMIT 1", (product_id,))
        return json.loads(rows[0]["payload"]) if rows else None

    def latest_analyses_since(self, since: str) -> list[dict[str, Any]]:
        rows = self._all(
            "SELECT payload FROM analyses a WHERE id = (SELECT MAX(id) FROM analyses b WHERE b.product_id = a.product_id)"
            " AND analyzed_at >= ?", (since,))
        return [json.loads(row["payload"]) for row in rows]

    # market search budget
    def count_searches(self, day: str) -> int:
        return self._all("SELECT COUNT(*) AS n FROM market_searches WHERE day = ?", (day,))[0]["n"]

    def record_search(self, day: str, query: str, status: str, searched_at: str) -> None:
        self._exec("INSERT INTO market_searches (day, query, status, searched_at) VALUES (?, ?, ?, ?)",
                   (day, query, status, searched_at))

    # publications
    def add_publication(self, **row: Any) -> int:
        columns = ", ".join(row)
        return self._exec(f"INSERT INTO publications ({columns}) VALUES ({', '.join('?' * len(row))})",
                          tuple(row.values())).lastrowid

    def update_publication(self, publication_id: int, **fields: Any) -> None:
        sets = ", ".join(f"{key} = ?" for key in fields)
        self._exec(f"UPDATE publications SET {sets} WHERE id = ?", (*fields.values(), publication_id))

    def publications(self, limit: int = 100) -> list[dict[str, Any]]:
        return self._all("SELECT * FROM publications ORDER BY id DESC LIMIT ?", (limit,))

    def publication(self, publication_id: int) -> dict[str, Any] | None:
        rows = self._all("SELECT * FROM publications WHERE id = ?", (publication_id,))
        return rows[0] if rows else None

    def due_publications(self, now: str) -> list[dict[str, Any]]:
        return self._all("SELECT * FROM publications WHERE status = 'scheduled' AND scheduled_for <= ? ORDER BY scheduled_for",
                         (now,))

    def last_publication(self, product_id: int) -> dict[str, Any] | None:
        rows = self._all("SELECT * FROM publications WHERE product_id = ? AND status IN ('sent', 'dry_run')"
                         " ORDER BY published_at DESC LIMIT 1", (product_id,))
        return rows[0] if rows else None

    def publication_statuses(self) -> dict[int, dict[str, Any]]:
        """Per product: in_queue when a publication is scheduled, otherwise the
        latest published one (published / published_dry_run)."""
        statuses: dict[int, dict[str, Any]] = {}
        for row in self._all("SELECT product_id, status, published_at, scheduled_for FROM publications"
                             " WHERE status IN ('scheduled', 'sent', 'dry_run') ORDER BY id"):
            if row["status"] == "scheduled":
                statuses[row["product_id"]] = {"status": "in_queue", "at": row["scheduled_for"]}
            elif statuses.get(row["product_id"], {}).get("status") != "in_queue":
                statuses[row["product_id"]] = {
                    "status": "published" if row["status"] == "sent" else "published_dry_run",
                    "at": row["published_at"],
                }
        return statuses

    def pending_publication(self, product_id: int) -> dict[str, Any] | None:
        rows = self._all("SELECT * FROM publications WHERE product_id = ? AND status = 'scheduled' LIMIT 1", (product_id,))
        return rows[0] if rows else None

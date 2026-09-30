"""Кэш ответов в SQLite: повторный прогон того же файла не тратит лимит DaData."""
import json
import sqlite3
import threading
import time


class Cache:
    def __init__(self, path, ttl_days=30):
        self.ttl = ttl_days * 86400
        self.lock = threading.Lock()
        self.db = sqlite3.connect(path, check_same_thread=False)
        self.db.execute(
            "CREATE TABLE IF NOT EXISTS cache (key TEXT PRIMARY KEY, value TEXT, created REAL)"
        )
        self.db.commit()

    def get(self, key):
        with self.lock:
            row = self.db.execute("SELECT value, created FROM cache WHERE key = ?", (key,)).fetchone()
        if not row or time.time() - row[1] > self.ttl:
            return None
        return json.loads(row[0])

    def set(self, key, value):
        with self.lock:
            self.db.execute(
                "REPLACE INTO cache (key, value, created) VALUES (?, ?, ?)",
                (key, json.dumps(value, ensure_ascii=False), time.time()),
            )
            self.db.commit()


class NoCache:
    def get(self, key):
        return None

    def set(self, key, value):
        pass

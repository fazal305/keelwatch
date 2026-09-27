"""Worker liveness heartbeats.

Each worker process upserts its own row on a fixed interval. The API reads
these rows to show which workers are running, stale or stopped. A database
outage never kills the worker: beats back off and resume when it returns.
"""

from __future__ import annotations

import threading
from datetime import UTC, datetime
from typing import Protocol

from pymysql.connections import Connection

from .config import Settings
from .db import connect
from .logs import Logger


class HeartbeatStore(Protocol):
    def beat(self, worker_id: str, pid: int, version: str, started_at: datetime) -> None: ...

    def mark_stopped(self, worker_id: str) -> None: ...


class MySqlHeartbeatStore:
    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        self._conn: Connection | None = None

    def beat(self, worker_id: str, pid: int, version: str, started_at: datetime) -> None:
        self._execute(
            """
            INSERT INTO worker_heartbeats
                (worker_id, pid, version, status, started_at, last_seen_at, stopped_at)
            VALUES (%s, %s, %s, 'running', %s, UTC_TIMESTAMP(3), NULL) AS new
            ON DUPLICATE KEY UPDATE
                pid = new.pid,
                version = new.version,
                status = 'running',
                started_at = new.started_at,
                last_seen_at = new.last_seen_at,
                stopped_at = NULL
            """,
            (worker_id, pid, version, started_at.astimezone(UTC).replace(tzinfo=None)),
        )

    def mark_stopped(self, worker_id: str) -> None:
        self._execute(
            """
            UPDATE worker_heartbeats
            SET status = 'stopped', last_seen_at = UTC_TIMESTAMP(3), stopped_at = UTC_TIMESTAMP(3)
            WHERE worker_id = %s
            """,
            (worker_id,),
        )

    def close(self) -> None:
        if self._conn is not None:
            try:
                self._conn.close()
            finally:
                self._conn = None

    def _execute(self, sql: str, params: tuple[object, ...]) -> None:
        if self._conn is None:
            self._conn = connect(self._settings)
        try:
            with self._conn.cursor() as cursor:
                cursor.execute(sql, params)
        except Exception:
            # Drop the connection so the next attempt reconnects cleanly.
            self.close()
            raise


class HeartbeatLoop:
    def __init__(
        self,
        store: HeartbeatStore,
        settings: Settings,
        logger: Logger,
        stop: threading.Event,
        pid: int,
        max_backoff_s: float = 60.0,
    ) -> None:
        self._store = store
        self._settings = settings
        self._logger = logger
        self._stop = stop
        self._pid = pid
        self._max_backoff_s = max_backoff_s
        self._started_at = datetime.now(UTC)
        self.beats = 0
        self.failures = 0

    def run(self) -> None:
        consecutive_failures = 0

        while not self._stop.is_set():
            try:
                self._store.beat(
                    self._settings.worker_id,
                    self._pid,
                    self._settings.app_version,
                    self._started_at,
                )
                self.beats += 1
                if consecutive_failures:
                    self._logger.info("heartbeat recovered", after_failures=consecutive_failures)
                elif self.beats == 1:
                    self._logger.info("heartbeat registered", worker_id=self._settings.worker_id)
                consecutive_failures = 0
                wait = float(self._settings.heartbeat_interval_s)
            except Exception as exc:
                consecutive_failures += 1
                self.failures += 1
                wait = self.backoff(consecutive_failures)
                self._logger.warning(
                    "heartbeat failed",
                    exception=type(exc).__name__,
                    attempt=consecutive_failures,
                    retry_in_s=wait,
                )
            self._stop.wait(wait)

        try:
            self._store.mark_stopped(self._settings.worker_id)
        except Exception as exc:
            self._logger.warning("could not record worker stop", exception=type(exc).__name__)

    def backoff(self, consecutive_failures: int) -> float:
        base = float(self._settings.heartbeat_interval_s)
        return min(base * (2 ** (consecutive_failures - 1)), self._max_backoff_s)

"""Digest phase, notification and daily-digest job handlers, and the
daily-digest scheduler."""

from __future__ import annotations

import json
import re
import time
from collections.abc import Callable
from dataclasses import dataclass
from datetime import UTC, datetime, timedelta
from typing import Any

from pymysql.connections import Connection

from ..budget import Budget
from ..logs import Logger
from ..pipeline import RunContext
from ..queue import Job, PermanentError
from .crypto import DecryptError, open_sealed
from .destinations import InvalidDestination, Sender
from .digests import (
    build_daily_digest,
    build_run_digest,
    day_bounds,
    enqueue_notifications,
    store_digest,
)
from .format import render_daily, render_run


@dataclass(frozen=True)
class NotificationConfig:
    key: bytes | None  # None: notifications are off (no NOTIFICATION_KEY)
    dashboard_url: str | None
    timeout_s: float = 10.0
    daily_hour_utc: int = 6

    @property
    def enabled(self) -> bool:
        return self.key is not None

    def __repr__(self) -> str:  # never render the key
        return f"NotificationConfig(enabled={self.enabled}, dashboard_url={self.dashboard_url!r})"


class NotificationRetry(Exception):
    """Temporary delivery failure; the job queue retries with backoff."""


def _one(conn: Connection, sql: str, params: tuple) -> dict[str, Any] | None:
    with conn.cursor() as cur:
        cur.execute(sql, params)
        return cur.fetchone()


def _json(value: Any) -> Any:
    return json.loads(value) if isinstance(value, str | bytes) else value


# ----- pipeline phase ---------------------------------------------------------------


def make_digest_phase(config: NotificationConfig) -> Callable[[RunContext, Budget], dict[str, Any]]:
    def digest_phase(ctx: RunContext, budget: Budget) -> dict[str, Any]:
        content = build_run_digest(ctx.conn, ctx.run["id"])
        repo = _one(
            ctx.conn,
            "SELECT installation_id FROM repositories WHERE id = %s",
            (ctx.repository["id"],),
        )
        now = datetime.now(UTC)
        digest_id = store_digest(
            ctx.conn,
            installation_id=repo["installation_id"],
            repository_id=ctx.repository["id"],
            kind="run",
            key=f"run:{ctx.run['id']}",
            period_start=ctx.run["created_at"] if ctx.run.get("created_at") else now,
            period_end=now,
            content=content,
            run_ids=[ctx.run["id"]],
        )
        queued = 0
        if config.enabled:
            queued = enqueue_notifications(
                ctx.conn,
                digest_id=digest_id,
                installation_id=repo["installation_id"],
                by_severity=content["findings"]["by_severity"],
                correlation_id=ctx.run["correlation_id"],
            )
        return {
            "digest_id": digest_id,
            "notifications_queued": queued,
            "notifications": "enabled" if config.enabled else "disabled (no NOTIFICATION_KEY)",
        }

    return digest_phase


# ----- notification job ---------------------------------------------------------------


def validate_notify_payload(payload: Any) -> dict[str, int]:
    if not isinstance(payload, dict) or set(payload) != {
        "schema_version",
        "destination_id",
        "digest_id",
    }:
        raise PermanentError("notify payload must have schema_version, destination_id, digest_id")
    if payload["schema_version"] != 1:
        raise PermanentError("unsupported notify schema_version")
    for key in ("destination_id", "digest_id"):
        v = payload[key]
        if not isinstance(v, int) or isinstance(v, bool) or v < 1:
            raise PermanentError(f"{key} must be a positive integer")
    return payload


def handle_notify(
    conn: Connection,
    job: Job,
    keep_lease: Callable[[], None],
    *,
    config: NotificationConfig,
    sender: Sender,
    logger: Logger,
) -> dict[str, Any]:
    p = validate_notify_payload(job.payload)
    dest = _one(
        conn,
        "SELECT id, kind, url_ciphertext, url_host, enabled FROM notification_destinations WHERE id = %s",
        (p["destination_id"],),
    )
    if dest is None or not dest["enabled"]:
        return {"outcome": "skipped", "reason": "destination removed or disabled"}
    digest = _one(conn, "SELECT kind, content FROM digests WHERE id = %s", (p["digest_id"],))
    if digest is None:
        raise PermanentError(f"digest {p['digest_id']} does not exist")

    previous = _one(
        conn,
        "SELECT COALESCE(MAX(attempt), 0) AS attempts, COALESCE(SUM(status = 'sent'), 0) AS sent "
        "FROM notification_deliveries WHERE destination_id = %s AND digest_id = %s",
        (dest["id"], p["digest_id"]),
    )
    if int(previous["sent"]):
        # Redelivered job after a send that was already recorded: don't post twice.
        return {"outcome": "already_sent"}
    attempt = int(previous["attempts"]) + 1

    def record(status: str, http_status: int | None, error: str | None) -> None:
        with conn.cursor() as cur:
            cur.execute(
                "INSERT INTO notification_deliveries (destination_id, digest_id, attempt, status, http_status, error) "
                "VALUES (%s, %s, %s, %s, %s, %s)",
                (
                    dest["id"],
                    p["digest_id"],
                    attempt,
                    status,
                    http_status,
                    (error or "")[:500] or None,
                ),
            )

    if config.key is None:
        record("failed", None, "NOTIFICATION_KEY is not configured")
        raise PermanentError("NOTIFICATION_KEY is not configured")

    content = _json(digest["content"])
    text = (
        render_run(content, config.dashboard_url)
        if digest["kind"] == "run"
        else render_daily(content, config.dashboard_url)
    )

    try:
        url = open_sealed(bytes(dest["url_ciphertext"]), config.key)
        result = sender.send(dest["kind"], url, text, config.timeout_s)
    except DecryptError as exc:
        record("failed", None, "stored URL could not be decrypted (wrong NOTIFICATION_KEY?)")
        raise PermanentError(str(exc)) from exc
    except InvalidDestination as exc:
        record("failed", None, str(exc))
        raise PermanentError(str(exc)) from exc

    record(
        result.status if result.status != "retry" else "failed", result.http_status, result.error
    )
    log = logger.with_correlation_id(job.correlation_id)
    if result.status == "retry":
        log.warning(
            "notification will be retried",
            destination_id=dest["id"],
            http_status=result.http_status,
        )
        raise NotificationRetry(result.error or "temporary failure")
    if result.status == "failed":
        raise PermanentError(f"destination rejected the message ({result.error})")
    return {
        "outcome": "sent",
        "destination_id": dest["id"],
        "digest_id": p["digest_id"],
        "delivery_attempt": attempt,
    }


# ----- daily digest --------------------------------------------------------------------

_DATE = re.compile(r"\d{4}-\d{2}-\d{2}")


def handle_daily_digest(
    conn: Connection, job: Job, *, config: NotificationConfig
) -> dict[str, Any]:
    p = job.payload
    if (
        not isinstance(p, dict)
        or set(p) != {"schema_version", "installation_id", "date"}
        or p["schema_version"] != 1
        or not isinstance(p["installation_id"], int)
        or not isinstance(p["date"], str)
        or not _DATE.fullmatch(p["date"])
    ):
        raise PermanentError("daily digest payload is malformed")

    content = build_daily_digest(conn, p["installation_id"], p["date"])
    run_ids = content.pop("run_ids")
    if not run_ids:
        return {"outcome": "skipped", "reason": "no finished runs that day"}
    start, end = day_bounds(p["date"])
    digest_id = store_digest(
        conn,
        installation_id=p["installation_id"],
        repository_id=None,
        kind="daily",
        key=f"daily:{p['installation_id']}:{p['date']}",
        period_start=start,
        period_end=end,
        content=content,
        run_ids=run_ids,
    )
    queued = (
        enqueue_notifications(
            conn,
            digest_id=digest_id,
            installation_id=p["installation_id"],
            by_severity=None,
            correlation_id=job.correlation_id,
        )
        if config.enabled
        else 0
    )
    return {
        "outcome": "stored",
        "digest_id": digest_id,
        "runs": len(run_ids),
        "notifications_queued": queued,
    }


class DailyDigestScheduler:
    """Queues yesterday's daily digest for each installation that had finished
    runs, once the configured UTC hour has passed. Idempotency keys make it
    safe for several workers to tick at once."""

    CHECK_EVERY_S = 60.0

    def __init__(self, config: NotificationConfig, clock: Callable[[], float] = time.time) -> None:
        self._config = config
        self._clock = clock
        self._last = float("-inf")

    def tick(self, conn: Connection) -> int:
        now = self._clock()
        if now - self._last < self.CHECK_EVERY_S:
            return 0
        self._last = now
        current = datetime.fromtimestamp(now, UTC)
        if current.hour < self._config.daily_hour_utc:
            return 0
        day = (current - timedelta(days=1)).strftime("%Y-%m-%d")
        start, end = day_bounds(day)
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT DISTINCT repo.installation_id
                FROM analysis_runs r JOIN repositories repo ON repo.id = r.repository_id
                WHERE r.finished_at >= %s AND r.finished_at < %s
                """,
                (start.replace(tzinfo=None), end.replace(tzinfo=None)),
            )
            installations = [row["installation_id"] for row in cur.fetchall()]
            queued = 0
            for installation_id in installations:
                payload = {"schema_version": 1, "installation_id": installation_id, "date": day}
                cur.execute(
                    "INSERT IGNORE INTO jobs (queue, type, payload, idempotency_key, correlation_id) "
                    "VALUES ('scheduled', 'daily_digest', %s, %s, %s)",
                    (
                        json.dumps(payload),
                        f"daily:{installation_id}:{day}",
                        f"daily-{installation_id}-{day}",
                    ),
                )
                queued += cur.rowcount
        return queued

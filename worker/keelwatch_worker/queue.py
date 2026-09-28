"""MySQL-backed job queue (ADR 0001).

Claiming uses SELECT ... FOR UPDATE SKIP LOCKED inside a transaction, so
concurrent workers never receive the same job. A claimed job carries a
lease (locked_until); if its worker dies, the lease expires and
recover_expired() puts the job back or dead-letters it.

Delivery is at-least-once. Handlers get exactly-once *effects within this
database* by writing their results and calling complete() in the same
transaction (see runner.JobRunner).
"""

from __future__ import annotations

import json
import random
from dataclasses import dataclass
from typing import Any

from pymysql.connections import Connection

MAX_ERROR_LENGTH = 1000


class PermanentError(Exception):
    """The job can never succeed (bad payload, missing row): dead-letter it now."""


@dataclass(frozen=True)
class Job:
    id: int
    queue: str
    type: str
    payload: dict[str, Any]
    attempts: int  # including the current attempt
    max_attempts: int
    correlation_id: str


def retry_delay_s(
    attempt: int, base_s: float, max_s: float, rng: random.Random | None = None
) -> float:
    """Exponential backoff with jitter: base * 2^(attempt-1), capped at max_s,
    then scaled into [50%, 100%] so retries from many jobs don't align."""
    ceiling = min(max_s, base_s * (2 ** max(0, attempt - 1)))
    return (rng or random).uniform(ceiling / 2, ceiling)


def describe_error(exc: BaseException) -> str:
    return f"{type(exc).__name__}: {exc}"[:MAX_ERROR_LENGTH]


class JobQueue:
    def __init__(self, conn: Connection, worker_id: str, lease_s: int) -> None:
        self._conn = conn
        self._worker_id = worker_id
        self._lease_s = lease_s

    def claim(self, queue: str) -> Job | None:
        conn = self._conn
        conn.begin()
        try:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    SELECT id, queue, type, payload, attempts, max_attempts, correlation_id
                    FROM jobs
                    WHERE queue = %s AND status = 'queued' AND run_after <= UTC_TIMESTAMP(3)
                    ORDER BY priority DESC, id
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
                    """,
                    (queue,),
                )
                row = cur.fetchone()
                if row is None:
                    conn.commit()
                    return None
                cur.execute(
                    """
                    UPDATE jobs
                    SET status = 'running', attempts = attempts + 1,
                        locked_by = %s, locked_until = UTC_TIMESTAMP(3) + INTERVAL %s SECOND,
                        updated_at = UTC_TIMESTAMP(3)
                    WHERE id = %s
                    """,
                    (self._worker_id, self._lease_s, row["id"]),
                )
            conn.commit()
        except BaseException:
            conn.rollback()
            raise

        payload = row["payload"]
        return Job(
            id=row["id"],
            queue=row["queue"],
            type=row["type"],
            payload=json.loads(payload) if isinstance(payload, str | bytes) else payload,
            attempts=row["attempts"] + 1,
            max_attempts=row["max_attempts"],
            correlation_id=row["correlation_id"],
        )

    def complete(self, job: Job) -> bool:
        """Mark succeeded. Call inside the handler's transaction. Returns False
        if this worker no longer holds the lease (the caller must roll back)."""
        with self._conn.cursor() as cur:
            cur.execute(
                """
                UPDATE jobs
                SET status = 'succeeded', locked_by = NULL, locked_until = NULL,
                    finished_at = UTC_TIMESTAMP(3), updated_at = UTC_TIMESTAMP(3)
                WHERE id = %s AND status = 'running' AND locked_by = %s
                """,
                (job.id, self._worker_id),
            )
            return cur.rowcount == 1

    def extend_lease(self, job: Job) -> bool:
        """Push locked_until forward for a long job. False means the lease was
        already lost (expired and reclaimed), so the caller must stop."""
        with self._conn.cursor() as cur:
            cur.execute(
                """
                UPDATE jobs
                SET locked_until = UTC_TIMESTAMP(3) + INTERVAL %s SECOND,
                    updated_at = UTC_TIMESTAMP(3)
                WHERE id = %s AND status = 'running' AND locked_by = %s
                """,
                (self._lease_s, job.id, self._worker_id),
            )
            return cur.rowcount == 1

    def fail(self, job: Job, error: str, retry_in_s: float | None) -> str:
        """Record a failed attempt. retry_in_s=None means dead-letter now.
        Returns the new status ('queued', 'dead') or 'lost' if the lease was gone."""
        retry = retry_in_s is not None and job.attempts < job.max_attempts
        with self._conn.cursor() as cur:
            if retry:
                cur.execute(
                    """
                    UPDATE jobs
                    SET status = 'queued', locked_by = NULL, locked_until = NULL,
                        run_after = UTC_TIMESTAMP(3) + INTERVAL %s MICROSECOND,
                        last_error = %s, updated_at = UTC_TIMESTAMP(3)
                    WHERE id = %s AND status = 'running' AND locked_by = %s
                    """,
                    (
                        int(retry_in_s * 1_000_000),
                        error[:MAX_ERROR_LENGTH],
                        job.id,
                        self._worker_id,
                    ),
                )
            else:
                cur.execute(
                    """
                    UPDATE jobs
                    SET status = 'dead', locked_by = NULL, locked_until = NULL,
                        finished_at = UTC_TIMESTAMP(3), last_error = %s,
                        updated_at = UTC_TIMESTAMP(3)
                    WHERE id = %s AND status = 'running' AND locked_by = %s
                    """,
                    (error[:MAX_ERROR_LENGTH], job.id, self._worker_id),
                )
            if cur.rowcount != 1:
                return "lost"
        return "queued" if retry else "dead"

    def recover_expired(self) -> dict[str, int]:
        """Requeue jobs whose lease expired (worker crashed or stalled), or
        dead-letter them if they have used every attempt."""
        with self._conn.cursor() as cur:
            cur.execute(
                """
                UPDATE jobs
                SET status = 'dead', locked_by = NULL, locked_until = NULL,
                    finished_at = UTC_TIMESTAMP(3), updated_at = UTC_TIMESTAMP(3),
                    last_error = 'lease expired on the final attempt (worker crashed or stalled)'
                WHERE status = 'running' AND locked_until < UTC_TIMESTAMP(3)
                  AND attempts >= max_attempts
                """
            )
            dead = cur.rowcount
            cur.execute(
                """
                UPDATE jobs
                SET status = 'queued', locked_by = NULL, locked_until = NULL,
                    run_after = UTC_TIMESTAMP(3), updated_at = UTC_TIMESTAMP(3),
                    last_error = 'lease expired (worker crashed or stalled); requeued'
                WHERE status = 'running' AND locked_until < UTC_TIMESTAMP(3)
                """
            )
            requeued = cur.rowcount
        return {"requeued": requeued, "dead": dead}

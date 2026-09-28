"""The job loop: recover expired leases, claim, process, acknowledge.

A handler's writes and the job's completion commit in one transaction, so
a crash between them is impossible: either both happen or neither does,
and the lease-expiry path retries the job.
"""

from __future__ import annotations

import contextlib
import random
import threading
import time
from collections.abc import Callable
from typing import Any

from pymysql.connections import Connection

from .config import Settings
from .events import handle_github_event
from .logs import Logger
from .queue import Job, JobQueue, PermanentError, describe_error, retry_delay_s

Handler = Callable[[Connection, Job], dict[str, Any]]


class LeaseLost(Exception):
    """Another worker reclaimed the job while this one was processing it."""


class JobRunner:
    RECOVER_EVERY_S = 30.0

    def __init__(
        self,
        connect: Callable[[], Connection],
        settings: Settings,
        logger: Logger,
        stop: threading.Event,
        queues: tuple[str, ...] = ("events",),
        handlers: dict[str, Handler] | None = None,
        rng: random.Random | None = None,
    ) -> None:
        self._connect = connect
        self._settings = settings
        self._logger = logger
        self._stop = stop
        self._queues = queues
        # Retry jitter only; not used for anything security-sensitive.
        self._rng = rng or random.Random()  # noqa: S311
        self._handlers: dict[str, Handler] = handlers or {
            "github_event": lambda conn, job: handle_github_event(
                conn, job, settings.analysis_budget_ms
            ),
        }
        self._conn: Connection | None = None
        self._last_recovery = 0.0
        self.processed = 0

    def run(self) -> None:
        backoff = 0
        while not self._stop.is_set():
            try:
                worked = self.run_once()
                backoff = 0
            except Exception as exc:
                # Database unavailable or similar: drop the connection and back off.
                self._close()
                backoff += 1
                wait = retry_delay_s(backoff, self._settings.poll_interval_s, 60, self._rng)
                self._logger.warning(
                    "job loop error", exception=type(exc).__name__, retry_in_s=round(wait, 1)
                )
                self._stop.wait(wait)
                continue
            if not worked:
                self._stop.wait(self._settings.poll_interval_s)
        self._close()

    def run_once(self) -> bool:
        """Claims and processes at most one job. Returns True if it did work."""
        queue = self._queue()

        if time.monotonic() - self._last_recovery >= self.RECOVER_EVERY_S:
            recovered = queue.recover_expired()
            self._last_recovery = time.monotonic()
            if recovered["requeued"] or recovered["dead"]:
                self._logger.warning("recovered expired job leases", **recovered)

        for name in self._queues:
            job = queue.claim(name)
            if job is not None:
                self._process(queue, job)
                self.processed += 1
                return True
        return False

    def _process(self, queue: JobQueue, job: Job) -> None:
        log = self._logger.with_correlation_id(job.correlation_id)
        conn = self._conn
        if conn is None:
            raise RuntimeError("job claimed without an open connection")
        started = time.perf_counter()

        handler = self._handlers.get(job.type)
        try:
            if handler is None:
                raise PermanentError(f"no handler for job type {job.type!r}")
            conn.begin()
            result = handler(conn, job)
            if not queue.complete(job):
                raise LeaseLost(f"lease on job {job.id} was lost before completion")
            conn.commit()
        except Exception as exc:
            conn.rollback()
            self._record_failure(queue, job, exc, log)
            return

        log.info(
            "job succeeded",
            job_id=job.id,
            type=job.type,
            attempt=job.attempts,
            duration_ms=round((time.perf_counter() - started) * 1000, 1),
            **result,
        )

    def _record_failure(self, queue: JobQueue, job: Job, exc: Exception, log: Logger) -> None:
        if isinstance(exc, LeaseLost):
            # The job belongs to someone else now; nothing of ours was committed.
            log.warning("job lease lost; work rolled back", job_id=job.id)
            return

        permanent = isinstance(exc, PermanentError)
        retry_in = (
            None
            if permanent
            else retry_delay_s(
                job.attempts, self._settings.retry_base_s, self._settings.retry_max_s, self._rng
            )
        )
        status = queue.fail(job, describe_error(exc), retry_in)
        log.warning(
            "job failed",
            job_id=job.id,
            type=job.type,
            attempt=job.attempts,
            max_attempts=job.max_attempts,
            exception=type(exc).__name__,
            permanent=permanent,
            new_status=status,
            retry_in_s=None if status != "queued" or retry_in is None else round(retry_in, 1),
        )

    def _queue(self) -> JobQueue:
        if self._conn is None:
            self._conn = self._connect()
        return JobQueue(self._conn, self._settings.worker_id, self._settings.job_lease_s)

    def _close(self) -> None:
        if self._conn is not None:
            # Closing a possibly broken connection is best-effort.
            with contextlib.suppress(Exception):
                self._conn.close()
            self._conn = None

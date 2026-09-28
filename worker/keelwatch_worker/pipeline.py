"""Stateful, resumable analysis pipeline.

Each phase runs under a child budget and writes an analysis_checkpoints row
that is committed immediately (autocommit), so progress survives a crash.
On resume, phases whose latest checkpoint is completed or skipped are not
run again; their saved state is loaded instead.

Run outcomes:
  completed     every phase completed or was skipped
  checkpointed  the budget ran out; failure_reason = budget_exhausted:<phase>
  failed        a phase failed permanently; failure_reason = <phase>: <error>
Infrastructure errors (the database going away) are re-raised so the job
queue retries the job, which resumes from the last checkpoint.
"""

from __future__ import annotations

import json
import time
from collections.abc import Callable
from dataclasses import dataclass, field
from typing import Any

import pymysql
from pymysql.connections import Connection

from .budget import Budget, BudgetExceeded
from .llm.router import CallRecord, Router
from .logs import Logger
from .queue import describe_error

MAX_STATE_BYTES = 256 * 1024


class PhaseFailed(Exception):
    """The phase cannot succeed for this run; the run is marked failed."""


class PhaseSkipped(Exception):
    """The phase does not apply to this run (e.g. no LLM configured)."""


@dataclass
class RunContext:
    conn: Connection
    run: dict[str, Any]
    repository: dict[str, Any]
    envelope: dict[str, Any] | None
    state: dict[str, dict[str, Any]]
    logger: Logger
    router: Router | None = None
    record_call: Callable[[CallRecord], None] = field(default=lambda _r: None)


@dataclass(frozen=True)
class Phase:
    name: str
    cap_ms: int
    run: Callable[[RunContext, Budget], dict[str, Any]]


@dataclass(frozen=True)
class RunResult:
    status: str
    reason: str | None = None
    phases_run: tuple[str, ...] = ()
    phases_resumed: tuple[str, ...] = ()


class PipelineEngine:
    def __init__(
        self,
        phases: list[Phase],
        *,
        reserve_ms: int = 2000,
        clock: Callable[[], float] = time.perf_counter,
    ) -> None:
        names = [p.name for p in phases]
        if len(set(names)) != len(names):
            raise ValueError("phase names must be unique")
        self.phases = phases
        self._reserve_ms = reserve_ms
        self._clock = clock

    def execute(
        self,
        conn: Connection,
        run_id: int,
        budget_ms: int,
        logger: Logger,
        *,
        router: Router | None = None,
        keep_lease: Callable[[], None] = lambda: None,
    ) -> RunResult:
        loaded = self._load(conn, run_id)
        if loaded is None:
            raise PhaseFailed(f"analysis run {run_id} does not exist")
        run, repository, envelope = loaded
        if run["status"] in ("completed", "cancelled"):
            return RunResult("skipped", f"run is already {run['status']}")

        budget = Budget(budget_ms, reserve_ms=self._reserve_ms, clock=self._clock)
        self._exec(
            conn,
            """
            UPDATE analysis_runs
            SET status = 'running', failure_reason = NULL, current_phase = NULL,
                started_at = COALESCE(started_at, UTC_TIMESTAMP(3)),
                deadline_at = UTC_TIMESTAMP(3) + INTERVAL %s MICROSECOND,
                updated_at = UTC_TIMESTAMP(3)
            WHERE id = %s
            """,
            (budget_ms * 1000, run_id),
        )

        done, attempts = self._checkpoints(conn, run_id)
        state = dict(done)
        ctx = RunContext(conn, run, repository, envelope, state, logger, router)
        ran: list[str] = []

        for phase in self.phases:
            if phase.name in done:
                continue
            keep_lease()

            attempt = attempts.get(phase.name, 0) + 1
            try:
                phase_budget = budget.child(phase.cap_ms)
            except BudgetExceeded:
                return self._finish(
                    conn, run_id, "checkpointed", f"budget_exhausted:{phase.name}", ran, done
                )

            checkpoint_id = self._start_checkpoint(conn, run_id, phase.name, attempt)
            ctx.record_call = self._recorder(conn, run_id, phase.name)
            started = time.perf_counter()
            try:
                result = phase.run(ctx, phase_budget)
                status, saved = "completed", result
            except PhaseSkipped as exc:
                status, saved = "skipped", {"reason": str(exc)}
            except BudgetExceeded as exc:
                self._fail_checkpoint(conn, checkpoint_id, started, f"budget exhausted: {exc}")
                return self._finish(
                    conn, run_id, "checkpointed", f"budget_exhausted:{phase.name}", ran, done
                )
            except PhaseFailed as exc:
                self._fail_checkpoint(conn, checkpoint_id, started, str(exc))
                return self._finish(
                    conn, run_id, "failed", f"{phase.name}: {exc}"[:1000], ran, done
                )
            except pymysql.err.Error:
                raise  # infrastructure: let the job retry and resume
            except Exception as exc:
                # A bug in a phase: record it and fail the run rather than retry forever.
                logger.error("phase crashed", phase=phase.name, exception=type(exc).__name__)
                self._fail_checkpoint(conn, checkpoint_id, started, describe_error(exc))
                return self._finish(
                    conn, run_id, "failed", f"{phase.name}: {describe_error(exc)}"[:1000], ran, done
                )

            encoded = json.dumps(saved, default=str)
            if len(encoded) > MAX_STATE_BYTES:
                self._fail_checkpoint(conn, checkpoint_id, started, "phase state too large")
                return self._finish(
                    conn, run_id, "failed", f"{phase.name}: state too large", ran, done
                )

            self._exec(
                conn,
                """
                UPDATE analysis_checkpoints
                SET status = %s, state = %s, finished_at = UTC_TIMESTAMP(3), duration_ms = %s
                WHERE id = %s
                """,
                (status, encoded, int((time.perf_counter() - started) * 1000), checkpoint_id),
            )
            state[phase.name] = saved
            ran.append(phase.name)
            logger.info(
                "phase finished",
                run_id=run_id,
                phase=phase.name,
                status=status,
                duration_ms=round((time.perf_counter() - started) * 1000, 1),
                budget_left_ms=round(budget.remaining_ms()),
            )

        return self._finish(conn, run_id, "completed", None, ran, done)

    # ----- persistence helpers ------------------------------------------------

    @staticmethod
    def _exec(conn: Connection, sql: str, params: tuple[Any, ...]) -> int:
        with conn.cursor() as cur:
            cur.execute(sql, params)
            return cur.lastrowid

    def _load(self, conn: Connection, run_id: int):
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT r.*, repo.full_name, repo.is_private, repo.llm_policy,
                       repo.default_branch, e.envelope
                FROM analysis_runs r
                JOIN repositories repo ON repo.id = r.repository_id
                LEFT JOIN repository_events e ON e.id = r.event_id
                WHERE r.id = %s
                """,
                (run_id,),
            )
            row = cur.fetchone()
        if row is None:
            return None
        repository = {
            "id": row["repository_id"],
            "full_name": row["full_name"],
            "is_private": bool(row["is_private"]),
            "llm_policy": row["llm_policy"],
            "default_branch": row["default_branch"],
        }
        raw = row.pop("envelope")
        envelope = json.loads(raw) if isinstance(raw, str | bytes) else raw
        return row, repository, envelope

    def _checkpoints(self, conn: Connection, run_id: int):
        with conn.cursor() as cur:
            cur.execute(
                "SELECT phase, attempt, status, state FROM analysis_checkpoints "
                "WHERE run_id = %s ORDER BY phase, attempt",
                (run_id,),
            )
            rows = cur.fetchall()
        attempts: dict[str, int] = {}
        latest: dict[str, dict[str, Any]] = {}
        for r in rows:
            attempts[r["phase"]] = max(attempts.get(r["phase"], 0), r["attempt"])
            latest[r["phase"]] = r
        done = {
            name: (
                json.loads(r["state"])
                if isinstance(r["state"], str | bytes)
                else (r["state"] or {})
            )
            for name, r in latest.items()
            if r["status"] in ("completed", "skipped")
        }
        return done, attempts

    def _start_checkpoint(self, conn: Connection, run_id: int, phase: str, attempt: int) -> int:
        checkpoint_id = self._exec(
            conn,
            "INSERT INTO analysis_checkpoints (run_id, phase, attempt, status) "
            "VALUES (%s, %s, %s, 'running')",
            (run_id, phase, attempt),
        )
        self._exec(
            conn,
            "UPDATE analysis_runs SET current_phase = %s, updated_at = UTC_TIMESTAMP(3) "
            "WHERE id = %s",
            (phase, run_id),
        )
        return checkpoint_id

    def _fail_checkpoint(
        self, conn: Connection, checkpoint_id: int, started: float, error: str
    ) -> None:
        self._exec(
            conn,
            """
            UPDATE analysis_checkpoints
            SET status = 'failed', error = %s, finished_at = UTC_TIMESTAMP(3), duration_ms = %s
            WHERE id = %s
            """,
            (error[:1000], int((time.perf_counter() - started) * 1000), checkpoint_id),
        )

    def _finish(self, conn, run_id, status, reason, ran, resumed) -> RunResult:
        self._exec(
            conn,
            """
            UPDATE analysis_runs
            SET status = %s, failure_reason = %s,
                current_phase = CASE WHEN %s = 'completed' THEN NULL ELSE current_phase END,
                finished_at = CASE WHEN %s IN ('completed', 'failed')
                                   THEN UTC_TIMESTAMP(3) ELSE NULL END,
                updated_at = UTC_TIMESTAMP(3)
            WHERE id = %s
            """,
            (status, reason, status, status, run_id),
        )
        return RunResult(status, reason, tuple(ran), tuple(resumed))

    def _recorder(self, conn: Connection, run_id: int, phase: str) -> Callable[[CallRecord], None]:
        def record(call: CallRecord) -> None:
            self._exec(
                conn,
                """
                INSERT INTO provider_calls
                    (run_id, phase, provider, model, outcome, latency_ms,
                     tokens_in, tokens_out, retry_no, error)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                """,
                (
                    run_id,
                    phase,
                    call.provider,
                    call.model,
                    call.outcome,
                    int(call.latency_ms),
                    call.tokens_in,
                    call.tokens_out,
                    call.retry_no,
                    call.error,
                ),
            )

        return record

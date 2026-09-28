"""Integration: the phase engine, checkpoints, budgets, resume and the LLM
review phase against real MySQL (DB_TEST_NAME). A fake clock makes budget
exhaustion deterministic."""

import json
import threading

import pytest
from mysql_support import rows, runner, scalar, seed_event

from keelwatch_worker.analysis import (
    PRODUCTION_PHASES,
    ResumeRefused,
    llm_review,
    request_resume,
)
from keelwatch_worker.budget import Budget
from keelwatch_worker.llm.base import ProviderResult
from keelwatch_worker.llm.router import Router
from keelwatch_worker.logs import Logger
from keelwatch_worker.main import build_handlers
from keelwatch_worker.pipeline import Phase, PhaseFailed, PhaseSkipped, PipelineEngine
from keelwatch_worker.runner import LeaseLost

pytestmark = pytest.mark.integration


class FakeClock:
    def __init__(self) -> None:
        self.now = 0.0

    def __call__(self) -> float:
        return self.now

    def advance_ms(self, ms: float) -> None:
        self.now += ms / 1000


def seed_run(conn, *, private=False, llm_policy="public_only"):
    seeded = seed_event(conn)
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE repositories SET is_private = %s, llm_policy = %s WHERE id = %s",
            (int(private), llm_policy, seeded["repo_id"]),
        )
        cur.execute(
            "INSERT INTO analysis_runs (repository_id, event_id, trigger_type, idempotency_key, "
            "status, head_sha, budget_ms, correlation_id) "
            "VALUES (%s, %s, 'webhook', %s, 'queued', %s, 60000, 'corr-run-00001')",
            (seeded["repo_id"], seeded["event_id"], f"test:{seeded['event_id']}", "b" * 40),
        )
        return cur.lastrowid


def run_row(conn, run_id):
    return rows(conn, "SELECT * FROM analysis_runs WHERE id = %s", (run_id,))[0]


def checkpoints(conn, run_id):
    return [
        (r["phase"], r["attempt"], r["status"])
        for r in rows(
            conn,
            "SELECT phase, attempt, status FROM analysis_checkpoints WHERE run_id = %s ORDER BY id",
            (run_id,),
        )
    ]


class Recorder:
    """Phases that count their calls and optionally spend fake time."""

    def __init__(self, clock: FakeClock) -> None:
        self.clock = clock
        self.calls: dict[str, int] = {}

    def phase(self, name, *, spend_ms=0, needs_ms=0, result=None, error=None):
        def run(ctx, budget: Budget):
            self.calls[name] = self.calls.get(name, 0) + 1
            if needs_ms:
                budget.require(needs_ms, name)
            self.clock.advance_ms(spend_ms)
            if error is not None:
                raise error
            return result if result is not None else {name: True}

        return Phase(name, cap_ms=60_000, run=run)


LOG = Logger("worker")


# ----- the engine ---------------------------------------------------------------------


def test_all_phases_complete_with_saved_checkpoints(conn):
    run_id = seed_run(conn)
    rec = Recorder(FakeClock())
    engine = PipelineEngine([rec.phase("a"), rec.phase("b")], reserve_ms=0, clock=rec.clock)

    result = engine.execute(conn, run_id, 10_000, LOG)

    assert result.status == "completed"
    assert checkpoints(conn, run_id) == [("a", 1, "completed"), ("b", 1, "completed")]
    row = run_row(conn, run_id)
    assert (row["status"], row["current_phase"], row["failure_reason"]) == ("completed", None, None)
    assert row["started_at"] is not None and row["finished_at"] is not None
    state = scalar(conn, "SELECT state FROM analysis_checkpoints WHERE phase = 'b'")
    assert json.loads(state) == {"b": True}


def test_budget_exhaustion_checkpoints_and_resume_skips_completed_phases(conn):
    run_id = seed_run(conn)
    rec = Recorder(FakeClock())
    phases = [rec.phase("a", spend_ms=1000), rec.phase("b", needs_ms=5000), rec.phase("c")]
    engine = PipelineEngine(phases, reserve_ms=500, clock=rec.clock)

    first = engine.execute(conn, run_id, 5000, LOG)

    assert (first.status, first.reason) == ("checkpointed", "budget_exhausted:b")
    assert checkpoints(conn, run_id) == [("a", 1, "completed"), ("b", 1, "failed")]
    assert run_row(conn, run_id)["finished_at"] is None, "a checkpointed run is not finished"

    request_resume(conn, run_id, 60_000)
    assert run_row(conn, run_id)["status"] == "queued"
    second = engine.execute(conn, run_id, 60_000, LOG)

    assert second.status == "completed"
    assert rec.calls == {"a": 1, "b": 2, "c": 1}, "completed phase 'a' must not run again"
    assert second.phases_run == ("b", "c")
    assert checkpoints(conn, run_id)[-2:] == [("b", 2, "completed"), ("c", 1, "completed")]
    assert run_row(conn, run_id)["attempt"] == 2


def test_no_time_left_before_a_phase_starts(conn):
    run_id = seed_run(conn)
    rec = Recorder(FakeClock())
    engine = PipelineEngine(
        [rec.phase("a", spend_ms=4600), rec.phase("b")], reserve_ms=500, clock=rec.clock
    )

    result = engine.execute(conn, run_id, 5000, LOG)

    assert (result.status, result.reason) == ("checkpointed", "budget_exhausted:b")
    assert "b" not in rec.calls
    assert checkpoints(conn, run_id) == [("a", 1, "completed")]


def test_phase_failure_fails_the_run_and_resume_retries_only_that_phase(conn):
    run_id = seed_run(conn)
    rec = Recorder(FakeClock())
    failing = rec.phase("b", error=PhaseFailed("manifest could not be parsed"))
    engine = PipelineEngine([rec.phase("a"), failing], reserve_ms=0, clock=rec.clock)

    result = engine.execute(conn, run_id, 10_000, LOG)

    assert result.status == "failed"
    row = run_row(conn, run_id)
    assert row["failure_reason"] == "b: manifest could not be parsed"
    assert row["finished_at"] is not None
    assert scalar(conn, "SELECT error FROM analysis_checkpoints WHERE phase = 'b'") == (
        "manifest could not be parsed"
    )

    request_resume(conn, run_id, 10_000)
    fixed = PipelineEngine([rec.phase("a"), rec.phase("b")], reserve_ms=0, clock=rec.clock)
    assert fixed.execute(conn, run_id, 10_000, LOG).status == "completed"
    assert rec.calls["a"] == 1


def test_a_crashing_phase_is_recorded_not_retried_forever(conn):
    run_id = seed_run(conn)
    rec = Recorder(FakeClock())
    engine = PipelineEngine(
        [rec.phase("a", error=KeyError("missing"))], reserve_ms=0, clock=rec.clock
    )

    result = engine.execute(conn, run_id, 10_000, LOG)

    assert result.status == "failed"
    assert run_row(conn, run_id)["failure_reason"].startswith("a: KeyError")


def test_skipped_phase_counts_as_done_on_resume(conn):
    run_id = seed_run(conn)
    rec = Recorder(FakeClock())
    phases = [
        rec.phase("a", error=PhaseSkipped("llm_not_configured")),
        rec.phase("b", needs_ms=99_999),
    ]
    engine = PipelineEngine(phases, reserve_ms=0, clock=rec.clock)

    engine.execute(conn, run_id, 10_000, LOG)
    assert checkpoints(conn, run_id)[0] == ("a", 1, "skipped")
    assert json.loads(scalar(conn, "SELECT state FROM analysis_checkpoints WHERE phase = 'a'")) == {
        "reason": "llm_not_configured"
    }

    request_resume(conn, run_id, 10_000)
    engine.execute(conn, run_id, 10_000, LOG)
    assert rec.calls["a"] == 1


def test_oversized_phase_state_fails_the_run(conn):
    run_id = seed_run(conn)
    rec = Recorder(FakeClock())
    engine = PipelineEngine(
        [rec.phase("a", result={"blob": "x" * 300_000})], reserve_ms=0, clock=rec.clock
    )

    result = engine.execute(conn, run_id, 10_000, LOG)

    assert (result.status, result.reason) == ("failed", "a: state too large")


def test_losing_the_job_lease_stops_the_run_but_keeps_its_progress(conn):
    run_id = seed_run(conn)
    rec = Recorder(FakeClock())
    engine = PipelineEngine([rec.phase("a"), rec.phase("b")], reserve_ms=0, clock=rec.clock)
    leases = iter([None])

    def keep_lease():
        if next(leases, "lost") == "lost":
            raise LeaseLost("reclaimed by another worker")

    with pytest.raises(LeaseLost):
        engine.execute(conn, run_id, 10_000, LOG, keep_lease=keep_lease)

    assert checkpoints(conn, run_id) == [("a", 1, "completed")], "progress before the loss is kept"
    assert "b" not in rec.calls


def test_resume_is_refused_for_runs_that_are_not_failed_or_checkpointed(conn):
    run_id = seed_run(conn)
    with pytest.raises(ResumeRefused, match="queued"):
        request_resume(conn, run_id, 10_000)
    with pytest.raises(ResumeRefused, match="does not exist"):
        request_resume(conn, 999_999, 10_000)


# ----- the LLM review phase --------------------------------------------------------------

CONTEXT_TEXT = "def charge(amount):\n    return gateway.pay(amount)\n# TODO handle refunds\n"


class ScriptedProvider:
    name = "fake"
    model = "fake-model-1"
    data_policy = "may_train"

    def __init__(self, content):
        self.content = content
        self.calls = 0
        self.last_context = None

    def analyze(self, context, task, constraints):
        self.calls += 1
        self.last_context = context
        return ProviderResult(
            self.name, self.model, self.content, latency_ms=42, tokens_in=300, tokens_out=60
        )


def context_phase(ctx, budget):
    secret = "sk" + "_live_" + "9" * 24  # assembled at runtime
    return {"text": CONTEXT_TEXT + f"STRIPE = '{secret}'\n", "redactions": 0}


def test_llm_review_keeps_only_grounded_observations_and_records_the_call(conn):
    run_id = seed_run(conn)
    provider = ScriptedProvider(
        {
            "summary": "Adds a payment call without refund handling.",
            "observations": [
                {
                    "title": "Refunds not handled",
                    "detail": "A TODO remains.",
                    "confidence": "medium",
                    "evidence": "# TODO handle refunds",
                },
                {
                    "title": "SQL injection",
                    "detail": "Invented.",
                    "confidence": "high",
                    "evidence": "cursor.execute(user_input)",
                },
            ],
        }
    )
    engine = PipelineEngine(
        [Phase("context", 5000, context_phase), Phase("llm_review", 30_000, llm_review)],
        reserve_ms=0,
    )

    result = engine.execute(conn, run_id, 60_000, LOG, router=Router([provider]))

    assert result.status == "completed"
    review = json.loads(
        scalar(conn, "SELECT state FROM analysis_checkpoints WHERE phase = 'llm_review'")
    )
    assert [o["title"] for o in review["observations"]] == ["Refunds not handled"]
    assert review["unsupported_observations_dropped"] == 1
    assert (review["provider"], review["model"]) == ("fake", "fake-model-1")
    assert "sk_live_" not in provider.last_context.text, "secrets are masked before sending"

    call = rows(
        conn,
        "SELECT phase, provider, model, outcome, latency_ms, tokens_in, tokens_out "
        "FROM provider_calls",
    )
    assert call == [
        {
            "phase": "llm_review",
            "provider": "fake",
            "model": "fake-model-1",
            "outcome": "success",
            "latency_ms": 42,
            "tokens_in": 300,
            "tokens_out": 60,
        }
    ]


def test_private_repository_code_is_never_sent(conn):
    run_id = seed_run(conn, private=True, llm_policy="public_only")
    provider = ScriptedProvider({"summary": "should never be asked"})
    engine = PipelineEngine(
        [Phase("context", 5000, context_phase), Phase("llm_review", 30_000, llm_review)],
        reserve_ms=0,
    )

    engine.execute(conn, run_id, 60_000, LOG, router=Router([provider]))

    assert provider.calls == 0
    assert (
        scalar(conn, "SELECT status FROM analysis_checkpoints WHERE phase = 'llm_review'")
        == "skipped"
    )
    assert json.loads(
        scalar(conn, "SELECT state FROM analysis_checkpoints WHERE phase = 'llm_review'")
    ) == {"reason": "blocked_by_privacy_policy"}
    assert scalar(conn, "SELECT COUNT(*) FROM provider_calls") == 0


# ----- end to end through the job queue ---------------------------------------------------


def test_worker_turns_an_event_into_a_completed_run(settings, conn):
    seed_event(conn)
    handlers = build_handlers(settings, LOG, Router([]))
    r = runner(settings, handlers=handlers, queues=("events", "analysis"))

    assert r.run_once() is True  # event job -> run + analysis job
    assert r.run_once() is True  # analysis job -> pipeline

    run = rows(conn, "SELECT status, failure_reason, finished_at FROM analysis_runs")[0]
    assert run["status"] == "completed", run
    # No GitHub client was given, so extraction and everything that needs its
    # output is skipped (not failed), and the run still completes.
    recorded = checkpoints(conn, scalar(conn, "SELECT id FROM analysis_runs"))
    assert [p for p, _, _ in recorded] == [p.name for p in PRODUCTION_PHASES]
    assert recorded[0] == ("load_event", 1, "completed")
    assert ("extract_changes", 1, "skipped") in recorded
    assert recorded[-1] == ("normalize_findings", 1, "completed")
    assert scalar(conn, "SELECT COUNT(*) FROM jobs WHERE status <> 'succeeded'") == 0


def test_long_analysis_keeps_extending_its_lease(settings, conn):
    seed_event(conn)
    extended = threading.Event()

    def slow_phase(ctx, budget):
        with ctx.conn.cursor() as cur:
            cur.execute(
                "SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), locked_until) AS left_s "
                "FROM jobs WHERE queue = 'analysis' AND status = 'running'"
            )
            if cur.fetchone()["left_s"] >= settings.job_lease_s - 2:
                extended.set()
        return {}

    import keelwatch_worker.analysis as analysis_module

    original = list(analysis_module.PRODUCTION_PHASES)
    analysis_module.PRODUCTION_PHASES[:] = [Phase("slow", 5000, slow_phase)]
    try:
        handlers = build_handlers(settings, LOG, Router([]))
        r = runner(settings, handlers=handlers, queues=("events", "analysis"))
        r.run_once()
        r.run_once()
    finally:
        analysis_module.PRODUCTION_PHASES[:] = original

    assert extended.is_set(), "the lease was refreshed right before the phase ran"

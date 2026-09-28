"""Analysis jobs (contracts/analysis-job.v1.json) and the production pipeline.

Phase 5 ships the engine plus the phases that do real work today. The code
intelligence phases (changed-file extraction, context building, security,
dependencies, structure) are added to PRODUCTION_PHASES in Phase 6; the LLM
review phase is implemented and tested here and joins the pipeline once a
context phase produces something to review.
"""

from __future__ import annotations

import json
import re
from typing import Any

from pymysql.connections import Connection

from .budget import Budget, BudgetExceeded
from .llm.base import AnalysisContext, TaskSpec
from .llm.router import Router
from .logs import Logger
from .pipeline import Phase, PhaseFailed, PhaseSkipped, PipelineEngine, RunContext
from .queue import Job, PermanentError
from .redaction import redact

_CORRELATION = re.compile(r"[A-Za-z0-9._-]{8,128}")
TRIGGERS = frozenset({"webhook", "schedule", "manual", "resume"})


def validate_analysis_job(payload: Any) -> dict[str, Any]:
    """Runtime check equivalent to contracts/analysis-job.v1.json."""
    expected = {
        "schema_version",
        "run_id",
        "repository_id",
        "trigger",
        "correlation_id",
        "budget_ms",
    }
    if not isinstance(payload, dict) or set(payload) != expected:
        raise PermanentError(f"analysis job payload keys must be exactly {sorted(expected)}")
    if payload["schema_version"] != 1:
        raise PermanentError("unsupported analysis job schema_version")
    for key in ("run_id", "repository_id"):
        v = payload[key]
        if not isinstance(v, int) or isinstance(v, bool) or v < 1:
            raise PermanentError(f"{key} must be a positive integer")
    if payload["trigger"] not in TRIGGERS:
        raise PermanentError("unknown trigger")
    b = payload["budget_ms"]
    if not isinstance(b, int) or isinstance(b, bool) or not 1000 <= b <= 3_600_000:
        raise PermanentError("budget_ms must be between 1000 and 3600000")
    if not isinstance(payload["correlation_id"], str) or not _CORRELATION.fullmatch(
        payload["correlation_id"]
    ):
        raise PermanentError("correlation_id is malformed")
    return payload


# ----- phases -----------------------------------------------------------------


def load_event(ctx: RunContext, budget: Budget) -> dict[str, Any]:
    envelope = ctx.envelope
    if envelope is None:
        raise PhaseFailed("run has no triggering event")
    pr = envelope.get("pull_request") or {}
    return {
        "event_type": envelope["type"],
        "repository": ctx.repository["full_name"],
        "head_sha": ctx.run["head_sha"],
        "pull_request": pr.get("number"),
    }


REVIEW_TASK = TaskSpec(
    name="review_change",
    instructions=(
        "Summarise the engineering risk of this change in two sentences, then list at "
        "most five observations. Every observation must quote a short, exact excerpt "
        "from the context as evidence. Do not report anything you cannot quote."
    ),
    response_schema_hint=(
        '{"summary": "...", "observations": [{"title": "...", "detail": "...", '
        '"confidence": "low|medium|high", "evidence": "exact excerpt from the context"}]}'
    ),
)


def _normalise_ws(text: str) -> str:
    return " ".join(text.split())


def validate_review(content: dict[str, Any], context_text: str) -> tuple[dict[str, Any], int]:
    """Keeps only well-formed observations whose evidence really appears in the
    context: a cheap guard against invented findings. Returns (review, dropped)."""
    summary = content.get("summary")
    if not isinstance(summary, str) or not summary.strip():
        raise PhaseFailed("model answer has no summary")
    haystack = _normalise_ws(context_text)

    kept: list[dict[str, str]] = []
    dropped = 0
    raw = content.get("observations")
    for item in raw if isinstance(raw, list) else []:
        if len(kept) >= 5:
            dropped += 1
            continue
        if not isinstance(item, dict):
            dropped += 1
            continue
        title, detail = item.get("title"), item.get("detail")
        confidence, evidence = item.get("confidence"), item.get("evidence")
        if (
            not isinstance(title, str)
            or not title.strip()
            or not isinstance(detail, str)
            or confidence not in ("low", "medium", "high")
            or not isinstance(evidence, str)
            or len(_normalise_ws(evidence)) < 4
            or _normalise_ws(evidence) not in haystack
        ):
            dropped += 1
            continue
        kept.append(
            {
                "title": title.strip()[:200],
                "detail": detail.strip()[:2000],
                "confidence": confidence,
                "evidence": evidence.strip()[:500],
            }
        )
    return {"summary": summary.strip()[:2000], "observations": kept}, dropped


def llm_review(ctx: RunContext, budget: Budget) -> dict[str, Any]:
    context_state = ctx.state.get("context") or {}
    text = context_state.get("text")
    if not text:
        raise PhaseSkipped("no context to review")
    if ctx.router is None or not ctx.router.providers:
        raise PhaseSkipped("llm_not_configured")

    # Defence in depth: the context phase redacts, and so does this one.
    masked = redact(text)
    context = AnalysisContext(
        repository_full_name=ctx.repository["full_name"],
        repository_private=ctx.repository["is_private"],
        text=masked.text,
        redactions=context_state.get("redactions", 0) + masked.count,
    )
    outcome = ctx.router.analyze(
        context,
        REVIEW_TASK,
        budget,
        llm_policy=ctx.repository["llm_policy"],
        record=ctx.record_call,
    )
    if not outcome.ok:
        if outcome.reason == "budget_exhausted":
            raise BudgetExceeded("no time left for another provider call")
        # No answer is a gap, not a failure: deterministic findings still stand.
        raise PhaseSkipped(outcome.reason)

    result = outcome.result
    if result is None:  # "ok" implies a result; stated explicitly for type checkers
        raise PhaseFailed("router reported success without a result")
    review, dropped = validate_review(result.content, context.text)
    return {
        "provider": result.provider,
        "model": result.model,
        "tokens_in": result.tokens_in,
        "tokens_out": result.tokens_out,
        "unsupported_observations_dropped": dropped,
        **review,
    }


def _code_intelligence_phases() -> list[Phase]:
    # Imported here: intel.phases depends on this module's neighbours.
    from .intel import phases as intel

    return [
        Phase("extract_changes", 30_000, intel.extract_changes),
        Phase("secrets", 5_000, intel.secrets_phase),
        Phase("dependencies", 30_000, intel.dependencies_phase),
        Phase("structure", 2_000, intel.structure_phase),
        Phase("context", 2_000, intel.context_phase),
        Phase("llm_review", 60_000, llm_review),
        Phase("normalize_findings", 5_000, intel.normalize_findings),
    ]


# Order matters: later phases read earlier phases' checkpoint state.
PRODUCTION_PHASES: list[Phase] = [
    Phase("load_event", 5_000, load_event),
    *_code_intelligence_phases(),
]


# ----- job handler and resume ------------------------------------------------------


def handle_analysis_run(
    conn: Connection,
    job: Job,
    keep_lease,
    *,
    engine: PipelineEngine,
    router: Router | None,
    logger: Logger,
    github: Any = None,
    osv: Any = None,
) -> dict[str, Any]:
    payload = validate_analysis_job(job.payload)
    with conn.cursor() as cur:
        cur.execute("SELECT repository_id FROM analysis_runs WHERE id = %s", (payload["run_id"],))
        row = cur.fetchone()
    if row is None:
        raise PermanentError(f"analysis run {payload['run_id']} does not exist")
    if row["repository_id"] != payload["repository_id"]:
        raise PermanentError("run belongs to a different repository than the job says")

    result = engine.execute(
        conn,
        payload["run_id"],
        payload["budget_ms"],
        logger.with_correlation_id(payload["correlation_id"]),
        router=router,
        github=github,
        osv=osv,
        keep_lease=keep_lease,
    )
    return {
        "outcome": result.status,
        "reason": result.reason,
        "run_id": payload["run_id"],
        "phases_run": list(result.phases_run),
        "phases_resumed": list(result.phases_resumed),
    }


class ResumeRefused(Exception):
    pass


def request_resume(conn: Connection, run_id: int, budget_ms: int) -> int:
    """Queues a resume for a failed or checkpointed run. Completed phases are
    kept; the run gets a fresh budget. Returns the new job id."""
    conn.begin()
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id, repository_id, status, attempt, correlation_id "
                "FROM analysis_runs WHERE id = %s FOR UPDATE",
                (run_id,),
            )
            run = cur.fetchone()
            if run is None:
                raise ResumeRefused(f"run {run_id} does not exist")
            if run["status"] not in ("failed", "checkpointed"):
                raise ResumeRefused(
                    f"run {run_id} is {run['status']}; only failed or checkpointed runs resume"
                )
            attempt = run["attempt"] + 1
            cur.execute(
                "UPDATE analysis_runs SET status = 'queued', attempt = %s, failure_reason = NULL, "
                "finished_at = NULL, updated_at = UTC_TIMESTAMP(3) WHERE id = %s",
                (attempt, run_id),
            )
            payload = {
                "schema_version": 1,
                "run_id": run_id,
                "repository_id": run["repository_id"],
                "trigger": "resume",
                "correlation_id": run["correlation_id"],
                "budget_ms": budget_ms,
            }
            cur.execute(
                "INSERT INTO jobs (queue, type, payload, idempotency_key, correlation_id) "
                "VALUES ('analysis', 'analysis_run', %s, %s, %s)",
                (json.dumps(payload), f"run:{run_id}:attempt:{attempt}", run["correlation_id"]),
            )
            job_id = cur.lastrowid
        conn.commit()
    except BaseException:
        conn.rollback()
        raise
    return job_id

"""Handler for jobs on the 'events' queue (contracts/event-job.v1.json).

Turns a stored repository event into at most one analysis run plus an
analysis job. Everything here runs inside the runner's transaction, and
every insert is keyed, so reprocessing the same job cannot create a
second run or a second analysis job.
"""

from __future__ import annotations

import json
import re
from dataclasses import dataclass
from typing import Any

from pymysql.connections import Connection

from .queue import Job, PermanentError

# re.fullmatch, never re.search with "$": see contracts/README.md.
_CORRELATION = re.compile(r"[A-Za-z0-9._-]{8,128}")

ANALYSABLE_PR_ACTIONS = frozenset({"opened", "synchronize", "reopened", "ready_for_review"})


@dataclass(frozen=True)
class RunDecision:
    analyse: bool
    reason: str
    idempotency_key: str | None = None
    head_sha: str | None = None


def validate_event_job(payload: Any) -> dict[str, Any]:
    """Runtime check equivalent to contracts/event-job.v1.json."""
    if not isinstance(payload, dict):
        raise PermanentError("event job payload must be an object")
    expected = {"schema_version", "event_id", "repository_id", "correlation_id"}
    if set(payload) != expected:
        raise PermanentError(f"event job payload keys must be exactly {sorted(expected)}")
    if payload["schema_version"] != 1:
        raise PermanentError("unsupported event job schema_version")
    for key in ("event_id", "repository_id"):
        value = payload[key]
        if not isinstance(value, int) or isinstance(value, bool) or value < 1:
            raise PermanentError(f"{key} must be a positive integer")
    if not isinstance(payload["correlation_id"], str) or not _CORRELATION.fullmatch(
        payload["correlation_id"]
    ):
        raise PermanentError("correlation_id is malformed")
    return payload


def decide(envelope: dict[str, Any], repository_id: int, default_branch: str | None) -> RunDecision:
    """Which events deserve an analysis run. Pure, so the policy is unit-tested."""
    event_type = envelope.get("type")

    if envelope.get("event") == "pull_request":
        pr = envelope["pull_request"]
        if envelope.get("action") not in ANALYSABLE_PR_ACTIONS:
            return RunDecision(False, f"no analysis for {event_type}")
        if pr["state"] != "open":
            return RunDecision(False, "pull request is not open")
        if pr["draft"]:
            return RunDecision(False, "draft pull request")
        return RunDecision(
            True,
            "pull request updated",
            idempotency_key=f"pr:{repository_id}:{pr['number']}:{pr['head_sha']}",
            head_sha=pr["head_sha"],
        )

    if envelope.get("event") == "push":
        push = envelope["push"]
        if default_branch is None or push["ref"] != f"refs/heads/{default_branch}":
            return RunDecision(False, "push to a non-default branch (covered by its pull request)")
        if push["commit_count"] == 0:
            return RunDecision(False, "push with no commits")
        return RunDecision(
            True,
            "push to default branch",
            idempotency_key=f"push:{repository_id}:{push['after']}",
            head_sha=push["after"],
        )

    return RunDecision(False, f"unsupported event type {event_type}")


def handle_github_event(conn: Connection, job: Job, analysis_budget_ms: int) -> dict[str, Any]:
    payload = validate_event_job(job.payload)

    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT e.id, e.repository_id, e.envelope,
                   r.default_branch, r.analysis_enabled, r.removed_at
            FROM repository_events e
            JOIN repositories r ON r.id = e.repository_id
            WHERE e.id = %s
            """,
            (payload["event_id"],),
        )
        row = cur.fetchone()

    if row is None:
        raise PermanentError(f"repository event {payload['event_id']} does not exist")
    if row["repository_id"] != payload["repository_id"]:
        raise PermanentError("event belongs to a different repository than the job says")
    if not row["analysis_enabled"] or row["removed_at"] is not None:
        return {"outcome": "skipped", "reason": "repository is disabled or removed"}

    envelope = (
        json.loads(row["envelope"]) if isinstance(row["envelope"], str | bytes) else row["envelope"]
    )
    decision = decide(envelope, row["repository_id"], row["default_branch"])
    if not decision.analyse:
        return {"outcome": "skipped", "reason": decision.reason}

    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT IGNORE INTO analysis_runs
                (repository_id, event_id, trigger_type, idempotency_key, status,
                 head_sha, budget_ms, correlation_id)
            VALUES (%s, %s, 'webhook', %s, 'queued', %s, %s, %s)
            """,
            (
                row["repository_id"],
                row["id"],
                decision.idempotency_key,
                decision.head_sha,
                analysis_budget_ms,
                payload["correlation_id"],
            ),
        )
        created = cur.rowcount == 1
        cur.execute(
            "SELECT id FROM analysis_runs WHERE idempotency_key = %s", (decision.idempotency_key,)
        )
        run_id = cur.fetchone()["id"]

        analysis_job = {
            "schema_version": 1,
            "run_id": run_id,
            "repository_id": row["repository_id"],
            "trigger": "webhook",
            "correlation_id": payload["correlation_id"],
            "budget_ms": analysis_budget_ms,
        }
        cur.execute(
            """
            INSERT IGNORE INTO jobs (queue, type, payload, idempotency_key, correlation_id)
            VALUES ('analysis', 'analysis_run', %s, %s, %s)
            """,
            (json.dumps(analysis_job), f"run:{run_id}:attempt:1", payload["correlation_id"]),
        )

    return {
        "outcome": "run_created" if created else "run_exists",
        "reason": decision.reason,
        "run_id": run_id,
    }

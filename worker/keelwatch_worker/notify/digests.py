"""Builds, stores and fans out digests.

Run digest: one per analysis run, written by the pipeline's `digest` phase.
Daily digest: one per installation per UTC day, written by a scheduled job.
Both are stored in `digests` (content JSON) and linked to their runs; each
stored digest then gets one notification job per matching destination.
"""

from __future__ import annotations

import json
from datetime import UTC, datetime, timedelta
from typing import Any

from pymysql.connections import Connection

from .format import SEVERITY_ORDER, meets_threshold

TOP_FINDINGS = 5


def _rows(conn: Connection, sql: str, params: tuple = ()) -> list[dict[str, Any]]:
    with conn.cursor() as cur:
        cur.execute(sql, params)
        return cur.fetchall()


def _json(value: Any) -> Any:
    return json.loads(value) if isinstance(value, str | bytes) else value


def _by_severity(rows: list[dict[str, Any]]) -> dict[str, int]:
    out = {s: 0 for s in SEVERITY_ORDER}
    for r in rows:
        out[r["severity"]] = out.get(r["severity"], 0) + int(r.get("n", 1))
    return {s: n for s, n in out.items() if n}


# ----- run digest -------------------------------------------------------------------


def build_run_digest(conn: Connection, run_id: int) -> dict[str, Any]:
    [run] = _rows(
        conn,
        """
        SELECT r.id, r.repository_id, r.status, r.head_sha, r.created_at,
               repo.full_name, repo.is_private, e.envelope
        FROM analysis_runs r
        JOIN repositories repo ON repo.id = r.repository_id
        LEFT JOIN repository_events e ON e.id = r.event_id
        WHERE r.id = %s
        """,
        (run_id,),
    )
    findings = _rows(
        conn,
        """
        SELECT f.fingerprint, f.severity, f.confidence, f.title, f.file_path, f.line_start,
               f.source, f.rule_id,
               EXISTS (
                   SELECT 1 FROM analysis_findings prev
                   WHERE prev.repository_id = f.repository_id
                     AND prev.fingerprint = f.fingerprint
                     AND prev.run_id < f.run_id
               ) AS recurring
        FROM analysis_findings f
        WHERE f.run_id = %s
        ORDER BY FIELD(f.severity, 'critical', 'high', 'medium', 'low', 'info'), f.id
        """,
        (run_id,),
    )
    checkpoints = {
        r["phase"]: r
        for r in _rows(
            conn,
            """
            SELECT c.phase, c.status, c.state FROM analysis_checkpoints c
            JOIN (SELECT phase, MAX(attempt) AS attempt FROM analysis_checkpoints
                  WHERE run_id = %s GROUP BY phase) latest
              ON latest.phase = c.phase AND latest.attempt = c.attempt
            WHERE c.run_id = %s
            """,
            (run_id, run_id),
        )
    }

    envelope = _json(run["envelope"]) or {}
    pr = envelope.get("pull_request") or {}
    recurring = sum(1 for f in findings if f["recurring"])

    return {
        "schema_version": 1,
        "kind": "run",
        "run_id": run["id"],
        "repository": {
            "id": run["repository_id"],
            "full_name": run["full_name"],
            "private": bool(run["is_private"]),
        },
        "trigger": {
            "type": envelope.get("type"),
            "pull_request": pr.get("number"),
            "head_sha_short": (run["head_sha"] or "")[:7] or None,
        },
        "findings": {
            "total": len(findings),
            "by_severity": _by_severity(findings),
            "new": len(findings) - recurring,
            "recurring": recurring,
            "top": [
                {
                    "severity": f["severity"],
                    "confidence": f["confidence"],
                    "title": f["title"],
                    "file_path": f["file_path"],
                    "line": f["line_start"],
                    "source": f["source"],
                    "rule_id": f["rule_id"],
                }
                for f in findings[:TOP_FINDINGS]
            ],
        },
        "dependencies": _dependency_summary(checkpoints),
        "structure": (_json((checkpoints.get("structure") or {}).get("state")) or {}).get(
            "metrics"
        ),
        "review": _review_summary(checkpoints),
        "gaps": _gaps(checkpoints),
    }


def _dependency_summary(checkpoints: dict[str, dict]) -> dict[str, Any] | None:
    state = _json((checkpoints.get("dependencies") or {}).get("state"))
    if not state or "changes" not in state:
        return None
    return {
        "changes": len(state["changes"]),
        "manifests": state.get("manifests", []),
        "osv": state.get("osv"),
    }


def _review_summary(checkpoints: dict[str, dict]) -> dict[str, Any] | None:
    cp = checkpoints.get("llm_review") or {}
    state = _json(cp.get("state")) or {}
    if cp.get("status") != "completed" or not state.get("summary"):
        return None
    return {
        "summary": state["summary"],
        "provider": state.get("provider"),
        "model": state.get("model"),
    }


def _gaps(checkpoints: dict[str, dict]) -> list[str]:
    """What was *not* checked, so a quiet digest is never mistaken for a clean bill."""
    gaps: list[str] = []
    labels = {
        "extract_changes": "changed files",
        "secrets": "secrets scan",
        "dependencies": "dependency analysis",
        "structure": "change structure",
        "llm_review": "AI review",
    }
    for phase, label in labels.items():
        cp = checkpoints.get(phase)
        if cp is None:
            gaps.append(f"{label}: did not run")
        elif cp["status"] == "skipped":
            reason = (_json(cp["state"]) or {}).get("reason", "skipped")
            gaps.append(f"{label}: {reason}")
        elif cp["status"] == "failed":
            gaps.append(f"{label}: failed")
    extract = _json((checkpoints.get("extract_changes") or {}).get("state")) or {}
    if extract.get("files_truncated"):
        gaps.append("only part of a very large change was analysed")
    deps = _json((checkpoints.get("dependencies") or {}).get("state")) or {}
    if str(deps.get("osv", "")).startswith("unavailable"):
        gaps.append("known-vulnerability lookup was unavailable")
    return gaps


# ----- daily digest ---------------------------------------------------------------------


def day_bounds(date_str: str) -> tuple[datetime, datetime]:
    start = datetime.strptime(date_str, "%Y-%m-%d").replace(tzinfo=UTC)
    return start, start + timedelta(days=1)


def build_daily_digest(conn: Connection, installation_id: int, date_str: str) -> dict[str, Any]:
    start, end = day_bounds(date_str)
    naive = (start.replace(tzinfo=None), end.replace(tzinfo=None))
    runs = _rows(
        conn,
        """
        SELECT r.id, r.status, r.repository_id, repo.full_name, repo.is_private
        FROM analysis_runs r
        JOIN repositories repo ON repo.id = r.repository_id
        WHERE repo.installation_id = %s AND r.finished_at >= %s AND r.finished_at < %s
        ORDER BY repo.full_name, r.id
        """,
        (installation_id, *naive),
    )
    run_ids = [r["id"] for r in runs]
    findings: list[dict[str, Any]] = []
    areas: dict[str, int] = {}
    new_files = 0
    dependency_changes = 0
    if run_ids:
        marks = ", ".join(["%s"] * len(run_ids))
        findings = _rows(
            conn,
            f"""
            SELECT f.run_id, f.severity,
                   NOT EXISTS (
                       SELECT 1 FROM analysis_findings prev
                       WHERE prev.repository_id = f.repository_id
                         AND prev.fingerprint = f.fingerprint AND prev.run_id < f.run_id
                   ) AS is_new
            FROM analysis_findings f WHERE f.run_id IN ({marks})
            """,  # noqa: S608 - placeholders only
            tuple(run_ids),
        )
        for cp in _rows(
            conn,
            # {marks} is only "%s, %s, ..."; the run ids are bound as parameters.
            f"SELECT phase, state FROM analysis_checkpoints WHERE status = 'completed' "  # noqa: S608
            f"AND phase IN ('structure', 'dependencies') AND run_id IN ({marks})",
            tuple(run_ids),
        ):
            state = _json(cp["state"]) or {}
            if cp["phase"] == "structure":
                metrics = state.get("metrics") or {}
                new_files += int(metrics.get("new_files") or 0)
                for area in metrics.get("areas_touched") or []:
                    areas[area] = areas.get(area, 0) + 1
            else:
                dependency_changes += len(state.get("changes") or [])

    per_repo: dict[int, dict[str, Any]] = {}
    for r in runs:
        repo = per_repo.setdefault(
            r["repository_id"],
            {
                "full_name": r["full_name"],
                "private": bool(r["is_private"]),
                "runs": 0,
                "failed_runs": 0,
                "run_ids": [],
            },
        )
        repo["runs"] += 1
        repo["failed_runs"] += int(r["status"] == "failed")
        repo["run_ids"].append(r["id"])
    for repo in per_repo.values():
        ids = set(repo.pop("run_ids"))
        repo["by_severity"] = _by_severity([f for f in findings if f["run_id"] in ids])

    return {
        "schema_version": 1,
        "kind": "daily",
        "installation_id": installation_id,
        "period": {"date": date_str, "start": start.isoformat(), "end": end.isoformat()},
        "totals": {
            "runs": len(runs),
            "failed_runs": sum(1 for r in runs if r["status"] == "failed"),
            "by_severity": _by_severity(findings),
            "new_findings": sum(1 for f in findings if f["is_new"]),
        },
        "repositories": list(per_repo.values()),
        "evolution": {
            "areas_touched": sorted(areas.items(), key=lambda kv: (-kv[1], kv[0]))[:10],
            "new_files": new_files,
            "dependency_changes": dependency_changes,
        },
        "run_ids": run_ids,
    }


# ----- persistence and fan-out ------------------------------------------------------------


def store_digest(
    conn: Connection,
    *,
    installation_id: int,
    repository_id: int | None,
    kind: str,
    key: str,
    period_start: datetime,
    period_end: datetime,
    content: dict[str, Any],
    run_ids: list[int],
) -> int:
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT IGNORE INTO digests
                (installation_id, repository_id, kind, digest_key, period_start, period_end, content)
            VALUES (%s, %s, %s, %s, %s, %s, %s)
            """,
            (
                installation_id,
                repository_id,
                kind,
                key,
                period_start.replace(tzinfo=None),
                period_end.replace(tzinfo=None),
                json.dumps(content, default=str),
            ),
        )
        cur.execute("SELECT id FROM digests WHERE digest_key = %s", (key,))
        digest_id = cur.fetchone()["id"]
        for run_id in run_ids:
            cur.execute(
                "INSERT IGNORE INTO digest_runs (digest_id, run_id) VALUES (%s, %s)",
                (digest_id, run_id),
            )
    return digest_id


def enqueue_notifications(
    conn: Connection,
    *,
    digest_id: int,
    installation_id: int,
    by_severity: dict[str, int] | None,
    correlation_id: str,
) -> int:
    """One job per enabled destination whose threshold this digest meets.
    by_severity=None means "always send" (daily digests with activity)."""
    destinations = _rows(
        conn,
        "SELECT id, min_severity FROM notification_destinations WHERE installation_id = %s AND enabled = 1",
        (installation_id,),
    )
    queued = 0
    with conn.cursor() as cur:
        for dest in destinations:
            if by_severity is not None and not meets_threshold(by_severity, dest["min_severity"]):
                continue
            payload = {"schema_version": 1, "destination_id": dest["id"], "digest_id": digest_id}
            cur.execute(
                "INSERT IGNORE INTO jobs (queue, type, payload, idempotency_key, correlation_id) "
                "VALUES ('notifications', 'notify', %s, %s, %s)",
                (json.dumps(payload), f"notify:{dest['id']}:{digest_id}", correlation_id),
            )
            queued += cur.rowcount
    return queued

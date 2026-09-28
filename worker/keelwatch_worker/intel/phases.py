"""Code-intelligence phases for the analysis pipeline.

extract_changes -> secrets -> dependencies -> structure -> context
-> (llm_review) -> normalize_findings

Each phase reads earlier phases' checkpoint state from ctx.state and returns
its own JSON-serialisable state. GitHub rate limits and outages raise
RetryLater so the run resumes later instead of failing.
"""

from __future__ import annotations

import json
import re
from typing import Any

from ..budget import Budget
from ..github.http import (
    GitHubError,
    GitHubNotFound,
    GitHubPermissionDenied,
    GitHubRateLimited,
    GitHubUnavailable,
)
from ..pipeline import PhaseFailed, PhaseSkipped, RetryLater, RunContext
from ..redaction import redact
from . import deps, structure
from .diff import is_generated, manifest_ecosystem
from .findings import make_finding
from .osv import OsvUnavailable
from .secrets import scan as scan_secrets

MAX_FILES = 300
MAX_PATCH_BYTES_PER_FILE = 20_000
MAX_TOTAL_PATCH_BYTES = 150_000
MAX_MANIFESTS = 20
MAX_CONTEXT_CHARS = 24_000
STATUSES = frozenset({"added", "removed", "modified", "renamed", "copied", "changed", "unchanged"})


def _github_call(fn, *args):
    try:
        return fn(*args)
    except (GitHubRateLimited, GitHubUnavailable) as exc:
        raise RetryLater(str(exc)) from exc
    except GitHubPermissionDenied as exc:
        raise PhaseFailed(str(exc)) from exc


def _safe_path(value: Any) -> str | None:
    if not isinstance(value, str) or not value or len(value) > 1024 or "\x00" in value:
        return None
    if value.startswith(("/", "\\")) or re.match(r"^[A-Za-z]:", value) or ".." in value.split("/"):
        return None
    return value


def _changes(ctx: RunContext) -> dict[str, Any]:
    extract = ctx.state.get("extract_changes") or {}
    if not extract.get("files"):
        raise PhaseSkipped("no changed files were extracted")
    return extract


# ----- extract_changes ---------------------------------------------------------------


def extract_changes(ctx: RunContext, budget: Budget) -> dict[str, Any]:
    gh = ctx.github
    if gh is None:
        raise PhaseSkipped("github_not_configured")
    if ctx.repository["is_private"] and not gh.auth.can_read_private:
        raise PhaseSkipped("a private repository needs a configured GitHub App")
    env = ctx.envelope
    if env is None:
        raise PhaseSkipped("run has no triggering event")

    name = ctx.repository["full_name"]
    installation = env["installation"]["github_id"]
    try:
        if env["event"] == "pull_request":
            pr = env["pull_request"]
            base, head = pr["base_sha"], pr["head_sha"]
            files, truncated = _github_call(gh.pr_files, name, pr["number"], installation, budget)
        else:
            push = env["push"]
            base, head = push["before"], push["after"]
            if set(base) == {"0"}:  # new branch: no "before" commit to compare with
                base = None
                files, truncated = _github_call(gh.commit_files, name, head, installation, budget)
            else:
                files, truncated = _github_call(gh.compare, name, base, head, installation, budget)
    except GitHubNotFound as exc:
        raise PhaseSkipped(f"changes are no longer available: {exc}") from exc
    except GitHubError as exc:
        raise PhaseFailed(str(exc)) from exc

    kept: list[dict[str, Any]] = []
    total_patch = 0
    redactions = 0
    for raw in files[:MAX_FILES]:
        path = _safe_path(raw.get("filename"))
        status = raw.get("status")
        if path is None or status not in STATUSES:
            continue
        entry: dict[str, Any] = {
            "path": path,
            "status": status,
            "additions": int(raw.get("additions") or 0),
            "deletions": int(raw.get("deletions") or 0),
            "patch": None,
            "patch_note": None,
        }
        patch = raw.get("patch")
        if is_generated(path):
            entry["patch_note"] = "generated_or_lockfile"
        elif not isinstance(patch, str):
            entry["patch_note"] = "not_provided"  # binary, or too large for GitHub to include
        elif total_patch >= MAX_TOTAL_PATCH_BYTES:
            entry["patch_note"] = "size_limit"
        else:
            room = min(MAX_PATCH_BYTES_PER_FILE, MAX_TOTAL_PATCH_BYTES - total_patch)
            if len(patch) > room:
                patch = patch[:room].rsplit("\n", 1)[0]
                entry["patch_note"] = "truncated"
            # Mask secrets and emails *before* the patch is stored anywhere, even in
            # Keelwatch's own checkpoints. Masking leaves [REDACTED:<kind>] on the same
            # line, which the secrets phase reads to report the finding.
            masked = redact(patch)
            entry["patch"] = masked.text
            redactions += masked.count
            total_patch += len(patch)
        kept.append(entry)

    return {
        "base": base,
        "head": head,
        "files": kept,
        "files_truncated": bool(truncated) or len(files) > MAX_FILES,
        "patch_bytes": total_patch,
        "redactions": redactions,
        "github_requests": gh.requests_made,
    }


# ----- secrets ------------------------------------------------------------------------


def secrets_phase(ctx: RunContext, budget: Budget) -> dict[str, Any]:
    return {"findings": scan_secrets(_changes(ctx)["files"])}


# ----- dependencies ------------------------------------------------------------------


def dependencies_phase(ctx: RunContext, budget: Budget) -> dict[str, Any]:
    extract = _changes(ctx)
    manifests = [f for f in extract["files"] if manifest_ecosystem(f["path"])][:MAX_MANIFESTS]
    if not manifests:
        return {"manifests": [], "changes": [], "findings": [], "osv": "not_needed"}
    gh = ctx.github
    name = ctx.repository["full_name"]
    installation = ctx.envelope["installation"]["github_id"]
    base, head = extract["base"], extract["head"]

    findings: list[dict[str, Any]] = []
    changes_out: list[dict[str, Any]] = []
    to_query: list[tuple[str, str, str]] = []
    change_index: dict[tuple[str, str, str], tuple[deps.Change, str]] = {}

    for f in manifests:
        path, ecosystem = f["path"], manifest_ecosystem(f["path"])
        before_text = (
            _github_call(gh.file_content, name, path, base, installation, budget)
            if base and f["status"] != "added"
            else None
        )
        after_text = (
            _github_call(gh.file_content, name, path, head, installation, budget)
            if f["status"] != "removed"
            else None
        )
        try:
            before, after = deps.parse(ecosystem, before_text), deps.parse(ecosystem, after_text)
        except deps.ManifestError as exc:
            findings.append(
                make_finding(
                    phase=deps.PHASE,
                    severity="info",
                    category="dependency",
                    confidence="high",
                    title="Manifest could not be parsed",
                    description=f"{path}: {exc}",
                    source="rule",
                    rule_id="deps.unparseable-manifest",
                    file_path=path,
                )
            )
            continue

        for change in deps.diff(ecosystem, before, after):
            changes_out.append(
                {
                    "file": path,
                    "ecosystem": ecosystem,
                    "section": change.section,
                    "name": change.name,
                    "before": change.before,
                    "after": change.after,
                    "kind": change.kind,
                }
            )
            findings.extend(deps.rules_for(change, path))
            version = deps.exact_version(ecosystem, change.after)
            if version:
                key = (ecosystem, change.name, version)
                to_query.append(key)
                change_index[key] = (change, path)

    osv_status = "not_needed"
    if to_query:
        if ctx.osv is None:
            osv_status = "disabled"
        else:
            try:
                results = ctx.osv.vulnerabilities(to_query, budget)
                osv_status = "checked"
                for key, advisories in results.items():
                    if advisories:
                        change, path = change_index[key]
                        findings.append(
                            deps.vulnerability_finding(change, key[2], advisories, path)
                        )
            except OsvUnavailable as exc:
                # A lookup gap is reported, not treated as "no vulnerabilities".
                osv_status = f"unavailable: {exc}"[:200]

    return {
        "manifests": [f["path"] for f in manifests],
        "changes": changes_out[:200],
        "findings": findings,
        "osv": osv_status,
        "osv_queried": len(set(to_query)),
    }


# ----- structure ----------------------------------------------------------------------


def structure_phase(ctx: RunContext, budget: Budget) -> dict[str, Any]:
    m = structure.metrics(_changes(ctx)["files"])
    return {"metrics": m, "findings": structure.findings(m)}


# ----- context --------------------------------------------------------------------------


def context_phase(ctx: RunContext, budget: Budget) -> dict[str, Any]:
    files = [f for f in _changes(ctx)["files"] if f.get("patch")]
    if not files:
        raise PhaseSkipped("no reviewable diff text")
    # Largest additions first: that's where most review attention belongs.
    files.sort(key=lambda f: f["additions"], reverse=True)
    parts: list[str] = []
    used = 0
    included = 0
    for f in files:
        block = (
            f"### {f['path']} ({f['status']}, +{f['additions']} -{f['deletions']})\n{f['patch']}\n"
        )
        if used + len(block) > MAX_CONTEXT_CHARS:
            continue
        parts.append(block)
        used += len(block)
        included += 1
    if not parts:
        raise PhaseSkipped("every diff was larger than the context limit")
    masked = redact("\n".join(parts))
    return {
        "text": masked.text,
        "redactions": masked.count,
        "files_included": included,
        "files_omitted": len(files) - included,
    }


# ----- normalize_findings --------------------------------------------------------------

_FINGERPRINT = re.compile(r"[0-9a-f]{64}")


def _valid(finding: dict[str, Any]) -> bool:
    """Runtime check mirroring contracts/finding.v1.json and the table's constraints."""
    try:
        location = finding["location"]
        if location is not None and _safe_path(location["file_path"]) is None:
            return False
        return (
            finding["schema_version"] == 1
            and _FINGERPRINT.fullmatch(finding["fingerprint"]) is not None
            and finding["severity"] in ("critical", "high", "medium", "low", "info")
            and finding["category"]
            in ("security", "logic", "dependency", "architecture", "quality")
            and finding["confidence"] in ("low", "medium", "high")
            and 0 < len(finding["title"]) <= 200
            and 0 < len(finding["description"]) <= 8000
            and finding["source"] in ("rule", "llm", "osv")
            and (finding["source"] != "rule" or bool(finding["rule_id"]))
            and (finding["source"] != "llm" or bool(finding["provider"] and finding["model"]))
        )
    except (KeyError, TypeError):
        return False


def llm_findings(review: dict[str, Any]) -> list[dict[str, Any]]:
    out = []
    for obs in review.get("observations") or []:
        out.append(
            make_finding(
                phase="llm_review",
                severity="low",
                category="quality",
                confidence=obs["confidence"],
                title=obs["title"],
                description=obs["detail"] or obs["title"],
                source="llm",
                evidence={"kind": "code", "snippet": obs["evidence"][:2000], "redacted": True},
                provider=review["provider"],
                model=review["model"],
                identity=obs["evidence"],
            )
        )
    return out


def normalize_findings(ctx: RunContext, budget: Budget) -> dict[str, Any]:
    candidates: list[dict[str, Any]] = []
    for phase in ("secrets", "dependencies", "structure"):
        candidates.extend((ctx.state.get(phase) or {}).get("findings") or [])
    review = ctx.state.get("llm_review") or {}
    if review.get("observations"):
        candidates.extend(llm_findings(review))

    valid = [f for f in candidates if _valid(f)]
    inserted = 0
    with ctx.conn.cursor() as cur:
        for f in valid:
            loc = f["location"] or {}
            cur.execute(
                """
                INSERT IGNORE INTO analysis_findings
                    (run_id, repository_id, phase, fingerprint, severity, category, confidence,
                     title, description, evidence, file_path, line_start, line_end,
                     recommendation, source, rule_id, provider, model)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                """,
                (
                    ctx.run["id"],
                    ctx.repository["id"],
                    f["phase"],
                    f["fingerprint"],
                    f["severity"],
                    f["category"],
                    f["confidence"],
                    f["title"],
                    f["description"],
                    json.dumps(f["evidence"]) if f["evidence"] is not None else None,
                    loc.get("file_path"),
                    loc.get("line_start"),
                    loc.get("line_end"),
                    f["recommendation"],
                    f["source"],
                    f["rule_id"],
                    f["provider"],
                    f["model"],
                ),
            )
            inserted += cur.rowcount

    by_severity: dict[str, int] = {}
    for f in valid:
        by_severity[f["severity"]] = by_severity.get(f["severity"], 0) + 1
    return {
        "candidates": len(candidates),
        "invalid_dropped": len(candidates) - len(valid),
        "inserted": inserted,
        "by_severity": by_severity,
    }

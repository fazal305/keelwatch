"""Plain-text notification messages built from stored digests.

Rules, in order of importance:
1. Never include code snippets, evidence, or model-quoted text.
2. Private repositories: no repository name, file paths or finding titles;
   counts only, plus a dashboard link when one is configured.
3. Everything passes through redact() as a final safety net.
"""

from __future__ import annotations

from typing import Any

from ..redaction import redact

SEVERITY_ORDER = ("critical", "high", "medium", "low", "info")


def severity_rank(severity: str) -> int:
    return SEVERITY_ORDER.index(severity) if severity in SEVERITY_ORDER else len(SEVERITY_ORDER)


def meets_threshold(by_severity: dict[str, int], min_severity: str) -> bool:
    limit = severity_rank(min_severity)
    return any(count > 0 and severity_rank(sev) <= limit for sev, count in by_severity.items())


def counts_line(by_severity: dict[str, int]) -> str:
    parts = [f"{by_severity[s]} {s}" for s in SEVERITY_ORDER if by_severity.get(s)]
    return ", ".join(parts) if parts else "no findings"


def _link(dashboard_url: str | None, path: str) -> str | None:
    return f"{dashboard_url.rstrip('/')}{path}" if dashboard_url else None


def render_run(digest: dict[str, Any], dashboard_url: str | None) -> str:
    repo = digest["repository"]
    private = repo["private"]
    trigger = digest["trigger"]
    what = (
        f"PR #{trigger['pull_request']}"
        if trigger.get("pull_request")
        else f"push {trigger.get('head_sha_short') or ''}".strip()
    )
    name = "a private repository" if private else repo["full_name"]
    findings = digest["findings"]
    lines = [
        f"Keelwatch · {name} · {what}",
        f"Findings: {counts_line(findings['by_severity'])}"
        + (
            f" ({findings['new']} new, {findings['recurring']} recurring)"
            if findings["total"]
            else ""
        ),
    ]
    if not private:
        for f in findings["top"]:
            if f.get("source") == "llm":
                continue  # model-written titles stay in the dashboard, not in chat
            where = (
                f" — {f['file_path']}" + (f":{f['line']}" if f.get("line") else "")
                if f.get("file_path")
                else ""
            )
            lines.append(f"• [{f['severity']}] {f['title']}{where}")
    if digest.get("gaps"):
        lines.append("Not checked: " + "; ".join(digest["gaps"][:4]))
    link = _link(dashboard_url, f"/runs/{digest['run_id']}")
    if link:
        lines.append(("Details: " if private else "") + link)
    elif private:
        lines.append("Details are available in the Keelwatch dashboard.")
    lines.append("Signals are heuristic; confirm before acting.")
    return redact("\n".join(lines)).text


def render_daily(digest: dict[str, Any], dashboard_url: str | None) -> str:
    totals = digest["totals"]
    lines = [
        f"Keelwatch daily digest · {digest['period']['date']} (UTC)",
        f"{totals['runs']} analysis run(s), {totals['failed_runs']} failed · "
        f"findings: {counts_line(totals['by_severity'])} ({totals['new_findings']} new)",
    ]
    private_count = 0
    for repo in digest["repositories"]:
        if repo["private"]:
            private_count += 1
            continue
        lines.append(
            f"• {repo['full_name']}: {repo['runs']} run(s), {counts_line(repo['by_severity'])}"
        )
    if private_count:
        lines.append(
            f"• {private_count} private repositor{'y' if private_count == 1 else 'ies'} (details in the dashboard)"
        )
    link = _link(dashboard_url, "/digests")
    if link:
        lines.append(link)
    return redact("\n".join(lines)).text

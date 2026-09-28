"""Change-shape metrics and two deliberately modest heuristics.

These describe the change, not the author. Thresholds are configurable
defaults, and the findings say plainly that they are heuristics.
"""

from __future__ import annotations

from typing import Any

from .diff import is_generated, is_source, is_test, top_level_area
from .findings import make_finding

PHASE = "structure"
LARGE_CHANGE_LINES = 800
UNTESTED_MIN_ADDED = 50


def metrics(files: list[dict[str, Any]]) -> dict[str, Any]:
    reviewed = [f for f in files if not is_generated(f["path"])]
    source = [f for f in reviewed if is_source(f["path"]) and not is_test(f["path"])]
    tests = [f for f in reviewed if is_test(f["path"])]
    return {
        "files_changed": len(files),
        "files_generated_or_lockfile": len(files) - len(reviewed),
        "lines_added": sum(f["additions"] for f in reviewed),
        "lines_deleted": sum(f["deletions"] for f in reviewed),
        "source_files": len(source),
        "source_lines_added": sum(f["additions"] for f in source),
        "test_files": len(tests),
        "areas_touched": sorted({top_level_area(f["path"]) for f in reviewed})[:50],
        "new_files": sum(1 for f in reviewed if f["status"] == "added"),
    }


def findings(m: dict[str, Any]) -> list[dict[str, Any]]:
    out = []
    changed = m["lines_added"] + m["lines_deleted"]
    if changed > LARGE_CHANGE_LINES:
        out.append(
            make_finding(
                phase=PHASE,
                severity="low",
                category="quality",
                confidence="medium",
                title="Large change",
                description=(
                    f"{changed} lines changed across {m['files_changed'] - m['files_generated_or_lockfile']} "
                    f"files (excluding generated files and lockfiles). Large changes are harder to review "
                    "thoroughly; this is a heuristic, not a defect."
                ),
                source="rule",
                rule_id="structure.large-change",
                evidence={
                    "kind": "metric",
                    "metric": "lines_changed",
                    "value": changed,
                    "threshold": LARGE_CHANGE_LINES,
                },
                recommendation="Consider splitting it into smaller, independently reviewable changes.",
            )
        )
    if m["source_lines_added"] >= UNTESTED_MIN_ADDED and m["test_files"] == 0:
        out.append(
            make_finding(
                phase=PHASE,
                severity="info",
                category="quality",
                confidence="low",
                title="Source changed without test changes",
                description=(
                    f"{m['source_lines_added']} source lines were added and no test files changed. "
                    "Existing tests may already cover this; the signal only notes the absence of test edits."
                ),
                source="rule",
                rule_id="structure.no-test-changes",
                evidence={
                    "kind": "metric",
                    "metric": "source_lines_added",
                    "value": m["source_lines_added"],
                    "threshold": UNTESTED_MIN_ADDED,
                },
                recommendation="Check whether the new behaviour is covered by tests.",
            )
        )
    return out

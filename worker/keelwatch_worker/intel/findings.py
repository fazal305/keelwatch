"""Builds findings in the contracts/finding.v1.json shape.

The fingerprint deliberately ignores line numbers: the same issue in the
same file keeps its fingerprint when surrounding code moves, so a later run
can tell "recurring" from "new".
"""

from __future__ import annotations

import hashlib
import re
from typing import Any

SEVERITIES = ("critical", "high", "medium", "low", "info")
CATEGORIES = ("security", "logic", "dependency", "architecture", "quality")
CONFIDENCES = ("low", "medium", "high")
_RULE_ID = re.compile(r"[a-z0-9][a-z0-9._-]{0,99}")


def fingerprint(*parts: str | None) -> str:
    normalised = "\x1f".join(" ".join((p or "").split()).lower() for p in parts)
    return hashlib.sha256(normalised.encode("utf-8")).hexdigest()


def make_finding(
    *,
    phase: str,
    severity: str,
    category: str,
    confidence: str,
    title: str,
    description: str,
    source: str,
    rule_id: str | None = None,
    evidence: dict[str, Any] | None = None,
    file_path: str | None = None,
    line: int | None = None,
    recommendation: str | None = None,
    provider: str | None = None,
    model: str | None = None,
    identity: str | None = None,
) -> dict[str, Any]:
    if severity not in SEVERITIES or category not in CATEGORIES or confidence not in CONFIDENCES:
        raise ValueError(f"invalid finding classification: {severity}/{category}/{confidence}")
    if source == "rule" and (rule_id is None or not _RULE_ID.fullmatch(rule_id)):
        raise ValueError("rule findings need a valid rule_id")
    if source == "llm" and (not provider or not model):
        raise ValueError("llm findings need provider and model")

    location = None
    if file_path is not None:
        location = {"file_path": file_path[:1024], "line_start": line, "line_end": line}

    snippet = (evidence or {}).get("snippet") or (evidence or {}).get("package") or ""
    return {
        "schema_version": 1,
        "fingerprint": fingerprint(rule_id or source, file_path, identity or snippet or title),
        "phase": phase,
        "severity": severity,
        "category": category,
        "confidence": confidence,
        "title": title[:200],
        "description": description[:8000],
        "evidence": evidence,
        "location": location,
        "recommendation": recommendation[:4000] if recommendation else None,
        "source": source,
        "rule_id": rule_id,
        "provider": provider,
        "model": model,
    }

"""Secrets and insecure patterns in *added* lines of a change.

Only what the change introduces is reported, with file and line. Evidence
is stored masked: a finding never contains the secret it reports.
Pattern rules are signals, not proof, so confidence is stated per rule.
"""

from __future__ import annotations

import re
from pathlib import PurePosixPath
from typing import Any

from ..redaction import CREDENTIAL_PATTERNS, has_assigned_secret, redact
from .diff import added_lines, is_generated, is_test
from .findings import make_finding

PHASE = "secrets"

_SENSITIVE_FILE = re.compile(
    r"(?:^|/)(?:\.env(?:\.(?!example$|sample$|template$|dist$)[\w.-]+)?|id_(?:rsa|dsa|ecdsa|ed25519)"
    r"|[\w.-]+\.(?:pem|key|p12|pfx|keystore|jks)|credentials\.json|\.npmrc|\.pypirc)$",
    re.IGNORECASE,
)

# (rule_id, pattern, severity, category, confidence, title, recommendation, applies_to_suffixes)
_INSECURE: tuple[tuple[str, re.Pattern[str], str, str, str, str, str, tuple[str, ...]], ...] = (
    (
        "config.tls-verification-disabled",
        re.compile(
            r"verify\s*=\s*False|rejectUnauthorized\s*:\s*false|NODE_TLS_REJECT_UNAUTHORIZED\s*=\s*['\"]?0"
            r"|CURLOPT_SSL_VERIFYPEER\s*,\s*(?:false|0)|InsecureSkipVerify\s*:\s*true"
        ),
        "high",
        "security",
        "medium",
        "TLS certificate verification disabled",
        "Keep certificate verification on; trust a specific CA bundle instead if needed.",
        (),
    ),
    (
        "web.raw-html-sink",
        re.compile(
            r"dangerouslySetInnerHTML|\.innerHTML\s*=|\.outerHTML\s*=|insertAdjacentHTML\(|v-html="
        ),
        "medium",
        "security",
        "low",
        "Raw HTML sink (possible XSS)",
        "Render data as text, or pass it through a vetted sanitizer before inserting HTML.",
        (".js", ".jsx", ".ts", ".tsx", ".vue", ".svelte", ".html", ".mjs"),
    ),
    (
        "code.dynamic-eval",
        re.compile(r"(?<![\w.])eval\s*\(|new\s+Function\s*\("),
        "medium",
        "security",
        "low",
        "Dynamic code evaluation",
        "Avoid evaluating strings as code; parse data explicitly instead.",
        (".js", ".jsx", ".ts", ".tsx", ".mjs", ".cjs", ".py", ".php"),
    ),
    (
        "config.debug-enabled",
        re.compile(r"^\s*(?:DEBUG\s*=\s*True|APP_DEBUG\s*=\s*true)\b"),
        "low",
        "security",
        "low",
        "Debug mode enabled in committed configuration",
        "Drive debug mode from environment configuration, off by default.",
        (),
    ),
)


_MARKER = re.compile(r"\[REDACTED:([a-z_]+)\]")
_CREDENTIAL_KINDS = frozenset(kind for kind, _ in CREDENTIAL_PATTERNS)


def _credential_kind(text: str) -> str | None:
    """A credential in raw text, or one already masked at extraction time
    (patches are redacted before they are stored, leaving a marker)."""
    for kind, pattern in CREDENTIAL_PATTERNS:
        if pattern.search(text):
            return kind
    for marker in _MARKER.finditer(text):
        if marker.group(1) in _CREDENTIAL_KINDS:
            return marker.group(1)
    return None


def _has_assigned_secret(text: str) -> bool:
    return has_assigned_secret(text) or "[REDACTED:assigned_secret]" in text


def _snippet(line: str) -> tuple[str, bool]:
    masked = redact(line.strip())
    return masked.text[:200], masked.count > 0


def scan(files: list[dict[str, Any]]) -> list[dict[str, Any]]:
    findings: list[dict[str, Any]] = []
    for f in files:
        path, status = f["path"], f["status"]

        if status != "removed" and _SENSITIVE_FILE.search(path):
            findings.append(
                make_finding(
                    phase=PHASE,
                    severity="high",
                    category="security",
                    confidence="medium",
                    title="Sensitive file committed",
                    description=f"{PurePosixPath(path).name} looks like a secrets or key file and was {status}.",
                    source="rule",
                    rule_id="secrets.sensitive-file",
                    evidence={"kind": "code", "snippet": path[:200], "redacted": False},
                    file_path=path,
                    recommendation="Remove it from the repository, rotate anything it contained, and ignore it.",
                )
            )

        if is_generated(path):
            continue
        suffix = PurePosixPath(path).suffix.lower()
        in_test = is_test(path)

        for line_no, text in added_lines(f.get("patch")):
            kind = _credential_kind(text)
            if kind is not None:
                snippet, _ = _snippet(text)
                findings.append(
                    make_finding(
                        phase=PHASE,
                        severity="medium" if in_test else "critical",
                        category="security",
                        confidence="high",
                        title=f"Possible {kind.replace('_', ' ')} committed",
                        description=(
                            f"An added line matches the format of a {kind.replace('_', ' ')}"
                            + (" in a test file." if in_test else ".")
                        ),
                        source="rule",
                        rule_id=f"secrets.{kind.replace('_', '-')}",
                        evidence={"kind": "code", "snippet": snippet, "redacted": True},
                        file_path=path,
                        line=line_no,
                        recommendation="Revoke and rotate the credential, then load it from server-side configuration.",
                        identity=f"{kind}:{snippet}",
                    )
                )
            elif _has_assigned_secret(text):
                snippet, _ = _snippet(text)
                findings.append(
                    make_finding(
                        phase=PHASE,
                        severity="low" if in_test else "high",
                        category="security",
                        confidence="medium",
                        title="Hard-coded secret value",
                        description="A secret-named setting is assigned a literal value in the change.",
                        source="rule",
                        rule_id="secrets.assigned-secret",
                        evidence={"kind": "code", "snippet": snippet, "redacted": True},
                        file_path=path,
                        line=line_no,
                        recommendation="Read the value from environment configuration instead.",
                        identity=snippet,
                    )
                )

            for (
                rule_id,
                pattern,
                severity,
                category,
                confidence,
                title,
                advice,
                suffixes,
            ) in _INSECURE:
                if suffixes and suffix not in suffixes:
                    continue
                if pattern.search(text):
                    snippet, masked = _snippet(text)
                    findings.append(
                        make_finding(
                            phase=PHASE,
                            severity="low" if in_test else severity,
                            category=category,
                            confidence=confidence,
                            title=title,
                            description=f"Added line {line_no} matches a pattern associated with this risk.",
                            source="rule",
                            rule_id=rule_id,
                            evidence={"kind": "code", "snippet": snippet, "redacted": masked},
                            file_path=path,
                            line=line_no,
                            recommendation=advice,
                            identity=snippet,
                        )
                    )
    return findings

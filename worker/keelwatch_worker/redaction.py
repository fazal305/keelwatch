"""Masks secrets and personal data in text before it leaves Keelwatch
(LLM context now; notifications in Phase 7).

Detection is heuristic: patterns for well-known credential formats plus
assignment-style secrets. It reduces risk; it cannot guarantee that no
secret survives, which is why private code is not sent at all by default
(ADR 0003).
"""

from __future__ import annotations

import re
from dataclasses import dataclass

_PATTERNS: tuple[tuple[str, re.Pattern[str]], ...] = (
    (
        "private_key",
        re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----", re.S),
    ),
    (
        "github_token",
        re.compile(r"\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,})\b"),
    ),
    ("aws_access_key", re.compile(r"\b(?:AKIA|ASIA)[0-9A-Z]{16}\b")),
    ("google_api_key", re.compile(r"\bAIza[0-9A-Za-z_-]{35}\b")),
    ("slack_token", re.compile(r"\bxox[abposr]-[A-Za-z0-9-]{10,}\b")),
    ("slack_webhook", re.compile(r"https://hooks\.slack\.com/services/[A-Za-z0-9/_-]+")),
    (
        "discord_webhook",
        re.compile(r"https://(?:discord|discordapp)\.com/api/webhooks/[0-9]+/[A-Za-z0-9_-]+"),
    ),
    ("groq_key", re.compile(r"\bgsk_[A-Za-z0-9]{20,}\b")),
    ("openai_style_key", re.compile(r"\bsk-(?:proj-)?[A-Za-z0-9_-]{20,}\b")),
    ("stripe_key", re.compile(r"\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{16,}\b")),
    ("jwt", re.compile(r"\beyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b")),
    ("url_credentials", re.compile(r"(?<=://)[^/\s:@]+:[^/\s@]+(?=@)")),
    ("email", re.compile(r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b")),
)

# key = "value" / key: 'value' / KEY=value where the key names a secret.
_ASSIGNMENT = re.compile(
    r"""(?ix)
    (?P<key>\b[\w.-]*(?:password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key|
        private[_-]?key|client[_-]?secret|auth)[\w.-]*)
    (?P<sep>\s*[:=]\s*)
    (?P<quote>["']?)
    (?P<value>[^\s"'`,;]{6,})
    (?P=quote)
    """
)

# Values that look like placeholders are not secrets worth masking.
_PLACEHOLDER = re.compile(
    r"(?i)^(?:x+|\*+|changeme|example|your[_-].*|<.*>|\$\{.*\}|process\.env.*|os\.environ.*|null|none|true|false)$"
)


@dataclass(frozen=True)
class Redacted:
    text: str
    findings: dict[str, int]

    @property
    def count(self) -> int:
        return sum(self.findings.values())


def redact(text: str) -> Redacted:
    findings: dict[str, int] = {}

    def mask(kind: str) -> str:
        findings[kind] = findings.get(kind, 0) + 1
        return f"[REDACTED:{kind}]"

    for kind, pattern in _PATTERNS:
        text = pattern.sub(lambda _m, k=kind: mask(k), text)

    def assignment(m: re.Match[str]) -> str:
        value = m.group("value")
        if value.startswith("[REDACTED:") or _PLACEHOLDER.match(value):
            return m.group(0)
        quote = m.group("quote")
        return f"{m.group('key')}{m.group('sep')}{quote}{mask('assigned_secret')}{quote}"

    text = _ASSIGNMENT.sub(assignment, text)
    return Redacted(text, findings)

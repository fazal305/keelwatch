"""Structured JSON-lines logging with key-based redaction.

Same record shape as the PHP API (ts, level, service, msg, correlation_id,
ctx) so logs from both services can be read and filtered together.
"""

from __future__ import annotations

import json
import re
import sys
from datetime import UTC, datetime
from typing import Any, TextIO

REDACTED = "[REDACTED]"
_SENSITIVE_KEY = re.compile(
    r"pass|secret|token|authorization|signature|cookie|api[_-]?key|private[_-]?key|credential",
    re.IGNORECASE,
)


def redact(value: Any) -> Any:
    if isinstance(value, dict):
        return {
            key: REDACTED if isinstance(key, str) and _SENSITIVE_KEY.search(key) else redact(item)
            for key, item in value.items()
        }
    if isinstance(value, list | tuple):
        return [redact(item) for item in value]
    return value


class Logger:
    def __init__(
        self,
        service: str,
        stream: TextIO | None = None,
        correlation_id: str | None = None,
    ) -> None:
        self._service = service
        self._stream = stream or sys.stderr
        self._correlation_id = correlation_id

    def with_correlation_id(self, correlation_id: str) -> Logger:
        return Logger(self._service, self._stream, correlation_id)

    def info(self, msg: str, **ctx: Any) -> None:
        self._write("info", msg, ctx)

    def warning(self, msg: str, **ctx: Any) -> None:
        self._write("warning", msg, ctx)

    def error(self, msg: str, **ctx: Any) -> None:
        self._write("error", msg, ctx)

    def _write(self, level: str, msg: str, ctx: dict[str, Any]) -> None:
        record: dict[str, Any] = {
            "ts": datetime.now(UTC).isoformat(timespec="milliseconds").replace("+00:00", "Z"),
            "level": level,
            "service": self._service,
            "msg": msg,
        }
        if self._correlation_id:
            record["correlation_id"] = self._correlation_id
        if ctx:
            record["ctx"] = redact(ctx)
        self._stream.write(json.dumps(record, default=str, ensure_ascii=False) + "\n")
        self._stream.flush()

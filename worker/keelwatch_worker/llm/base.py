"""The provider contract: analyze(context, task, constraints) -> ProviderResult.

Business logic depends only on these types; model and provider names come
from configuration (see llm/config.py).
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Literal, Protocol

# What the operator has confirmed about a provider's data handling. Unknown
# is treated like "may train": private code is never sent there.
DataPolicy = Literal["no_training", "may_train"]


@dataclass(frozen=True)
class AnalysisContext:
    """Bounded, already-redacted material for the model. Built by the
    context phase; providers never read the repository themselves."""

    repository_full_name: str
    repository_private: bool
    text: str
    redactions: int = 0


@dataclass(frozen=True)
class TaskSpec:
    name: str  # e.g. "review_findings"
    instructions: str
    response_schema_hint: str  # a compact JSON example of the expected answer


@dataclass(frozen=True)
class Constraints:
    timeout_s: float
    max_output_tokens: int = 1024
    temperature: float = 0.0


@dataclass(frozen=True)
class ProviderResult:
    provider: str
    model: str
    content: dict[str, Any]
    latency_ms: float
    tokens_in: int | None = None
    tokens_out: int | None = None
    raw_finish_reason: str | None = None
    extra: dict[str, Any] = field(default_factory=dict)


class ProviderError(Exception):
    """A provider call failed. `retryable` says whether trying again (here or
    on another provider) could help."""

    outcome = "error"

    def __init__(self, message: str, *, retryable: bool = True) -> None:
        super().__init__(message)
        self.retryable = retryable


class ProviderTimeout(ProviderError):
    outcome = "timeout"


class ProviderRateLimited(ProviderError):
    outcome = "rate_limited"

    def __init__(self, message: str, retry_after_s: float | None = None) -> None:
        super().__init__(message, retryable=True)
        self.retry_after_s = retry_after_s


class ProviderRejected(ProviderError):
    """Bad credentials, bad request, or an unusable answer: retrying the same
    provider won't help."""

    outcome = "rejected"

    def __init__(self, message: str) -> None:
        super().__init__(message, retryable=False)


class LLMProvider(Protocol):
    name: str
    model: str
    data_policy: DataPolicy

    def analyze(
        self, context: AnalysisContext, task: TaskSpec, constraints: Constraints
    ) -> ProviderResult: ...

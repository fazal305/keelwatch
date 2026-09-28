"""Chooses, calls and falls back between providers.

Order of concerns for every analysis request:
1. privacy policy (ADR 0003) decides which providers may see this repo;
2. circuit breakers skip providers that keep failing;
3. each call's timeout and every retry/backoff fit inside the budget;
4. every attempt is recorded (provider, model, outcome, latency, tokens).
"""

from __future__ import annotations

import time
from collections.abc import Callable
from dataclasses import dataclass

from ..budget import Budget
from .base import (
    AnalysisContext,
    Constraints,
    LLMProvider,
    ProviderError,
    ProviderRateLimited,
    ProviderResult,
    TaskSpec,
)

MIN_CALL_S = 1.0  # don't start a call that can't reasonably finish


@dataclass(frozen=True)
class CallRecord:
    provider: str
    model: str
    outcome: str  # success | timeout | rate_limited | error | rejected
    latency_ms: float
    retry_no: int
    tokens_in: int | None = None
    tokens_out: int | None = None
    error: str | None = None


@dataclass(frozen=True)
class RouterOutcome:
    result: ProviderResult | None
    reason: str  # "ok" or why no answer was produced

    @property
    def ok(self) -> bool:
        return self.result is not None


def eligible(provider: LLMProvider, repository_private: bool, llm_policy: str) -> bool:
    if llm_policy == "none":
        return False
    if repository_private:
        # 'public_only' means nothing is sent while the repo is private; even
        # 'allowed' only reaches providers confirmed not to train on inputs.
        return llm_policy == "allowed" and provider.data_policy == "no_training"
    return llm_policy in ("public_only", "allowed")


class CircuitBreaker:
    def __init__(
        self, threshold: int, cooldown_s: float, clock: Callable[[], float] = time.monotonic
    ):
        self._threshold = threshold
        self._cooldown_s = cooldown_s
        self._clock = clock
        self._failures = 0
        self._opened_at: float | None = None

    def allow(self) -> bool:
        if self._opened_at is None:
            return True
        if self._clock() - self._opened_at >= self._cooldown_s:
            # Half-open: let one call through; its result decides.
            self._opened_at = None
            self._failures = self._threshold - 1
            return True
        return False

    def success(self) -> None:
        self._failures = 0
        self._opened_at = None

    def failure(self) -> None:
        self._failures += 1
        if self._failures >= self._threshold:
            self._opened_at = self._clock()

    @property
    def is_open(self) -> bool:
        return self._opened_at is not None


class Router:
    def __init__(
        self,
        providers: list[LLMProvider],
        *,
        preferred_timeout_s: float = 30.0,
        max_attempts_per_provider: int = 2,
        retry_base_s: float = 1.0,
        max_rate_limit_wait_s: float = 10.0,
        breaker_threshold: int = 3,
        breaker_cooldown_s: float = 120.0,
        clock: Callable[[], float] = time.monotonic,
        sleep: Callable[[float], None] = time.sleep,
    ) -> None:
        self.providers = providers
        self._preferred_timeout_s = preferred_timeout_s
        self._max_attempts = max_attempts_per_provider
        self._retry_base_s = retry_base_s
        self._max_rate_limit_wait_s = max_rate_limit_wait_s
        self._sleep = sleep
        self._breakers = {
            p.name: CircuitBreaker(breaker_threshold, breaker_cooldown_s, clock) for p in providers
        }

    def breaker(self, provider_name: str) -> CircuitBreaker:
        return self._breakers[provider_name]

    def analyze(
        self,
        context: AnalysisContext,
        task: TaskSpec,
        budget: Budget,
        *,
        llm_policy: str,
        record: Callable[[CallRecord], None],
        max_output_tokens: int = 1024,
    ) -> RouterOutcome:
        if not self.providers:
            return RouterOutcome(None, "no_providers_configured")

        candidates = [
            p for p in self.providers if eligible(p, context.repository_private, llm_policy)
        ]
        if not candidates:
            return RouterOutcome(None, "blocked_by_privacy_policy")

        tried_any = False
        for provider in candidates:
            if not self._breakers[provider.name].allow():
                continue
            tried_any = True
            outcome = self._try_provider(provider, context, task, budget, record, max_output_tokens)
            if outcome is not None:
                return outcome
            if budget.remaining_ms() < MIN_CALL_S * 1000:
                return RouterOutcome(None, "budget_exhausted")

        return RouterOutcome(
            None, "all_providers_failed" if tried_any else "all_providers_circuit_open"
        )

    def _try_provider(
        self,
        provider: LLMProvider,
        context: AnalysisContext,
        task: TaskSpec,
        budget: Budget,
        record: Callable[[CallRecord], None],
        max_output_tokens: int,
    ) -> RouterOutcome | None:
        breaker = self._breakers[provider.name]

        for attempt in range(self._max_attempts):
            timeout_s = budget.timeout_s(self._preferred_timeout_s)
            if timeout_s < MIN_CALL_S:
                return None

            started = time.perf_counter()
            try:
                result = provider.analyze(
                    context,
                    task,
                    Constraints(timeout_s=timeout_s, max_output_tokens=max_output_tokens),
                )
            except ProviderError as exc:
                breaker.failure()
                record(
                    CallRecord(
                        provider.name,
                        provider.model,
                        exc.outcome,
                        (time.perf_counter() - started) * 1000,
                        attempt,
                        error=str(exc)[:500],
                    )
                )
                if not exc.retryable or breaker.is_open or attempt + 1 >= self._max_attempts:
                    return None
                wait = self._retry_wait(exc, attempt)
                if wait is None or wait * 1000 >= budget.remaining_ms() - MIN_CALL_S * 1000:
                    return None  # waiting would eat the budget; try the next provider
                self._sleep(wait)
                continue

            breaker.success()
            record(
                CallRecord(
                    provider.name,
                    provider.model,
                    "success",
                    result.latency_ms,
                    attempt,
                    tokens_in=result.tokens_in,
                    tokens_out=result.tokens_out,
                )
            )
            return RouterOutcome(result, "ok")
        return None

    def _retry_wait(self, exc: ProviderError, attempt: int) -> float | None:
        if isinstance(exc, ProviderRateLimited):
            if exc.retry_after_s is None:
                return self._retry_base_s * (2**attempt)
            if exc.retry_after_s > self._max_rate_limit_wait_s:
                return None  # a long rate limit: better to fall back now
            return exc.retry_after_s
        return self._retry_base_s * (2**attempt)

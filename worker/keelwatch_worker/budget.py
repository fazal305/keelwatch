"""Execution budget for one analysis run.

A Budget is a deadline on a monotonic, high-resolution clock. Everything
that can take time (a phase, a provider call, a retry, a backoff sleep)
asks the budget how much it may use, so no retry loop can outlive the run.
"""

from __future__ import annotations

import time
from collections.abc import Callable


class BudgetExceeded(Exception):
    """Raised when work cannot fit in the remaining budget."""


class Budget:
    def __init__(
        self,
        total_ms: int,
        reserve_ms: int = 2000,
        clock: Callable[[], float] = time.perf_counter,
    ) -> None:
        if total_ms <= 0:
            raise ValueError("budget must be positive")
        self._clock = clock
        self._start = clock()
        self.total_ms = total_ms
        # Held back so there is always time to write the checkpoint.
        self.reserve_ms = min(reserve_ms, total_ms // 2)

    def elapsed_ms(self) -> float:
        return (self._clock() - self._start) * 1000

    def remaining_ms(self) -> float:
        """Time left for work, excluding the checkpoint reserve."""
        return max(0.0, self.total_ms - self.reserve_ms - self.elapsed_ms())

    def expired(self) -> bool:
        return self.remaining_ms() <= 0

    def allowance_ms(self, cap_ms: float | None = None) -> float:
        remaining = self.remaining_ms()
        return remaining if cap_ms is None else min(cap_ms, remaining)

    def timeout_s(self, preferred_s: float) -> float:
        """A network timeout that never outlives the budget."""
        return max(0.0, min(preferred_s, self.remaining_ms() / 1000))

    def require(self, needed_ms: float, what: str) -> None:
        if self.remaining_ms() < needed_ms:
            raise BudgetExceeded(
                f"{what} needs ~{needed_ms:.0f} ms but only {self.remaining_ms():.0f} ms remain"
            )

    def child(self, cap_ms: float | None) -> Budget:
        """A budget for one phase: capped, and never longer than what's left."""
        allowance = int(self.allowance_ms(cap_ms))
        if allowance <= 0:
            raise BudgetExceeded("no time left for this phase")
        return Budget(allowance, reserve_ms=0, clock=self._clock)

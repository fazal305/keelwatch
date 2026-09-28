"""Validated worker settings.

Mirrors the API's rules: fail fast, report every problem at once, and name
the offending variable without ever echoing its value.
"""

from __future__ import annotations

import os
import socket
from collections.abc import Mapping
from dataclasses import dataclass

ENVIRONMENTS = ("development", "test", "production")


class ConfigError(Exception):
    def __init__(self, errors: list[str]) -> None:
        self.errors = errors
        super().__init__("Invalid configuration: " + "; ".join(errors))


@dataclass(frozen=True)
class Settings:
    app_env: str
    app_version: str
    db_host: str
    db_port: int
    db_name: str
    db_user: str
    db_password: str
    db_connect_timeout_s: int
    heartbeat_interval_s: int
    stale_after_s: int
    worker_id: str
    job_lease_s: int = 60
    poll_interval_s: int = 2
    retry_base_s: int = 5
    retry_max_s: int = 300
    analysis_budget_ms: int = 120_000
    phase_reserve_ms: int = 2000
    llm_timeout_s: int = 30
    llm_max_attempts: int = 2
    llm_breaker_failures: int = 3
    llm_breaker_cooldown_s: int = 120

    def with_database(self, name: str) -> Settings:
        return Settings(**{**self.__dict__, "db_name": name})

    def with_overrides(self, **changes: object) -> Settings:
        return Settings(**{**self.__dict__, **changes})

    def __repr__(self) -> str:  # never render the password, even in tracebacks
        return (
            f"Settings(app_env={self.app_env!r}, db={self.db_user}@{self.db_host}/{self.db_name})"
        )


def load_settings(env: Mapping[str, str]) -> Settings:
    errors: list[str] = []

    def required(key: str) -> str:
        value = (env.get(key) or "").strip()
        if not value:
            errors.append(f"{key} is required")
        return value

    def positive_int(key: str, default: int, maximum: int) -> int:
        raw = (env.get(key) or "").strip()
        if not raw:
            return default
        if not raw.isdigit() or not 1 <= int(raw) <= maximum:
            errors.append(f"{key} must be an integer between 1 and {maximum}")
            return default
        return int(raw)

    app_env = required("APP_ENV")
    if app_env and app_env not in ENVIRONMENTS:
        errors.append("APP_ENV must be one of: " + ", ".join(ENVIRONMENTS))

    heartbeat = positive_int("WORKER_HEARTBEAT_INTERVAL_S", 10, 300)
    stale_after = positive_int("WORKER_STALE_AFTER_S", 35, 3600)
    if stale_after <= heartbeat:
        errors.append("WORKER_STALE_AFTER_S must be greater than WORKER_HEARTBEAT_INTERVAL_S")

    worker_id = (env.get("WORKER_ID") or "").strip() or f"{socket.gethostname()}-{os.getpid()}"
    if len(worker_id) > 128:
        errors.append("WORKER_ID must be at most 128 characters")

    settings = Settings(
        app_env=app_env,
        app_version=(env.get("APP_VERSION") or "").strip() or "0.0.0",
        db_host=required("DB_HOST"),
        db_port=positive_int("DB_PORT", 3306, 65535),
        db_name=required("DB_NAME"),
        db_user=required("DB_USER"),
        db_password=required("DB_PASSWORD"),
        db_connect_timeout_s=positive_int("DB_CONNECT_TIMEOUT_S", 2, 30),
        heartbeat_interval_s=heartbeat,
        stale_after_s=stale_after,
        worker_id=worker_id,
        job_lease_s=positive_int("JOB_LEASE_S", 60, 3600),
        poll_interval_s=positive_int("WORKER_POLL_INTERVAL_S", 2, 60),
        retry_base_s=positive_int("JOB_RETRY_BASE_S", 5, 3600),
        retry_max_s=positive_int("JOB_RETRY_MAX_S", 300, 86400),
        # Matches the analysis_runs CHECK constraint (1 s .. 1 h).
        analysis_budget_ms=positive_int("ANALYSIS_BUDGET_MS", 120_000, 3_600_000),
        phase_reserve_ms=positive_int("PHASE_CHECKPOINT_RESERVE_MS", 2000, 60_000),
        llm_timeout_s=positive_int("LLM_TIMEOUT_S", 30, 600),
        llm_max_attempts=positive_int("LLM_MAX_ATTEMPTS", 2, 5),
        llm_breaker_failures=positive_int("LLM_BREAKER_FAILURES", 3, 50),
        llm_breaker_cooldown_s=positive_int("LLM_BREAKER_COOLDOWN_S", 120, 3600),
    )
    if settings.analysis_budget_ms < 1000:
        errors.append("ANALYSIS_BUDGET_MS must be at least 1000")
    if settings.retry_max_s < settings.retry_base_s:
        errors.append("JOB_RETRY_MAX_S must be at least JOB_RETRY_BASE_S")

    if errors:
        raise ConfigError(errors)
    return settings

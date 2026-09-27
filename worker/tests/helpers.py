from __future__ import annotations

import io

from keelwatch_worker.config import Settings, load_settings
from keelwatch_worker.logs import Logger

FAKE_PASSWORD = "not-a-real-password-7f3a"


def env(**overrides: str) -> dict[str, str]:
    base = {
        "APP_ENV": "test",
        "APP_VERSION": "9.9.9",
        "DB_HOST": "127.0.0.1",
        "DB_PORT": "3306",
        "DB_NAME": "keelwatch_unit",
        "DB_USER": "unit_user",
        "DB_PASSWORD": FAKE_PASSWORD,
        "WORKER_HEARTBEAT_INTERVAL_S": "10",
        "WORKER_STALE_AFTER_S": "35",
        "WORKER_ID": "test-worker",
    }
    base.update(overrides)
    return base


def settings(**overrides: str) -> Settings:
    return load_settings(env(**overrides))


def memory_logger() -> tuple[Logger, io.StringIO]:
    stream = io.StringIO()
    return Logger("worker", stream), stream

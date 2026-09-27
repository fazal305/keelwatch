"""Worker entry point: ``python -m keelwatch_worker``.

Phase 1 runs only the heartbeat loop. Job processing arrives in Phase 4.
"""

from __future__ import annotations

import argparse
import os
import signal
import sys
import threading
from pathlib import Path

from dotenv import load_dotenv

from .config import ConfigError, Settings, load_settings
from .db import connect
from .heartbeat import HeartbeatLoop, MySqlHeartbeatStore
from .logs import Logger

ROOT = Path(__file__).resolve().parents[2]


def check(settings: Settings, logger: Logger) -> int:
    """One-shot readiness probe for process supervisors: exit 0 if the
    database is reachable and the heartbeat table exists."""
    try:
        conn = connect(settings)
        try:
            with conn.cursor() as cursor:
                cursor.execute("SELECT COUNT(*) AS n FROM worker_heartbeats")
        finally:
            conn.close()
    except Exception as exc:
        logger.error("readiness check failed", exception=type(exc).__name__)
        return 1
    logger.info("readiness check passed")
    return 0


def install_signal_handlers(stop: threading.Event, logger: Logger) -> None:
    def handle(signum: int, _frame: object) -> None:
        logger.info("shutdown requested", signal=signal.Signals(signum).name)
        stop.set()

    for name in ("SIGINT", "SIGTERM", "SIGBREAK"):
        sig = getattr(signal, name, None)
        if sig is not None:
            signal.signal(sig, handle)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="keelwatch_worker")
    parser.add_argument("--check", action="store_true", help="probe the database once and exit")
    args = parser.parse_args(argv)

    load_dotenv(ROOT / ".env", override=False)
    logger = Logger("worker")

    try:
        settings = load_settings(os.environ)
    except ConfigError as exc:
        logger.error("configuration invalid", errors=exc.errors)
        return 1

    if args.check:
        return check(settings, logger)

    stop = threading.Event()
    install_signal_handlers(stop, logger)
    logger.info(
        "worker starting",
        worker_id=settings.worker_id,
        version=settings.app_version,
        environment=settings.app_env,
    )

    store = MySqlHeartbeatStore(settings)
    try:
        HeartbeatLoop(store, settings, logger, stop, pid=os.getpid()).run()
    finally:
        store.close()

    logger.info("worker stopped", worker_id=settings.worker_id)
    return 0


if __name__ == "__main__":
    sys.exit(main())

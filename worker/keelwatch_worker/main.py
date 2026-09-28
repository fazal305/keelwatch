"""Worker entry point.

    python -m keelwatch_worker                      run the worker
    python -m keelwatch_worker --check              probe the database once and exit
    python -m keelwatch_worker resume <run_id>      resume a failed/checkpointed run
    python -m keelwatch_worker destinations list
    python -m keelwatch_worker destinations add --installation <github id> --kind slack|discord
                                               --label <name> [--min-severity high]
    python -m keelwatch_worker destinations disable <id>

The worker runs the heartbeat loop in a background thread and the job loop
(events, analysis, notifications, scheduled) in the main thread until
SIGINT/SIGTERM/SIGBREAK.
"""

from __future__ import annotations

import argparse
import os
import signal
import sys
import threading
from pathlib import Path

from dotenv import load_dotenv

from .analysis import ResumeRefused, handle_analysis_run, production_phases, request_resume
from .config import ConfigError, Settings, load_settings
from .db import connect
from .events import handle_github_event
from .heartbeat import HeartbeatLoop, MySqlHeartbeatStore
from .integrations import IntegrationConfigError, build_github, build_notifications, build_osv
from .llm.config import LLMConfigError, build_providers
from .llm.router import Router
from .logs import Logger
from .notify.cli import (
    DestinationError,
    add_destination,
    disable_destination,
    list_destinations,
    prompt_url,
)
from .notify.destinations import KINDS, SEVERITIES, Sender
from .notify.jobs import (
    DailyDigestScheduler,
    NotificationConfig,
    handle_daily_digest,
    handle_notify,
)
from .pipeline import PipelineEngine
from .runner import JobRunner, SelfManaged

ROOT = Path(__file__).resolve().parents[2]
QUEUES = ("events", "analysis", "notifications", "scheduled")


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


def build_router(settings: Settings) -> Router:
    return Router(
        build_providers(os.environ),
        preferred_timeout_s=settings.llm_timeout_s,
        max_attempts_per_provider=settings.llm_max_attempts,
        breaker_threshold=settings.llm_breaker_failures,
        breaker_cooldown_s=settings.llm_breaker_cooldown_s,
    )


def build_handlers(
    settings: Settings,
    logger: Logger,
    router: Router,
    github=None,
    osv=None,
    notifications: NotificationConfig | None = None,
    sender: Sender | None = None,
) -> dict:
    notifications = notifications or NotificationConfig(key=None, dashboard_url=None)
    sender = sender or Sender()
    engine = PipelineEngine(production_phases(notifications), reserve_ms=settings.phase_reserve_ms)
    return {
        "github_event": lambda conn, job: handle_github_event(
            conn, job, settings.analysis_budget_ms
        ),
        "analysis_run": SelfManaged(
            lambda conn, job, keep_lease: handle_analysis_run(
                conn,
                job,
                keep_lease,
                engine=engine,
                router=router,
                logger=logger,
                github=github,
                osv=osv,
            )
        ),
        # Sending is an external side effect, so it can't share a rollback-able
        # transaction; delivery rows record each attempt instead.
        "notify": SelfManaged(
            lambda conn, job, keep_lease: handle_notify(
                conn, job, keep_lease, config=notifications, sender=sender, logger=logger
            )
        ),
        "daily_digest": lambda conn, job: handle_daily_digest(conn, job, config=notifications),
    }


def resume(settings: Settings, logger: Logger, run_id: int) -> int:
    conn = connect(settings)
    try:
        job_id = request_resume(conn, run_id, settings.analysis_budget_ms)
    except ResumeRefused as exc:
        logger.error("resume refused", run_id=run_id, reason=str(exc))
        return 1
    finally:
        conn.close()
    logger.info("resume queued", run_id=run_id, job_id=job_id)
    return 0


def destinations(settings: Settings, notifications: NotificationConfig, args) -> int:
    conn = connect(settings)
    try:
        if args.action == "list":
            rows = list_destinations(conn)
            if not rows:
                print("No notification destinations.")
            for r in rows:
                state = "enabled" if r["enabled"] else "disabled"
                print(
                    f"#{r['id']}  {r['kind']:<8} {r['label']!r} -> {r['url_host']}  "
                    f"min={r['min_severity']}  {state}  "
                    f"(installation {r['github_installation_id']}, {r['account_login']})"
                )
            return 0
        if args.action == "disable":
            ok = disable_destination(conn, args.id)
            print(f"Destination #{args.id} disabled." if ok else f"No destination #{args.id}.")
            return 0 if ok else 1
        # add
        if notifications.key is None:
            print("Set NOTIFICATION_KEY in .env first (32 random bytes, base64).", file=sys.stderr)
            return 1
        url = prompt_url()
        new_id = add_destination(
            conn,
            notifications.key,
            github_installation_id=args.installation,
            kind=args.kind,
            label=args.label,
            min_severity=args.min_severity,
            url=url,
        )
        print(f"Destination #{new_id} added. The URL is stored encrypted and won't be shown again.")
        return 0
    except DestinationError as exc:
        print(f"Refused: {exc}", file=sys.stderr)
        return 1
    finally:
        conn.close()


def parse_args(argv: list[str] | None):
    parser = argparse.ArgumentParser(prog="keelwatch_worker")
    parser.add_argument("--check", action="store_true", help="probe the database once and exit")
    sub = parser.add_subparsers(dest="command")
    resume_cmd = sub.add_parser("resume", help="resume a failed or checkpointed analysis run")
    resume_cmd.add_argument("run_id", type=int)

    dest = sub.add_parser("destinations", help="manage notification destinations")
    dest_sub = dest.add_subparsers(dest="action", required=True)
    dest_sub.add_parser("list")
    add = dest_sub.add_parser("add", help="the webhook URL is asked for at a hidden prompt")
    add.add_argument("--installation", type=int, required=True, help="GitHub installation ID")
    add.add_argument("--kind", choices=KINDS, required=True)
    add.add_argument("--label", required=True)
    add.add_argument("--min-severity", choices=SEVERITIES, default="high")
    disable = dest_sub.add_parser("disable")
    disable.add_argument("id", type=int)
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    load_dotenv(ROOT / ".env", override=False)
    logger = Logger("worker")

    try:
        settings = load_settings(os.environ)
        router = build_router(settings)
        github = build_github(os.environ)
        osv = build_osv(os.environ)
        notifications = build_notifications(os.environ)
    except (ConfigError, LLMConfigError, IntegrationConfigError) as exc:
        logger.error("configuration invalid", errors=exc.errors)
        return 1

    if args.check:
        return check(settings, logger)
    if args.command == "resume":
        return resume(settings, logger, args.run_id)
    if args.command == "destinations":
        return destinations(settings, notifications, args)

    stop = threading.Event()
    install_signal_handlers(stop, logger)
    logger.info(
        "worker starting",
        worker_id=settings.worker_id,
        version=settings.app_version,
        environment=settings.app_env,
        llm_providers=[f"{p.name}:{p.model}" for p in router.providers],
        phases=[p.name for p in production_phases(notifications)],
        github_auth=type(github.auth).__name__,
        osv_enabled=osv is not None,
        notifications_enabled=notifications.enabled,
    )

    store = MySqlHeartbeatStore(settings)
    heartbeat = threading.Thread(
        target=HeartbeatLoop(store, settings, logger, stop, pid=os.getpid()).run,
        name="heartbeat",
        daemon=True,
    )
    heartbeat.start()

    runner = JobRunner(
        lambda: connect(settings),
        settings,
        logger,
        stop,
        queues=QUEUES,
        handlers=build_handlers(settings, logger, router, github, osv, notifications),
        scheduler=DailyDigestScheduler(notifications),
    )
    try:
        # The current job finishes before shutdown; analysis runs stop at a checkpoint.
        runner.run()
    finally:
        stop.set()
        heartbeat.join(timeout=settings.heartbeat_interval_s + 5)
        store.close()

    logger.info("worker stopped", worker_id=settings.worker_id, jobs_processed=runner.processed)
    return 0


if __name__ == "__main__":
    sys.exit(main())

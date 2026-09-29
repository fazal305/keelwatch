"""Integration: digests, notification fan-out and delivery, and the daily
digest scheduler, against real MySQL with a fake webhook transport.
Nothing is ever sent to a real Slack or Discord."""

import json
from datetime import UTC, datetime, timedelta

import pytest
from mysql_support import rows, runner, scalar
from test_intel_mysql import github_for, seed_pr_run
from test_notify import DISCORD_URL, SLACK_URL, FakeTransport, public_resolver

from keelwatch_worker.analysis import production_phases
from keelwatch_worker.github.auth import AnonymousAuth
from keelwatch_worker.github.client import GitHubClient
from keelwatch_worker.llm.http import HttpResponse
from keelwatch_worker.llm.router import Router
from keelwatch_worker.logs import Logger
from keelwatch_worker.main import build_handlers
from keelwatch_worker.notify.cli import DestinationError, add_destination, list_destinations
from keelwatch_worker.notify.crypto import open_sealed
from keelwatch_worker.notify.destinations import Sender
from keelwatch_worker.notify.digests import enqueue_notifications
from keelwatch_worker.notify.jobs import DailyDigestScheduler, NotificationConfig
from keelwatch_worker.pipeline import PipelineEngine

pytestmark = pytest.mark.integration

LOG = Logger("worker")
KEY = bytes(range(32))  # test-only key
CONFIG = NotificationConfig(key=KEY, dashboard_url="https://keelwatch.example.com")


def github_installation(conn):
    return scalar(conn, "SELECT github_installation_id FROM installations LIMIT 1")


def run_analysis(conn, *, private=False, config=CONFIG, before_run=None):
    """Seeds one PR run and executes the full pipeline. `before_run` runs after
    seeding (so the installation exists) and before the pipeline (so digest
    fan-out sees any destinations it adds)."""
    run_id, full_name = seed_pr_run(conn, private=private)
    if before_run is not None:
        before_run()
    engine = PipelineEngine(production_phases(config), reserve_ms=500)
    result = engine.execute(
        conn,
        run_id,
        120_000,
        LOG,
        router=Router([]),
        github=GitHubClient(AnonymousAuth(), github_for(full_name)),
        osv=None,
    )
    assert result.status == "completed", result
    return run_id


def notify_runner(settings, transport, config=CONFIG):
    handlers = build_handlers(
        settings, LOG, Router([]), notifications=config, sender=Sender(transport, public_resolver)
    )
    return runner(settings, handlers=handlers, queues=("notifications", "scheduled"))


def add(conn, kind="slack", url=SLACK_URL, min_severity="high", key=KEY):
    return add_destination(
        conn,
        key,
        github_installation_id=github_installation(conn),
        kind=kind,
        label="team",
        min_severity=min_severity,
        url=url,
    )


# ----- run digest and fan-out --------------------------------------------------------------


def test_run_digest_is_stored_with_findings_and_gaps(conn):
    run_id = run_analysis(conn, config=NotificationConfig(key=None, dashboard_url=None))

    digest = rows(conn, "SELECT kind, digest_key, repository_id, content FROM digests")[0]
    assert (digest["kind"], digest["digest_key"]) == ("run", f"run:{run_id}")
    content = json.loads(digest["content"])
    # stripe key (critical), raw HTML sink + URL-sourced dependency (medium), downgrade (low);
    # OSV is off here, so there is no vulnerability finding.
    assert content["findings"]["by_severity"] == {"critical": 1, "medium": 2, "low": 1}
    assert content["findings"]["new"] == content["findings"]["total"] == 4
    assert "AI review: no AI provider is configured" in content["gaps"]
    assert scalar(conn, "SELECT COUNT(*) FROM digest_runs WHERE run_id = %s", (run_id,)) == 1
    state = json.loads(
        scalar(conn, "SELECT state FROM analysis_checkpoints WHERE phase = 'digest'")
    )
    assert state["notifications"] == "disabled (no NOTIFICATION_KEY)"
    assert scalar(conn, "SELECT COUNT(*) FROM jobs WHERE queue = 'notifications'") == 0


def test_findings_seen_in_an_earlier_run_are_marked_recurring(conn):
    run_analysis(conn)
    first_key = scalar(conn, "SELECT digest_key FROM digests")
    # Second run of the same repository with the same change.
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO analysis_runs (repository_id, event_id, trigger_type, idempotency_key, status, head_sha, "
            "budget_ms, correlation_id) SELECT repository_id, event_id, 'manual', 'second', 'queued', head_sha, "
            "120000, correlation_id FROM analysis_runs LIMIT 1"
        )
        second = cur.lastrowid
    full_name = scalar(conn, "SELECT full_name FROM repositories LIMIT 1")
    PipelineEngine(production_phases(CONFIG), reserve_ms=500).execute(
        conn,
        second,
        120_000,
        LOG,
        router=Router([]),
        github=GitHubClient(AnonymousAuth(), github_for(full_name)),
        osv=None,
    )
    content = json.loads(
        scalar(conn, "SELECT content FROM digests WHERE digest_key = %s", (f"run:{second}",))
    )
    assert first_key != f"run:{second}"
    assert content["findings"]["recurring"] == content["findings"]["total"] > 0
    assert content["findings"]["new"] == 0


def test_notifications_are_queued_only_for_destinations_whose_threshold_is_met(conn):
    seed_pr_run(conn)  # creates the installation
    high = add(conn, min_severity="high")
    add(conn, kind="discord", url=DISCORD_URL, min_severity="critical")
    disabled = add(conn, min_severity="info")
    with conn.cursor() as cur:
        cur.execute("UPDATE notification_destinations SET enabled = 0 WHERE id = %s", (disabled,))
        cur.execute(
            "INSERT INTO digests (installation_id, kind, digest_key, period_start, period_end, content) "
            "SELECT id, 'run', 'manual-test', UTC_TIMESTAMP(), UTC_TIMESTAMP(), '{}' FROM installations LIMIT 1"
        )
        digest_id = cur.lastrowid
    installation_id = scalar(conn, "SELECT id FROM installations LIMIT 1")

    queued = enqueue_notifications(
        conn,
        digest_id=digest_id,
        installation_id=installation_id,
        by_severity={"high": 2},
        correlation_id="corr-notify-01",
    )

    assert queued == 1
    payload = json.loads(scalar(conn, "SELECT payload FROM jobs WHERE queue = 'notifications'"))
    assert payload == {"schema_version": 1, "destination_id": high, "digest_id": digest_id}
    assert (
        enqueue_notifications(
            conn,
            digest_id=digest_id,
            installation_id=installation_id,
            by_severity={"high": 2},
            correlation_id="corr-notify-01",
        )
        == 0
    ), "idempotent"


# ----- delivery -------------------------------------------------------------------------------


def test_public_run_is_delivered_without_snippets(settings, conn):
    added = []
    run_id = run_analysis(conn, before_run=lambda: added.append(add(conn)))
    dest = added[0]
    transport = FakeTransport(HttpResponse(200, {}, None, "ok"))

    assert notify_runner(settings, transport).run_once() is True

    [call] = transport.calls
    assert call["url"] == SLACK_URL
    text = call["payload"]["text"]
    assert f"https://keelwatch.example.com/runs/{run_id}" in text
    assert "Possible stripe key committed" in text
    assert "sk_live" not in text and "[REDACTED" not in text, "no evidence snippets in messages"
    delivery = rows(
        conn, "SELECT destination_id, attempt, status, http_status FROM notification_deliveries"
    )
    assert delivery == [
        {"destination_id": dest, "attempt": 1, "status": "sent", "http_status": 200}
    ]
    assert scalar(conn, "SELECT status FROM jobs WHERE queue = 'notifications'") == "succeeded"


def test_private_run_message_has_no_names_or_paths(settings, conn):
    # A private repo without an App has nothing extracted and no findings, so
    # the run itself queues nothing; queue the digest explicitly to inspect it.
    run_analysis(conn, private=True, before_run=lambda: add(conn))
    digest_id = scalar(conn, "SELECT id FROM digests")
    installation_id = scalar(conn, "SELECT id FROM installations LIMIT 1")
    enqueue_notifications(
        conn,
        digest_id=digest_id,
        installation_id=installation_id,
        by_severity=None,
        correlation_id="corr-private-1",
    )
    transport = FakeTransport(HttpResponse(200, {}, None, "ok"))

    notify_runner(settings, transport).run_once()

    text = transport.calls[0]["payload"]["text"]
    repo_name = scalar(conn, "SELECT full_name FROM repositories LIMIT 1")
    assert repo_name not in text and repo_name.split("/")[1] not in text
    assert "a private repository" in text


def test_a_redelivered_job_does_not_post_twice(settings, conn):
    run_analysis_with_destination(conn)
    transport = FakeTransport(HttpResponse(204, {}, None, ""))
    r = notify_runner(settings, transport)
    r.run_once()
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE jobs SET status = 'queued', finished_at = NULL WHERE queue = 'notifications'"
        )
    r.run_once()

    assert len(transport.calls) == 1
    assert scalar(conn, "SELECT COUNT(*) FROM notification_deliveries WHERE status = 'sent'") == 1


def test_rate_limit_is_retried_and_recorded(settings, conn):
    run_analysis_with_destination(conn)
    limited = FakeTransport(HttpResponse(429, {"retry-after": "2"}, None, ""))
    notify_runner(settings, limited).run_once()

    assert scalar(conn, "SELECT status FROM jobs WHERE queue = 'notifications'") == "queued"
    with conn.cursor() as cur:
        cur.execute("UPDATE jobs SET run_after = UTC_TIMESTAMP(3) WHERE queue = 'notifications'")
    notify_runner(settings, FakeTransport(HttpResponse(200, {}, None, "ok"))).run_once()

    assert rows(
        conn, "SELECT attempt, status, http_status FROM notification_deliveries ORDER BY attempt"
    ) == [
        {"attempt": 1, "status": "failed", "http_status": 429},
        {"attempt": 2, "status": "sent", "http_status": 200},
    ]


def test_revoked_webhook_is_dead_lettered_not_retried(settings, conn):
    run_analysis_with_destination(conn)
    notify_runner(settings, FakeTransport(HttpResponse(404, {}, None, "no_service"))).run_once()
    assert scalar(conn, "SELECT status FROM jobs WHERE queue = 'notifications'") == "dead"
    assert scalar(conn, "SELECT status FROM notification_deliveries") == "failed"


def test_wrong_key_is_dead_lettered_without_sending(settings, conn):
    run_analysis_with_destination(conn)
    transport = FakeTransport(HttpResponse(200, {}, None, "ok"))
    notify_runner(
        settings, transport, NotificationConfig(key=bytes([7]) * 32, dashboard_url=None)
    ).run_once()

    assert transport.calls == []
    assert scalar(conn, "SELECT status FROM jobs WHERE queue = 'notifications'") == "dead"
    assert "could not be decrypted" in scalar(conn, "SELECT error FROM notification_deliveries")


def run_analysis_with_destination(conn):
    return run_analysis(conn, before_run=lambda: add(conn))


# ----- daily digest ------------------------------------------------------------------------------


def test_scheduler_queues_one_daily_digest_and_it_is_delivered(settings, conn):
    run_analysis_with_destination(conn)
    yesterday = (datetime.now(UTC) - timedelta(days=1)).replace(
        hour=12, minute=0, second=0, microsecond=0
    )
    with conn.cursor() as cur:
        cur.execute("UPDATE analysis_runs SET finished_at = %s", (yesterday.replace(tzinfo=None),))
        cur.execute("DELETE FROM jobs WHERE queue = 'notifications'")
    now = (yesterday + timedelta(days=1)).replace(hour=7).timestamp()

    scheduler = DailyDigestScheduler(CONFIG, clock=lambda: now)
    assert scheduler.tick(conn) == 1
    assert DailyDigestScheduler(CONFIG, clock=lambda: now).tick(conn) == 0, (
        "a second worker adds nothing"
    )

    transport = FakeTransport(HttpResponse(200, {}, None, "ok"))
    r = notify_runner(settings, transport)
    r.run_once()  # scheduled: build and store the daily digest, queue its notification
    r.run_once()  # notifications: deliver it

    digest = rows(conn, "SELECT kind, digest_key, content FROM digests WHERE kind = 'daily'")[0]
    content = json.loads(digest["content"])
    assert content["totals"]["runs"] == 1
    assert content["totals"]["by_severity"]["critical"] == 1
    assert "run_ids" not in content
    assert "Keelwatch daily digest" in transport.calls[0]["payload"]["text"]


def test_scheduler_waits_for_the_configured_hour(conn):
    early = datetime.now(UTC).replace(hour=3, minute=0).timestamp()
    assert DailyDigestScheduler(CONFIG, clock=lambda: early).tick(conn) == 0


# ----- destination management ----------------------------------------------------------------------


def test_destinations_are_stored_encrypted_and_listed_without_urls(conn):
    seed_pr_run(conn)
    dest = add(conn)

    blob = bytes(
        scalar(conn, "SELECT url_ciphertext FROM notification_destinations WHERE id = %s", (dest,))
    )
    assert SLACK_URL.encode() not in blob
    assert open_sealed(blob, KEY) == SLACK_URL
    listed = list_destinations(conn)
    assert SLACK_URL not in json.dumps(listed, default=str)
    assert listed[0]["url_host"] == "hooks.slack.com"


@pytest.mark.parametrize(
    ("kwargs", "message"),
    [
        (
            {"url": "https://hooks.slack.com.evil.example/services/T/B/x"},
            "expected https://hooks.slack.com",
        ),
        ({"min_severity": "urgent"}, "min-severity"),
        ({"kind": "email"}, "kind must be one of"),
    ],
)
def test_bad_destinations_are_refused(conn, kwargs, message):
    seed_pr_run(conn)
    with pytest.raises(DestinationError, match=message):
        add(conn, **kwargs)


def test_unknown_installation_is_refused(conn):
    with pytest.raises(DestinationError, match="no active installation"):
        add_destination(
            conn,
            KEY,
            github_installation_id=999,
            kind="slack",
            label="x",
            min_severity="high",
            url=SLACK_URL,
        )

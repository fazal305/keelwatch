"""Integration: queue semantics and event processing against real MySQL
(DB_TEST_NAME). Run ``php api/bin/migrate.php --test`` first."""

import json
import os
import threading
from pathlib import Path

import pytest
from dotenv import dotenv_values

from keelwatch_worker.config import load_settings
from keelwatch_worker.db import connect
from keelwatch_worker.logs import Logger
from keelwatch_worker.queue import JobQueue
from keelwatch_worker.runner import JobRunner

pytestmark = pytest.mark.integration

ROOT = Path(__file__).resolve().parents[2]
ENVELOPES = ROOT / "contracts" / "fixtures" / "github-event" / "valid"
TABLES_IN_DELETE_ORDER = (
    "jobs",
    "analysis_findings",
    "analysis_checkpoints",
    "provider_calls",
    "analysis_runs",
    "repository_events",
    "webhook_deliveries",
    "repositories",
    "installations",
)


@pytest.fixture
def settings():
    values = {**dotenv_values(ROOT / ".env"), **os.environ}
    test_db = (values.get("DB_TEST_NAME") or "").strip()
    assert test_db and test_db != values.get("DB_NAME")
    values["WORKER_ID"] = "pytest-worker-a"
    base = load_settings({k: v for k, v in values.items() if v is not None})
    return base.with_database(test_db).with_overrides(retry_base_s=1, retry_max_s=2, job_lease_s=30)


@pytest.fixture
def conn(settings):
    c = connect(settings)
    with c.cursor() as cur:
        for table in TABLES_IN_DELETE_ORDER:
            cur.execute(f"DELETE FROM {table}")  # noqa: S608 - fixed table names
    yield c
    c.close()


def scalar(conn, sql, params=()):
    with conn.cursor() as cur:
        cur.execute(sql, params)
        row = cur.fetchone()
        return None if row is None else next(iter(row.values()))


def rows(conn, sql, params=()):
    with conn.cursor() as cur:
        cur.execute(sql, params)
        return cur.fetchall()


def seed_event(conn, envelope_file="pr-opened.json", default_branch="main", enabled=1):
    """Creates installation, repository, delivery, event and its queued job."""
    envelope = json.loads((ENVELOPES / envelope_file).read_text(encoding="utf-8"))
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO installations (github_installation_id, account_login, account_type) "
            "VALUES (12345678, 'example-org', 'Organization')"
        )
        installation_id = cur.lastrowid
        cur.execute(
            "INSERT INTO repositories (installation_id, github_repo_id, full_name, is_private, "
            "default_branch, analysis_enabled) VALUES (%s, 87654321, %s, 0, %s, %s)",
            (installation_id, envelope["repository"]["full_name"], default_branch, enabled),
        )
        repo_id = cur.lastrowid
        cur.execute(
            "INSERT INTO webhook_deliveries (github_delivery_id, event, status, payload_bytes, "
            "correlation_id, repository_id) VALUES (%s, %s, 'accepted', 100, %s, %s)",
            (envelope["delivery_id"], envelope["event"], envelope["correlation_id"], repo_id),
        )
        delivery_id = cur.lastrowid
        cur.execute(
            "INSERT INTO repository_events (delivery_id, repository_id, schema_version, type, "
            "occurred_at, envelope) VALUES (%s, %s, 1, %s, UTC_TIMESTAMP(3), %s)",
            (delivery_id, repo_id, envelope["type"], json.dumps(envelope)),
        )
        event_id = cur.lastrowid
        job_id = enqueue(
            conn,
            {
                "schema_version": 1,
                "event_id": event_id,
                "repository_id": repo_id,
                "correlation_id": envelope["correlation_id"],
            },
            f"event:{event_id}",
        )
    return {"repo_id": repo_id, "event_id": event_id, "job_id": job_id}


def enqueue(conn, payload, key, job_type="github_event", max_attempts=5):
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO jobs "
            "(queue, type, payload, idempotency_key, correlation_id, max_attempts) "
            "VALUES ('events', %s, %s, %s, 'corr-test-0001', %s)",
            (job_type, json.dumps(payload), key, max_attempts),
        )
        return cur.lastrowid


def runner(settings, handlers=None, worker_id=None):
    s = settings if worker_id is None else settings.with_overrides(worker_id=worker_id)
    return JobRunner(lambda: connect(s), s, Logger("worker"), threading.Event(), handlers=handlers)


# ----- happy path and idempotency ---------------------------------------------


def test_open_pull_request_becomes_one_run_and_one_analysis_job(settings, conn):
    seeded = seed_event(conn)

    assert runner(settings).run_once() is True

    job = rows(
        conn, "SELECT status, attempts, locked_by FROM jobs WHERE id = %s", (seeded["job_id"],)
    )[0]
    assert job == {"status": "succeeded", "attempts": 1, "locked_by": None}

    run = rows(conn, "SELECT * FROM analysis_runs")[0]
    assert run["status"] == "queued"
    assert run["trigger_type"] == "webhook"
    assert run["event_id"] == seeded["event_id"]
    assert run["budget_ms"] == settings.analysis_budget_ms

    analysis = rows(conn, "SELECT payload, idempotency_key FROM jobs WHERE queue = 'analysis'")
    assert len(analysis) == 1
    assert analysis[0]["idempotency_key"] == f"run:{run['id']}:attempt:1"
    assert json.loads(analysis[0]["payload"])["run_id"] == run["id"]


def test_reprocessing_the_same_event_never_duplicates_work(settings, conn):
    seeded = seed_event(conn)
    runner(settings).run_once()

    # Simulate at-least-once redelivery of the same event job.
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE jobs SET status = 'queued', finished_at = NULL WHERE id = %s",
            (seeded["job_id"],),
        )
    runner(settings).run_once()

    assert scalar(conn, "SELECT COUNT(*) FROM analysis_runs") == 1
    assert scalar(conn, "SELECT COUNT(*) FROM jobs WHERE queue = 'analysis'") == 1


def test_events_that_need_no_analysis_are_acknowledged_without_a_run(settings, conn):
    seed_event(conn, "pr-closed-merged.json")

    assert runner(settings).run_once() is True
    assert scalar(conn, "SELECT status FROM jobs WHERE queue = 'events'") == "succeeded"
    assert scalar(conn, "SELECT COUNT(*) FROM analysis_runs") == 0


def test_disabled_repository_is_skipped(settings, conn):
    seed_event(conn, enabled=0)
    runner(settings).run_once()
    assert scalar(conn, "SELECT status FROM jobs WHERE queue = 'events'") == "succeeded"
    assert scalar(conn, "SELECT COUNT(*) FROM analysis_runs") == 0


def test_empty_queue_does_nothing(settings, conn):
    assert runner(settings).run_once() is False


# ----- failures, retries, dead letters ------------------------------------------


def test_bad_payload_is_dead_lettered_immediately(settings, conn):
    job_id = enqueue(conn, {"schema_version": 1, "event_id": "not-an-int"}, "bad-1")

    runner(settings).run_once()

    job = rows(conn, "SELECT status, attempts, last_error FROM jobs WHERE id = %s", (job_id,))[0]
    assert job["status"] == "dead"
    assert job["attempts"] == 1
    assert job["last_error"].startswith("PermanentError:")


def test_missing_event_row_is_dead_lettered(settings, conn):
    payload = {
        "schema_version": 1,
        "event_id": 999999,
        "repository_id": 1,
        "correlation_id": "corr-0000001",
    }
    job_id = enqueue(conn, payload, "missing-1")
    runner(settings).run_once()
    assert scalar(conn, "SELECT status FROM jobs WHERE id = %s", (job_id,)) == "dead"


def test_transient_failure_retries_with_backoff_then_dead_letters(settings, conn):
    job_id = enqueue(conn, {"x": 1}, "flaky-1", job_type="flaky", max_attempts=2)

    def flaky(_conn, _job):
        raise ConnectionError("upstream timed out")

    r = runner(settings, handlers={"flaky": flaky})
    r.run_once()

    job = rows(
        conn,
        "SELECT status, attempts, last_error, "
        "TIMESTAMPDIFF(MICROSECOND, UTC_TIMESTAMP(3), run_after) / 1e6 AS wait_s "
        "FROM jobs WHERE id = %s",
        (job_id,),
    )[0]
    assert job["status"] == "queued"
    assert job["attempts"] == 1
    assert 0.3 <= float(job["wait_s"]) <= 1.1, "first retry waits base/2 .. base seconds"
    assert "upstream timed out" in job["last_error"]

    # Make it due now and fail the final attempt.
    with conn.cursor() as cur:
        cur.execute("UPDATE jobs SET run_after = UTC_TIMESTAMP(3) WHERE id = %s", (job_id,))
    r.run_once()
    assert rows(conn, "SELECT status, attempts FROM jobs WHERE id = %s", (job_id,))[0] == {
        "status": "dead",
        "attempts": 2,
    }


def test_handler_failure_rolls_back_its_writes(settings, conn):
    job_id = enqueue(conn, {"x": 1}, "atomic-1", job_type="half")

    def half(c, _job):
        with c.cursor() as cur:
            cur.execute(
                "INSERT INTO installations (github_installation_id, account_login, account_type) "
                "VALUES (777, 'should-vanish', 'User')"
            )
        raise RuntimeError("crashed after writing")

    runner(settings, handlers={"half": half}).run_once()

    assert (
        scalar(conn, "SELECT COUNT(*) FROM installations WHERE github_installation_id = 777") == 0
    )
    assert scalar(conn, "SELECT status FROM jobs WHERE id = %s", (job_id,)) == "queued"


# ----- leases -------------------------------------------------------------------


def test_expired_lease_is_recovered_or_dead_lettered(settings, conn):
    alive = enqueue(conn, {"x": 1}, "lease-1", max_attempts=3)
    exhausted = enqueue(conn, {"x": 2}, "lease-2", max_attempts=1)
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE jobs SET status = 'running', attempts = 1, locked_by = 'crashed-worker', "
            "locked_until = UTC_TIMESTAMP(3) - INTERVAL 1 SECOND"
        )

    result = JobQueue(conn, "pytest-worker-a", 30).recover_expired()

    assert result == {"requeued": 1, "dead": 1}
    assert scalar(conn, "SELECT status FROM jobs WHERE id = %s", (alive,)) == "queued"
    assert scalar(conn, "SELECT status FROM jobs WHERE id = %s", (exhausted,)) == "dead"
    assert scalar(conn, "SELECT locked_by FROM jobs WHERE id = %s", (alive,)) is None


def test_a_worker_that_lost_its_lease_cannot_complete_or_fail_the_job(settings, conn):
    enqueue(conn, {"x": 1}, "stolen-1")
    queue_a = JobQueue(conn, "worker-a", 30)
    job = queue_a.claim("events")

    # Lease expires and worker B reclaims it.
    with conn.cursor() as cur:
        cur.execute("UPDATE jobs SET locked_until = UTC_TIMESTAMP(3) - INTERVAL 1 SECOND")
    queue_a.recover_expired()
    other = connect(settings)
    try:
        assert JobQueue(other, "worker-b", 30).claim("events") is not None
    finally:
        other.close()

    assert queue_a.complete(job) is False
    assert queue_a.fail(job, "late failure", None) == "lost"
    assert rows(conn, "SELECT status, locked_by FROM jobs")[0] == {
        "status": "running",
        "locked_by": "worker-b",
    }


def test_concurrent_workers_never_claim_the_same_job(settings, conn):
    for i in range(40):
        enqueue(conn, {"i": i}, f"concurrent-{i}")

    claimed: list[int] = []
    lock = threading.Lock()
    workers = 4
    # Connect first, then release all workers at once so they really contend.
    start = threading.Barrier(workers)

    def drain(name):
        c = connect(settings)
        try:
            q = JobQueue(c, name, 30)
            start.wait(timeout=10)
            while (job := q.claim("events")) is not None:
                with lock:
                    claimed.append(job.id)
        finally:
            c.close()

    threads = [threading.Thread(target=drain, args=(f"w{i}",)) for i in range(workers)]
    for t in threads:
        t.start()
    for t in threads:
        t.join(timeout=30)

    assert len(claimed) == 40
    assert len(set(claimed)) == 40, "a job was handed to two workers"
    assert scalar(conn, "SELECT COUNT(*) FROM jobs WHERE status = 'running'") == 40


def test_claim_skips_a_row_another_worker_has_locked_instead_of_waiting(settings, conn):
    first = enqueue(conn, {"i": 1}, "skip-1")
    second = enqueue(conn, {"i": 2}, "skip-2")

    # Worker A is mid-claim: it holds the row lock on the first job.
    holder = connect(settings)
    try:
        holder.begin()
        with holder.cursor() as cur:
            cur.execute("SELECT id FROM jobs WHERE id = %s FOR UPDATE", (first,))

        # Worker B must not block on that lock; it takes the next job.
        other = connect(settings.with_overrides(db_connect_timeout_s=2))
        try:
            with other.cursor() as cur:
                cur.execute("SET SESSION innodb_lock_wait_timeout = 2")
            job = JobQueue(other, "worker-b", 30).claim("events")
        finally:
            other.close()
    finally:
        holder.rollback()
        holder.close()

    assert job is not None
    assert job.id == second

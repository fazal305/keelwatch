"""Shared MySQL fixtures and helpers for integration tests.

Registered as a pytest plugin in conftest.py, so the `settings` and `conn`
fixtures are available to every test module without importing them.
Plain helpers (rows, scalar, seed_event, ...) are imported normally.
"""

import json
import os
import threading
from pathlib import Path

import pytest
from dotenv import dotenv_values

from keelwatch_worker.config import load_settings
from keelwatch_worker.db import connect
from keelwatch_worker.logs import Logger
from keelwatch_worker.runner import JobRunner

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
    assert test_db and test_db != values.get("DB_NAME"), (
        "DB_TEST_NAME must be set and differ from DB_NAME"
    )
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


def enqueue(conn, payload, key, job_type="github_event", max_attempts=5):
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO jobs "
            "(queue, type, payload, idempotency_key, correlation_id, max_attempts) "
            "VALUES ('events', %s, %s, %s, 'corr-test-0001', %s)",
            (job_type, json.dumps(payload), key, max_attempts),
        )
        return cur.lastrowid


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


def runner(settings, handlers=None, worker_id=None, queues=("events",)):
    s = settings if worker_id is None else settings.with_overrides(worker_id=worker_id)
    return JobRunner(
        lambda: connect(s), s, Logger("worker"), threading.Event(), queues=queues, handlers=handlers
    )

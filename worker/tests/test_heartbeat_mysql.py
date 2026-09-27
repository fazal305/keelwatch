"""Integration: real MySQL test database (DB_TEST_NAME). Run with
``pytest -m integration`` after ``php api/bin/migrate.php --test``."""

import os
import threading
from pathlib import Path

import pytest
from dotenv import dotenv_values

from keelwatch_worker.config import load_settings
from keelwatch_worker.db import connect
from keelwatch_worker.heartbeat import HeartbeatLoop, MySqlHeartbeatStore
from keelwatch_worker.logs import Logger

pytestmark = pytest.mark.integration

ROOT = Path(__file__).resolve().parents[2]


@pytest.fixture
def test_settings():
    values = {**dotenv_values(ROOT / ".env"), **os.environ}
    test_db = (values.get("DB_TEST_NAME") or "").strip()
    assert test_db, "DB_TEST_NAME must be set for integration tests"
    assert test_db != values.get("DB_NAME"), "DB_TEST_NAME must differ from DB_NAME"
    values["WORKER_ID"] = "pytest-integration-worker"
    return load_settings({k: v for k, v in values.items() if v is not None}).with_database(test_db)


@pytest.fixture
def conn(test_settings):
    connection = connect(test_settings)
    with connection.cursor() as cursor:
        cursor.execute("DELETE FROM worker_heartbeats")
    yield connection
    connection.close()


def fetch(conn, worker_id):
    with conn.cursor() as cursor:
        cursor.execute(
            "SELECT pid, version, status, stopped_at, "
            "TIMESTAMPDIFF(SECOND, last_seen_at, UTC_TIMESTAMP()) AS age_s "
            "FROM worker_heartbeats WHERE worker_id = %s",
            (worker_id,),
        )
        return cursor.fetchone()


def test_heartbeat_upserts_and_marks_stopped(test_settings, conn):
    class StopAfterTwo(threading.Event):
        calls = 0

        def wait(self, timeout=None):
            self.calls += 1
            if self.calls >= 2:
                self.set()
            return self.is_set()

    store = MySqlHeartbeatStore(test_settings)
    stop = StopAfterTwo()
    loop = HeartbeatLoop(store, test_settings, Logger("worker"), stop, pid=31337)

    # Run two beats, inspecting the row while it is still "running".
    store.beat("pytest-integration-worker", 31337, test_settings.app_version, loop._started_at)
    row = fetch(conn, "pytest-integration-worker")
    assert row["status"] == "running"
    assert row["pid"] == 31337
    assert row["stopped_at"] is None
    assert row["age_s"] <= 2  # stored in UTC, same clock as the database

    loop.run()
    store.close()

    row = fetch(conn, "pytest-integration-worker")
    assert row["status"] == "stopped"
    assert row["stopped_at"] is not None
    assert loop.beats == 2

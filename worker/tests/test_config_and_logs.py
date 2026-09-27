import json

import pytest
from helpers import FAKE_PASSWORD, env, memory_logger, settings

from keelwatch_worker.config import ConfigError, load_settings
from keelwatch_worker.logs import redact


def test_builds_settings_with_defaults():
    s = settings(DB_PORT="", DB_CONNECT_TIMEOUT_S="", WORKER_HEARTBEAT_INTERVAL_S="")
    assert s.db_port == 3306
    assert s.db_connect_timeout_s == 2
    assert s.heartbeat_interval_s == 10
    assert s.worker_id == "test-worker"


def test_default_worker_id_is_host_and_pid():
    s = settings(WORKER_ID="")
    assert s.worker_id.rsplit("-", 1)[1].isdigit()


def test_reports_every_problem_at_once_without_values():
    with pytest.raises(ConfigError) as caught:
        load_settings(env(APP_ENV="staging", DB_HOST="", DB_PORT=FAKE_PASSWORD, DB_NAME=""))
    errors = caught.value.errors
    assert len(errors) == 4
    assert FAKE_PASSWORD not in str(caught.value)


def test_stale_threshold_must_exceed_heartbeat_interval():
    with pytest.raises(ConfigError, match="WORKER_STALE_AFTER_S must be greater"):
        load_settings(env(WORKER_HEARTBEAT_INTERVAL_S="30", WORKER_STALE_AFTER_S="30"))


def test_settings_repr_hides_password():
    assert FAKE_PASSWORD not in repr(settings())


def test_with_database_changes_only_name():
    s = settings()
    t = s.with_database("keelwatch_test")
    assert t.db_name == "keelwatch_test"
    assert t.db_user == s.db_user
    assert s.db_name == "keelwatch_unit"


def test_redacts_sensitive_keys_recursively():
    out = redact(
        {
            "DB_PASSWORD": "x",
            "headers": {"Authorization": "Bearer y", "content-type": "json"},
            "items": [{"github_token": "z"}],
            "path": "/ok",
        }
    )
    assert out == {
        "DB_PASSWORD": "[REDACTED]",
        "headers": {"Authorization": "[REDACTED]", "content-type": "json"},
        "items": [{"github_token": "[REDACTED]"}],
        "path": "/ok",
    }


def test_logger_writes_single_json_line_with_correlation_id():
    logger, stream = memory_logger()
    logger.with_correlation_id("corr-1").info("hello", secret="s", n=1)

    output = stream.getvalue()
    assert output.count("\n") == 1
    record = json.loads(output)
    assert record["service"] == "worker"
    assert record["correlation_id"] == "corr-1"
    assert record["ctx"] == {"secret": "[REDACTED]", "n": 1}
    assert record["ts"].endswith("Z")

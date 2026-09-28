import json
import random
from pathlib import Path

import pytest

from keelwatch_worker.events import decide, validate_event_job
from keelwatch_worker.queue import PermanentError, describe_error, retry_delay_s

FIXTURES = Path(__file__).resolve().parents[2] / "contracts" / "fixtures" / "github-event" / "valid"


def envelope(name: str) -> dict:
    return json.loads((FIXTURES / name).read_text(encoding="utf-8"))


VALID_JOB = {
    "schema_version": 1,
    "event_id": 5,
    "repository_id": 7,
    "correlation_id": "corr-0000001",
}


# ----- payload validation ---------------------------------------------------


def test_valid_event_job_passes():
    assert validate_event_job(dict(VALID_JOB)) == VALID_JOB


@pytest.mark.parametrize(
    "mutation",
    [
        {"event_id": 0},
        {"event_id": "5"},
        {"event_id": True},
        {"repository_id": -1},
        {"schema_version": 2},
        {"correlation_id": "short"},
        {"correlation_id": "corr-0000001\n"},
        {"extra": 1},
    ],
    ids=lambda m: ",".join(m),
)
def test_invalid_event_job_is_permanent(mutation):
    with pytest.raises(PermanentError):
        validate_event_job({**VALID_JOB, **mutation})


def test_non_object_payload_is_permanent():
    with pytest.raises(PermanentError):
        validate_event_job([1, 2])


# ----- run policy -----------------------------------------------------------


def test_open_pull_request_creates_a_run_keyed_by_head_sha():
    env = envelope("pr-opened.json")
    d = decide(env, 7, "main")
    assert d.analyse
    assert d.idempotency_key == f"pr:7:42:{env['pull_request']['head_sha']}"
    assert d.head_sha == env["pull_request"]["head_sha"]


@pytest.mark.parametrize(
    ("change", "reason_fragment"),
    [
        (lambda e: e["pull_request"].update(draft=True), "draft"),
        (lambda e: e.update(action="closed", type="pull_request.closed"), "no analysis"),
        (lambda e: e["pull_request"].update(state="closed"), "not open"),
    ],
)
def test_pull_requests_that_are_not_analysed(change, reason_fragment):
    env = envelope("pr-opened.json")
    change(env)
    d = decide(env, 7, "main")
    assert not d.analyse
    assert reason_fragment in d.reason


def test_push_to_default_branch_creates_a_run():
    env = envelope("push.json")
    d = decide(env, 7, "main")
    assert d.analyse
    assert d.idempotency_key == f"push:7:{env['push']['after']}"


def test_pushes_that_are_not_analysed():
    env = envelope("push.json")
    assert not decide(env, 7, "develop").analyse, "non-default branch"
    assert not decide(env, 7, None).analyse, "unknown default branch"
    env["push"]["commit_count"] = 0
    assert not decide(env, 7, "main").analyse, "no commits"


def test_same_event_always_gets_the_same_key():
    env = envelope("pr-opened.json")
    assert decide(env, 7, "main").idempotency_key == decide(env, 7, "main").idempotency_key


# ----- retry backoff --------------------------------------------------------


def test_retry_delay_grows_exponentially_with_jitter_and_is_capped():
    rng = random.Random(1)
    for attempt, ceiling in [(1, 5), (2, 10), (3, 20), (4, 40), (10, 300)]:
        for _ in range(50):
            delay = retry_delay_s(attempt, 5, 300, rng)
            assert ceiling / 2 <= delay <= ceiling


def test_error_descriptions_are_bounded():
    text = describe_error(ValueError("x" * 5000))
    assert text.startswith("ValueError: ")
    assert len(text) == 1000

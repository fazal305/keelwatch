"""analysis_findings.is_new must always equal its definition: no earlier run
of the same repository reported the same fingerprint (migration 0012)."""

import hashlib

import pytest
from mysql_support import scalar, seed_event

from keelwatch_worker.intel.phases import mark_new_findings

pytestmark = pytest.mark.integration

FP = hashlib.sha256(b"same-issue").hexdigest()


def _run(conn, repo_id, key):
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO analysis_runs (repository_id, trigger_type, idempotency_key, status, budget_ms, correlation_id) "
            "VALUES (%s, 'webhook', %s, 'running', 120000, 'corr-is-new')",
            (repo_id, key),
        )
        return cur.lastrowid


def _store(conn, run_id, repo_id, fingerprint=FP):
    """What normalize_findings does: insert, then mark."""
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO analysis_findings (run_id, repository_id, phase, fingerprint, severity, category, "
            "confidence, title, description, source, rule_id) "
            "VALUES (%s, %s, 'structure', %s, 'low', 'quality', 'low', 't', 'd', 'rule', 'structure.large-change')",
            (run_id, repo_id, fingerprint),
        )
        mark_new_findings(cur, run_id)


def _is_new(conn, run_id, fingerprint=FP):
    return scalar(conn, "SELECT is_new FROM analysis_findings WHERE run_id = %s AND fingerprint = %s", (run_id, fingerprint))


def test_first_occurrence_is_new_and_repeats_are_recurring(conn):
    repo = seed_event(conn)["repo_id"]
    first, second = _run(conn, repo, "k1"), _run(conn, repo, "k2")
    _store(conn, first, repo)
    _store(conn, second, repo)
    assert (_is_new(conn, first), _is_new(conn, second)) == (1, 0)


def test_an_earlier_run_finishing_late_takes_over_as_the_first_occurrence(conn):
    repo = seed_event(conn)["repo_id"]
    early, middle, late = _run(conn, repo, "k1"), _run(conn, repo, "k2"), _run(conn, repo, "k3")
    # The later runs store their findings first...
    _store(conn, middle, repo)
    _store(conn, late, repo)
    assert (_is_new(conn, middle), _is_new(conn, late)) == (1, 0)
    # ...then the earliest run finishes: it is now the first occurrence.
    _store(conn, early, repo)
    assert (_is_new(conn, early), _is_new(conn, middle), _is_new(conn, late)) == (1, 0, 0)


def test_new_is_per_repository(conn):
    repo = seed_event(conn)["repo_id"]
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO repositories (installation_id, github_repo_id, full_name, is_private) "
            "SELECT installation_id, 99887766, 'example-org/other', 0 FROM repositories WHERE id = %s",
            (repo,),
        )
        other = cur.lastrowid
    a, b = _run(conn, repo, "k1"), _run(conn, other, "k2")
    _store(conn, a, repo)
    _store(conn, b, other)
    assert (_is_new(conn, a), _is_new(conn, b)) == (1, 1)


def test_the_stored_flag_matches_the_definition_after_mixed_order(conn):
    repo = seed_event(conn)["repo_id"]
    runs = [_run(conn, repo, f"k{i}") for i in range(5)]
    fps = [hashlib.sha256(f"fp{i}".encode()).hexdigest() for i in range(3)]
    for run in (runs[3], runs[0], runs[4], runs[1], runs[2]):
        for i, fp in enumerate(fps):
            if (run + i) % 2 == 0 or run == runs[3]:
                _store(conn, run, repo, fp)
    mismatches = scalar(
        conn,
        """
        SELECT COUNT(*) FROM analysis_findings f
         WHERE f.repository_id = %s
           AND f.is_new <> (NOT EXISTS (SELECT 1 FROM analysis_findings p
                                         WHERE p.repository_id = f.repository_id AND p.fingerprint = f.fingerprint
                                           AND p.run_id < f.run_id))
        """,
        (repo,),
    )
    assert mismatches == 0

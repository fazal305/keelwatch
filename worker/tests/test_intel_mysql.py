"""Integration: the full code-intelligence pipeline against real MySQL,
with GitHub and OSV scripted. Run ``php api/bin/migrate.php --test`` first."""

import base64
import json

import pytest
from mysql_support import rows, scalar, seed_event
from test_github import FakeGitHub, FakeOsv

from keelwatch_worker.analysis import PRODUCTION_PHASES, request_resume
from keelwatch_worker.github.auth import AnonymousAuth
from keelwatch_worker.github.client import GitHubClient
from keelwatch_worker.github.http import Response
from keelwatch_worker.intel.osv import OsvClient
from keelwatch_worker.llm.http import HttpResponse
from keelwatch_worker.llm.router import Router
from keelwatch_worker.logs import Logger
from keelwatch_worker.pipeline import PipelineEngine, RetryLater

pytestmark = pytest.mark.integration

API = "https://api.github.com"
BASE, HEAD = "a" * 40, "b" * 40
LOG = Logger("worker")
# Credential-shaped value assembled at runtime; it must never reach the database.
STRIPE_KEY = "sk" + "_live_" + "9" * 24


def seed_pr_run(conn, *, private=False):
    seeded = seed_event(conn)  # pr-opened.json: PR #42, base a*40, head b*40
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE repositories SET is_private = %s WHERE id = %s",
            (int(private), seeded["repo_id"]),
        )
        cur.execute(
            "INSERT INTO analysis_runs (repository_id, event_id, trigger_type, idempotency_key, status, "
            "head_sha, budget_ms, correlation_id) VALUES (%s, %s, 'webhook', 'intel-test', 'queued', %s, "
            "120000, 'corr-intel-0001')",
            (seeded["repo_id"], seeded["event_id"], HEAD),
        )
        run_id = cur.lastrowid
    full_name = scalar(
        conn, "SELECT full_name FROM repositories WHERE id = %s", (seeded["repo_id"],)
    )
    return run_id, full_name


def content(obj) -> Response:
    raw = json.dumps(obj).encode()
    return Response(
        200,
        {},
        {
            "type": "file",
            "encoding": "base64",
            "size": len(raw),
            "content": base64.b64encode(raw).decode(),
        },
    )


def github_for(full_name, pr_files_response=None):
    pr_files = [
        {
            "filename": "src/pay.js",
            "status": "modified",
            "additions": 3,
            "deletions": 0,
            "patch": (
                "@@ -10,0 +11,3 @@\n"
                f"+const stripe = require('stripe')('{STRIPE_KEY}');\n"
                "+// TODO handle refunds\n"
                "+el.innerHTML = response.body;"
            ),
        },
        {
            "filename": "package.json",
            "status": "modified",
            "additions": 2,
            "deletions": 1,
            "patch": '@@ -3,1 +3,2 @@\n-    "lodash": "4.17.21"\n+    "lodash": "4.17.15",\n+    "evil": "github:someone/evil"',
        },
        {
            "filename": "package-lock.json",
            "status": "modified",
            "additions": 900,
            "deletions": 850,
            "patch": "@@ -1 +1 @@\n+huge",
        },
        {"filename": "assets/logo.png", "status": "added", "additions": 0, "deletions": 0},
    ]
    return FakeGitHub(
        {
            ("GET", f"{API}/repos/{full_name}/pulls/42/files"): pr_files_response
            or Response(200, {}, pr_files),
            ("GET", f"{API}/repos/{full_name}/contents/package.json?ref={BASE}"): content(
                {"dependencies": {"lodash": "4.17.21"}}
            ),
            ("GET", f"{API}/repos/{full_name}/contents/package.json?ref={HEAD}"): content(
                {"dependencies": {"lodash": "4.17.15", "evil": "github:someone/evil"}}
            ),
        }
    )


def osv_with_lodash_advisories():
    return FakeOsv(
        HttpResponse(200, {}, {"results": [{"vulns": [{"id": "GHSA-29mw-wpgm-hmr9"}]}]}, "")
    )


def run_pipeline(conn, run_id, github, osv, budget_ms=120_000):
    return PipelineEngine(PRODUCTION_PHASES, reserve_ms=500).execute(
        conn, run_id, budget_ms, LOG, router=Router([]), github=github, osv=osv
    )


def test_pull_request_produces_traceable_findings(conn):
    run_id, full_name = seed_pr_run(conn)
    github = GitHubClient(AnonymousAuth(), github_for(full_name))
    osv_transport = osv_with_lodash_advisories()

    result = run_pipeline(conn, run_id, github, OsvClient(transport=osv_transport))

    assert result.status == "completed", result
    statuses = dict(
        (r["phase"], r["status"])
        for r in rows(
            conn, "SELECT phase, status FROM analysis_checkpoints WHERE run_id = %s", (run_id,)
        )
    )
    assert statuses == {
        "load_event": "completed",
        "extract_changes": "completed",
        "secrets": "completed",
        "dependencies": "completed",
        "structure": "completed",
        "context": "completed",
        "llm_review": "skipped",  # no LLM configured
        "normalize_findings": "completed",
    }

    findings = rows(
        conn,
        "SELECT rule_id, source, severity, file_path, line_start, run_id, repository_id "
        "FROM analysis_findings ORDER BY rule_id",
    )
    assert sorted(
        (f["rule_id"] or f["source"], f["file_path"], f["line_start"]) for f in findings
    ) == sorted(
        [
            ("secrets.stripe-key", "src/pay.js", 11),
            ("web.raw-html-sink", "src/pay.js", 13),
            ("deps.downgrade", "package.json", None),
            ("deps.url-source", "package.json", None),
            ("osv", "package.json", None),
        ]
    )
    assert {f["run_id"] for f in findings} == {run_id}, "every finding traces back to its run"

    # Only the exact, changed version was sent to OSV.
    assert osv_transport.payloads == [
        {"queries": [{"package": {"ecosystem": "npm", "name": "lodash"}, "version": "4.17.15"}]}
    ]


def test_the_raw_secret_is_never_stored_anywhere(conn):
    run_id, full_name = seed_pr_run(conn)
    run_pipeline(conn, run_id, GitHubClient(AnonymousAuth(), github_for(full_name)), None)

    for table in ("analysis_checkpoints", "analysis_findings", "analysis_runs", "provider_calls"):
        dump = json.dumps(rows(conn, f"SELECT * FROM {table}"), default=str)  # noqa: S608
        assert STRIPE_KEY not in dump, f"raw secret found in {table}"
    extract = json.loads(
        scalar(conn, "SELECT state FROM analysis_checkpoints WHERE phase = 'extract_changes'")
    )
    assert extract["redactions"] >= 1
    assert "[REDACTED:stripe_key]" in extract["files"][0]["patch"]


def test_generated_files_and_binaries_carry_no_patch(conn):
    run_id, full_name = seed_pr_run(conn)
    run_pipeline(conn, run_id, GitHubClient(AnonymousAuth(), github_for(full_name)), None)
    files = {
        f["path"]: f
        for f in json.loads(
            scalar(conn, "SELECT state FROM analysis_checkpoints WHERE phase = 'extract_changes'")
        )["files"]
    }
    assert files["package-lock.json"]["patch"] is None
    assert files["package-lock.json"]["patch_note"] == "generated_or_lockfile"
    assert files["assets/logo.png"]["patch_note"] == "not_provided"


def test_osv_outage_is_recorded_as_a_gap_not_as_clean(conn):
    run_id, full_name = seed_pr_run(conn)
    down = OsvClient(transport=FakeOsv(ConnectionError("osv down")))

    assert (
        run_pipeline(
            conn, run_id, GitHubClient(AnonymousAuth(), github_for(full_name)), down
        ).status
        == "completed"
    )

    deps_state = json.loads(
        scalar(conn, "SELECT state FROM analysis_checkpoints WHERE phase = 'dependencies'")
    )
    assert deps_state["osv"].startswith("unavailable")
    assert scalar(conn, "SELECT COUNT(*) FROM analysis_findings WHERE source = 'osv'") == 0


def test_github_rate_limit_checkpoints_the_run_and_resume_finishes_it(conn):
    run_id, full_name = seed_pr_run(conn)
    limited = GitHubClient(
        AnonymousAuth(),
        github_for(
            full_name,
            pr_files_response=Response(
                403, {"x-ratelimit-remaining": "0", "x-ratelimit-reset": "0"}, {}
            ),
        ),
    )

    with pytest.raises(RetryLater):
        run_pipeline(conn, run_id, limited, None)
    row = rows(conn, "SELECT status, failure_reason FROM analysis_runs WHERE id = %s", (run_id,))[0]
    assert row == {"status": "checkpointed", "failure_reason": "retry_later:extract_changes"}

    # The job queue retries the same job; the engine picks up from the checkpoint.
    ok = GitHubClient(AnonymousAuth(), github_for(full_name))
    assert run_pipeline(conn, run_id, ok, None).status == "completed"
    assert (
        scalar(
            conn,
            "SELECT COUNT(*) FROM analysis_checkpoints WHERE run_id = %s AND phase = 'load_event'",
            (run_id,),
        )
        == 1
    ), "load_event was not re-run"


def test_private_repository_without_an_app_is_skipped_not_failed(conn):
    run_id, full_name = seed_pr_run(conn, private=True)
    transport = github_for(full_name)

    assert (
        run_pipeline(conn, run_id, GitHubClient(AnonymousAuth(), transport), None).status
        == "completed"
    )

    assert transport.calls == [], "no GitHub request is made for a private repo without an App"
    reason = json.loads(
        scalar(conn, "SELECT state FROM analysis_checkpoints WHERE phase = 'extract_changes'")
    )
    assert reason == {"reason": "a private repository needs a configured GitHub App"}
    assert scalar(conn, "SELECT COUNT(*) FROM analysis_findings") == 0


def test_rerunning_normalization_does_not_duplicate_findings(conn):
    run_id, full_name = seed_pr_run(conn)
    github = GitHubClient(AnonymousAuth(), github_for(full_name))
    run_pipeline(conn, run_id, github, None)
    before = scalar(conn, "SELECT COUNT(*) FROM analysis_findings")

    # Force normalization to run again on resume.
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE analysis_checkpoints SET status = 'failed', error = 'test' "
            "WHERE run_id = %s AND phase = 'normalize_findings'",
            (run_id,),
        )
        cur.execute(
            "UPDATE analysis_runs SET status = 'failed', failure_reason = 'test' WHERE id = %s",
            (run_id,),
        )
    request_resume(conn, run_id, 60_000)
    run_pipeline(conn, run_id, github, None)

    assert scalar(conn, "SELECT COUNT(*) FROM analysis_findings") == before

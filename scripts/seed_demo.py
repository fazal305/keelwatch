"""Fill an empty development database with a sample 60-day history.

For trying the dashboard (and taking the README screenshots) without a
GitHub App. Everything it writes is fictional: two accounts, six
repositories, webhook events, analysis runs with checkpoints, findings drawn
from the worker's real rule catalogue, and digests built by the worker's own
digest code from those rows.

    worker/.venv/Scripts/python scripts/seed_demo.py          (Windows)
    worker/.venv/bin/python scripts/seed_demo.py              (Linux/macOS)
    ... scripts/seed_demo.py --database keelwatch_demo        (another database)

Refuses to run when APP_ENV=production or when the database already has
installations. Apply migrations first (php api/bin/migrate.php).
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import random
import sys
from dataclasses import replace
from datetime import UTC, datetime, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "worker"))

from dotenv import load_dotenv  # noqa: E402
from keelwatch_worker.config import load_settings  # noqa: E402
from keelwatch_worker.db import connect  # noqa: E402
from keelwatch_worker.notify.digests import (  # noqa: E402
    build_daily_digest,
    build_run_digest,
    day_bounds,
    store_digest,
)

DAYS = 60
NOW = datetime.now(UTC).replace(tzinfo=None, microsecond=0)

# (account, type, github installation id)
ACCOUNTS = [("northwind-labs", "Organization", 810001), ("alice-dev", "User", 810002)]

# (account, name, private, llm_policy, events per day, test discipline 0-1)
REPOS = [
    ("northwind-labs", "checkout-api", False, "public_only", 2.2, 0.8),
    ("northwind-labs", "web-storefront", False, "public_only", 1.6, 0.5),
    ("northwind-labs", "billing-service", True, "none", 0.9, 0.9),
    ("northwind-labs", "infra-config", True, "none", 0.4, 0.1),
    ("alice-dev", "pathfinder", False, "allowed", 0.6, 0.7),
    ("alice-dev", "dotfiles", False, "none", 0.15, 0.0),
]

ACTORS = ["mhassan", "jlee", "priya-k", "tomasz", "alice-dev", "dependabot[bot]"]

# (weight, phase, rule_id, source, severity, category, confidence, title, description,
#  recommendation, file paths). Weights are per run; most runs have few findings.
CATALOGUE = [
    (
        0.006,
        "secrets",
        "secrets.github-token",
        "rule",
        "critical",
        "security",
        "high",
        "Possible github token committed",
        "An added line matches the format of a github token.",
        "Revoke and rotate the credential, then load it from server-side configuration.",
        ["src/integrations/github.ts", "scripts/release.sh"],
    ),
    (
        0.015,
        "secrets",
        "secrets.assigned-secret",
        "rule",
        "high",
        "security",
        "medium",
        "Hard-coded secret value",
        "A secret-named setting is assigned a literal value in the change.",
        "Read the value from environment configuration instead.",
        ["config/payments.php", "src/settings.py"],
    ),
    (
        0.004,
        "secrets",
        "secrets.sensitive-file",
        "rule",
        "high",
        "security",
        "high",
        "Sensitive file committed",
        ".env.staging looks like a secrets or key file and was added.",
        "Remove it from the repository, rotate anything it contained, and ignore it.",
        [".env.staging"],
    ),
    (
        0.05,
        "secrets",
        "config.tls-verification-disabled",
        "rule",
        "high",
        "security",
        "medium",
        "TLS certificate verification disabled",
        "Added line matches a pattern associated with this risk.",
        "Keep certificate verification on; trust a specific CA bundle instead if needed.",
        ["src/clients/ledger.py", "src/http/client.ts"],
    ),
    (
        0.06,
        "secrets",
        "web.raw-html-sink",
        "rule",
        "medium",
        "security",
        "low",
        "Raw HTML sink (possible XSS)",
        "Added line matches a pattern associated with this risk.",
        "Render text, or sanitise with a vetted library before inserting HTML.",
        ["src/components/ProductDescription.jsx", "src/pages/Help.jsx"],
    ),
    (
        0.012,
        "dependencies",
        None,
        "osv",
        "high",
        "dependency",
        "high",
        "axios 0.21.1 has known vulnerabilities",
        "OSV.dev lists advisories affecting this exact version.",
        "Upgrade to a version outside the affected ranges.",
        ["package.json"],
    ),
    (
        0.07,
        "dependencies",
        "deps.unpinned",
        "rule",
        "low",
        "dependency",
        "high",
        "lodash has no upper version bound",
        "The new constraint accepts any future major version.",
        "Use a caret or tilde range so major upgrades are deliberate.",
        ["package.json", "requirements.txt"],
    ),
    (
        0.03,
        "dependencies",
        "deps.url-source",
        "rule",
        "medium",
        "dependency",
        "high",
        "internal-ui is installed from a URL or git source",
        "The dependency bypasses the registry, so its contents can change without a version bump.",
        "Publish it to a registry, or pin the source to a commit hash.",
        ["package.json"],
    ),
    (
        0.025,
        "dependencies",
        "deps.downgrade",
        "rule",
        "low",
        "dependency",
        "medium",
        "symfony/http-client was downgraded",
        "The constraint now allows only older versions than before.",
        "Confirm the downgrade is intended and note why in the pull request.",
        ["composer.json"],
    ),
    (
        0.18,
        "structure",
        "structure.large-change",
        "rule",
        "low",
        "quality",
        "medium",
        "Large change",
        (
            "Over 800 lines changed across many files (excluding generated files and lockfiles). "
            "Large changes are harder to review thoroughly; this is a heuristic, not a defect."
        ),
        "Split the change into smaller pull requests where practical.",
        [None],
    ),
    (
        0.25,
        "structure",
        "structure.no-test-changes",
        "rule",
        "info",
        "quality",
        "low",
        "Source changed without test changes",
        "Source files changed but no test files did. This is a heuristic, not a defect.",
        "Add or update tests that cover the change, if it is not already covered.",
        [None],
    ),
]

PHASES = [
    "load_event",
    "extract_changes",
    "secrets",
    "dependencies",
    "structure",
    "context",
    "llm_review",
    "normalize_findings",
    "digest",
]


def ago(days: float) -> datetime:
    return NOW - timedelta(seconds=int(days * 86400))


def sha(seed: str) -> str:
    """A git-shaped (40 hex) id for sample commits and deliveries."""
    return hashlib.sha1(seed.encode(), usedforsecurity=False).hexdigest()


def main() -> int:
    parser = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter
    )
    parser.add_argument("--database", help="database to fill (default: DB_NAME from .env)")
    args = parser.parse_args()

    load_dotenv(ROOT / ".env", override=False)
    if os.environ.get("APP_ENV") == "production":
        print("Refusing to seed sample data with APP_ENV=production.", file=sys.stderr)
        return 2
    settings = load_settings(os.environ)
    if args.database:
        settings = replace(settings, db_name=args.database)
    conn = connect(settings)
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) AS n FROM installations")
        if cur.fetchone()["n"]:
            print(
                f"'{settings.db_name}' already has installations; seed an empty database.",
                file=sys.stderr,
            )
            return 2

    rng = random.Random(7)  # noqa: S311 - reproducible sample data, not security
    conn.autocommit(False)
    with conn.cursor() as cur:
        installs: dict[str, int] = {}
        for login, kind, gh_id in ACCOUNTS:
            cur.execute(
                "INSERT INTO installations (github_installation_id, account_login, account_type, created_at, updated_at)"
                " VALUES (%s, %s, %s, %s, %s)",
                (gh_id, login, kind, ago(DAYS), ago(DAYS)),
            )
            installs[login] = cur.lastrowid

        planned = []  # (time, repository id, repository settings)
        for idx, (account, name, private, policy, rate, _tests) in enumerate(REPOS):
            watched = DAYS - idx * 4
            cur.execute(
                "INSERT INTO repositories (installation_id, github_repo_id, full_name, is_private, default_branch,"
                " llm_policy, created_at, updated_at) VALUES (%s, %s, %s, %s, 'main', %s, %s, %s)",
                (
                    installs[account],
                    820000 + idx,
                    f"{account}/{name}",
                    int(private),
                    policy,
                    ago(watched),
                    ago(watched),
                ),
            )
            repo_id = cur.lastrowid
            # Activity is bursty: weekdays busier, a quiet spell for some repositories.
            for day in range(watched, -1, -1):
                when_day = ago(day)
                weekday = when_day.weekday() < 5
                quiet = name == "web-storefront" and 18 <= day <= 27
                expected = rate * (1.4 if weekday else 0.3) * (0.1 if quiet else 1)
                for _ in range(int(expected) + (rng.random() < expected % 1)):
                    at = when_day.replace(
                        hour=rng.randint(7, 19),
                        minute=rng.randint(0, 59),
                        second=rng.randint(0, 59),
                    )
                    if at <= NOW:
                        planned.append((at, repo_id, REPOS[idx]))

        # Insert in time order, as the webhook would, so ids and "new" findings follow time.
        runs: list[tuple[int, int, datetime]] = []  # (run id, installation id, finished)
        seen: dict[tuple[int, str], int] = {}
        for at, repo_id, (account, name, private, policy, _rate, tests) in sorted(planned):
            run = seed_event(
                cur, rng, repo_id, f"{account}/{name}", private, policy, tests, at, seen
            )
            if run:
                runs.append((run[0], installs[account], run[1]))
        conn.commit()

    conn.autocommit(True)
    for run_id, install_id, finished in runs:
        digest = build_run_digest(conn, run_id)
        store_digest(
            conn,
            installation_id=install_id,
            repository_id=digest["repository"]["id"],
            kind="run",
            key=f"run:{run_id}",
            period_start=finished,
            period_end=finished,
            content=digest,
            run_ids=[run_id],
        )
    for install_id in installs.values():
        for day in range(14, 0, -1):
            date_str = (NOW - timedelta(days=day)).strftime("%Y-%m-%d")
            content = build_daily_digest(conn, install_id, date_str)
            start, end = day_bounds(date_str)
            store_digest(
                conn,
                installation_id=install_id,
                repository_id=None,
                kind="daily",
                key=f"daily:{install_id}:{date_str}",
                period_start=start,
                period_end=end,
                content=content,
                run_ids=content["run_ids"],
            )
    print(
        f"Seeded {len(REPOS)} repositories and {len(runs)} analysis runs into '{settings.db_name}'."
    )
    return 0


def seed_event(cur, rng, repo_id, full_name, private, policy, tests, at, seen):
    """One webhook delivery, its normalized event and (usually) an analysis run."""
    is_pr = rng.random() < 0.55
    pr = rng.randint(40, 420) if is_pr else None
    head = sha(f"{full_name}-{at.isoformat()}")
    event_type = (
        rng.choice(["pull_request.opened", "pull_request.synchronize"]) if is_pr else "push"
    )
    delivery = sha(f"delivery-{head}")[:36]
    cur.execute(
        "INSERT INTO webhook_deliveries (github_delivery_id, event, action, repository_id, status, payload_bytes,"
        " correlation_id, received_at) VALUES (%s, %s, %s, %s, 'accepted', %s, %s, %s)",
        (
            delivery,
            "pull_request" if is_pr else "push",
            event_type.split(".")[1] if is_pr else None,
            repo_id,
            rng.randint(6000, 48000),
            f"demo-{delivery[:12]}",
            at,
        ),
    )
    delivery_id = cur.lastrowid
    envelope = {
        "type": event_type,
        "pull_request": {"number": pr} if pr else None,
        "head_sha": head,
    }
    cur.execute(
        "INSERT INTO repository_events (delivery_id, repository_id, schema_version, type, actor_login, ref, head_sha,"
        " pr_number, occurred_at, envelope, created_at) VALUES (%s, %s, 1, %s, %s, %s, %s, %s, %s, %s, %s)",
        (
            delivery_id,
            repo_id,
            event_type,
            rng.choice(ACTORS),
            None if pr else "refs/heads/main",
            head,
            pr,
            at,
            json.dumps(envelope),
            at,
        ),
    )
    event_id = cur.lastrowid

    roll = rng.random()
    recent = (NOW - at) < timedelta(hours=6)
    status = (
        "failed"
        if roll < 0.06
        else "checkpointed"
        if roll < 0.08 and recent
        else "cancelled"
        if roll < 0.09
        else "completed"
    )
    duration_ms = int(rng.lognormvariate(8.6, 0.5))  # ~5.4 s median, long tail
    finished = at + timedelta(milliseconds=duration_ms + 900)
    failure = {
        "failed": "extract_changes: GitHub returned HTTP 502 three times",
        "checkpointed": "GitHub rate limit reached; resumes after the reset time",
    }.get(status)
    cur.execute(
        "INSERT INTO analysis_runs (repository_id, event_id, trigger_type, idempotency_key, status, head_sha, budget_ms,"
        " current_phase, failure_reason, correlation_id, created_at, updated_at, started_at, finished_at)"
        " VALUES (%s, %s, 'webhook', %s, %s, %s, 120000, %s, %s, %s, %s, %s, %s, %s)",
        (
            repo_id,
            event_id,
            f"repo:{repo_id}:{head}",
            status,
            head,
            None if status == "completed" else "extract_changes",
            failure,
            f"demo-{delivery[:12]}",
            at,
            finished,
            at + timedelta(milliseconds=900),
            finished if status in ("completed", "failed") else None,
        ),
    )
    run_id = cur.lastrowid
    if status != "completed":
        cur.execute(
            "INSERT INTO analysis_checkpoints (run_id, phase, status, started_at, finished_at, duration_ms, state)"
            " VALUES (%s, 'load_event', 'completed', %s, %s, 12, '{}')",
            (run_id, at, at),
        )
        return (run_id, finished) if status == "failed" else None

    lines_added = int(rng.lognormvariate(4.6, 1.0))
    source_lines = int(lines_added * rng.uniform(0.5, 0.9))
    test_files = rng.randint(1, 4) if rng.random() < tests else 0
    osv = "unavailable: timeout" if rng.random() < 0.04 else "queried"
    dep_changes = (
        [{"name": "lodash", "from": "4.17.20", "to": "4.17.21"}] if rng.random() < 0.2 else []
    )
    llm_reason = (
        "blocked_by_privacy_policy" if (private or policy == "none") else "llm_not_configured"
    )
    states = {
        "extract_changes": {
            "files": [
                {
                    "path": f"src/module_{i}.py",
                    "status": "modified",
                    "additions": 12,
                    "deletions": 3,
                }
                for i in range(rng.randint(1, 30))
            ],
            "files_truncated": False,
        },
        "dependencies": {
            "changes": dep_changes,
            "manifests": ["package.json"] if dep_changes else [],
            "osv": osv,
        },
        "structure": {
            "metrics": {
                "lines_added": lines_added,
                "lines_deleted": int(lines_added * rng.uniform(0.1, 0.6)),
                "source_lines_added": source_lines,
                "test_files": test_files,
                "files_changed": rng.randint(1, 30),
                "files_generated_or_lockfile": 0,
                "new_files": rng.randint(0, 4),
                "areas_touched": rng.sample(
                    ["src/api", "src/billing", "src/ui", "config", "tests", "docs"], 2
                ),
            },
            "findings": [],
        },
        "llm_review": {"reason": llm_reason},
    }
    t = at + timedelta(milliseconds=900)
    for phase in PHASES:
        step = max(
            5, int(duration_ms * {"extract_changes": 0.45, "dependencies": 0.3}.get(phase, 0.04))
        )
        cur.execute(
            "INSERT INTO analysis_checkpoints (run_id, phase, status, started_at, finished_at, duration_ms, state)"
            " VALUES (%s, %s, %s, %s, %s, %s, %s)",
            (
                run_id,
                phase,
                "skipped" if phase == "llm_review" else "completed",
                t,
                t + timedelta(milliseconds=step),
                step,
                json.dumps(states.get(phase, {})),
            ),
        )
        t += timedelta(milliseconds=step)

    for weight, phase, rule_id, source, sev, cat, conf, title, desc, advice, paths in CATALOGUE:
        if rule_id == "structure.large-change":
            hit = lines_added > 800
        elif rule_id == "structure.no-test-changes":
            hit = test_files == 0 and source_lines > 50
        else:
            hit = rng.random() < weight
        if not hit:
            continue
        path = rng.choice(paths)
        fingerprint = hashlib.sha256(f"{repo_id}|{rule_id or title}|{path}".encode()).hexdigest()
        first = seen.setdefault((repo_id, fingerprint), run_id)
        line = rng.randint(3, 240) if path else None
        cur.execute(
            "INSERT INTO analysis_findings (run_id, repository_id, phase, fingerprint, severity, category, confidence,"
            " title, description, file_path, line_start, recommendation, source, rule_id, is_new, created_at)"
            " VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
            (
                run_id,
                repo_id,
                phase,
                fingerprint,
                sev,
                cat,
                conf,
                title,
                desc,
                path,
                line,
                advice,
                source,
                rule_id,
                int(first == run_id),
                finished,
            ),
        )
    return run_id, finished


if __name__ == "__main__":
    raise SystemExit(main())

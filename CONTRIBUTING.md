# Contributing to Keelwatch

Thanks for helping. This guide covers setup, the checks a change must pass,
and the conventions the codebase follows.

## Before you start

- **Bugs:** open an issue with the bug template. Include the correlation ID
  ("Reference" in the dashboard, `correlation_id` in logs) if you have one.
- **Features:** open an issue first so the approach can be agreed before you
  write code. Changes that cross the PHP/Python boundary or alter the schema
  are much easier to land with a short design note up front.
- **Security problems:** do not open an issue. Follow [SECURITY.md](SECURITY.md).

## Setup

Follow [Quick start](README.md#quick-start). For a populated dashboard
without GitHub, run `scripts/seed_demo.py` against your development database.

## Checks

CI runs the following on every pull request. Please run them locally first:

```bash
# API
cd api
php bin/lint.php
vendor/bin/phpunit --testsuite unit,contract
vendor/bin/phpunit --testsuite integration     # needs DB_TEST_NAME

# Worker
cd worker
.venv/bin/ruff check . && .venv/bin/ruff format --check .
.venv/bin/python -m pytest
php ../api/bin/migrate.php --test && .venv/bin/python -m pytest -m integration

# Dashboard
cd web
npm run lint && npm test && npm run build && npm run test:e2e
```

Integration tests drop the tables in `DB_TEST_NAME`. Never point it at a
database you care about.

## Conventions

**General**

- Keep changes focused. A pull request should do one thing; refactors go in
  their own pull request.
- Match the surrounding code: its naming, comment density and error handling.
- No secrets, tokens or real webhook URLs in code, tests, fixtures or logs.

**Database**

- Schema changes are new numbered files in `db/migrations/`. Never edit a
  migration that has been merged.
- Only the API runs migrations. The worker must not issue DDL.
- Store timestamps in UTC (`DATETIME(3)`), and add a constraint when a column
  has rules the code relies on.

**Cross-language contracts**

- Anything written by one side and read by the other (event envelopes, job
  payloads, findings) has a JSON Schema in `contracts/`. Change the schema and
  add valid and invalid fixtures in the same pull request. Both test suites
  run them.
- Bump the schema version for incompatible changes.

**API (PHP)**

- `declare(strict_types=1);` in every file, prepared statements only.
- New routes are private by default; mark public routes explicitly, and
  update `RouteTableTest` (it pins the access level of every route).
- Escape output by default ([ADR 0002](docs/adr/0002-escape-by-default.md)).

**Worker (Python)**

- Phases must be idempotent and checkpoint their state; a resumed run skips
  completed phases.
- Network calls go through the existing clients, which enforce timeouts,
  allowed hosts and redaction.
- Findings must state a confidence and their provenance (`rule_id`, OSV, or
  provider and model).

**Dashboard (React)**

- Every data view handles loading, empty, error and partial states (see
  [docs/ux-state-matrix.md](docs/ux-state-matrix.md)).
- Charts need a table view, and colours must work in both themes.
- No horizontal overflow from 375 px up; the browser tests check this.

**Signals and wording**

- Findings are signals with a confidence, not verdicts. Never report a skipped
  or failed check as a clean result: record it as a gap.
- Readiness criteria describe repositories, never people. Don't add combined
  scores, rankings or per-author metrics
  ([ADR 0004](docs/adr/0004-readiness-signals.md)).

## Commits and pull requests

- Write commit messages in the imperative ("Add …", "Fix …"), with a body
  that explains *why* when it isn't obvious.
- Fill in the pull request template, including how you tested the change.
- Add an ADR in `docs/adr/` for decisions that are hard to reverse: new
  infrastructure, a new external service, or a change to privacy defaults.

## Contributor License Agreement

This project is licensed under the PolyForm Noncommercial License 1.0.0, with paid commercial licenses available from the maintainer. Contributions are accepted only under the [Contributor License Agreement](CLA.md), which lets the maintainer relicense and sell them. Pull requests are merged only after you have agreed to it in the pull request template.

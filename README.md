# Keelwatch

Keelwatch turns GitHub repository activity into evidence-backed engineering
signals: code-quality findings, dependency and security changes, and
digests.

> **Status: Phase 1 (foundation).** Services boot, report honest health, and
> the dashboard shows real system status. Webhook ingestion, the job queue
> and analysis arrive in later phases. The full README (architecture,
> screenshots, deployment) is written in Phase 13.

## Layout

| Path | What it is |
| --- | --- |
| `api/` | PHP 8.4 API: webhook ingestion (Phase 3) and dashboard JSON API |
| `worker/` | Python 3.12 analysis worker |
| `web/` | React + Vite dashboard |
| `db/migrations/` | Numbered MySQL migrations, applied only by the API |
| `docs/adr/` | Architecture decision records |

## Requirements

- PHP 8.4 with `pdo_mysql`, `mbstring`, `zip`, and Composer
- Python 3.12
- Node.js 24 and npm
- MySQL 8.4

## Local setup

1. Copy `.env.example` to `.env` and fill in the database values. All
   services read this one file.
2. Create the database and user (as a MySQL admin), then apply migrations:

   ```bash
   php api/bin/migrate.php
   php api/bin/migrate.php --test
   ```

3. Install dependencies:

   ```bash
   cd api && composer install
   cd worker && python -m venv .venv && .venv/Scripts/pip install -r requirements-dev.txt
   cd web && npm install
   ```

## Running

| Service | Command (from its directory) | URL |
| --- | --- | --- |
| API | `composer serve` | http://127.0.0.1:8080 |
| Worker | `.venv/Scripts/python -m keelwatch_worker` | — |
| Dashboard | `npm run dev` | http://localhost:5173 |

The dashboard dev server proxies `/api`, `/healthz` and `/readyz` to the API.

Health endpoints:

- `GET /healthz`: the API process is up (no dependencies checked)
- `GET /readyz`: 200 only when the database is reachable, 503 otherwise
- `GET /api/system/health`: per-component report behind the System Health page
- `python -m keelwatch_worker --check`: the worker's one-shot readiness probe

## GitHub webhooks

`POST /webhooks/github` accepts GitHub App deliveries (`push`, `pull_request`,
`installation`, `installation_repositories`, `ping`). Per request:

1. Rejects early, without reading the database: non-JSON content type (415),
   malformed GitHub headers (400), body over `WEBHOOK_MAX_BODY_BYTES` (413).
2. Verifies `X-Hub-Signature-256` over the raw body (HMAC-SHA256,
   constant-time compare; `GITHUB_WEBHOOK_SECRET_PREVIOUS` supports rotation).
   Failures return 401 and are counted per client; past
   `WEBHOOK_FAILED_AUTH_LIMIT` per window they return 429. Valid deliveries
   never touch the counter.
3. In one transaction: records the delivery (a repeated delivery ID returns
   200 `duplicate`), syncs installations and repositories, stores the
   normalized event (`contracts/github-event.v1.json`), and enqueues a job.
   No raw payload and no email address is stored.
4. Answers 202 with a `Server-Timing` header (`verify`, `db`, `total`).

Signed payloads with an unexpected shape are recorded as ignored
(`invalid_payload`) rather than failing, so GitHub doesn't retry them
forever. A database outage returns 503 so the delivery can be redelivered.

Send signed test deliveries to a local API (loopback only; secret read
from `.env`):

```bash
php scripts/send-webhook.php push
php scripts/send-webhook.php pull_request.opened
php scripts/send-webhook.php push --bad-signature
php scripts/send-webhook.php push --repeat=200
```

## Analysis pipeline

Each analysis run executes these phases in order. Every phase has a time
cap, commits a checkpoint, and is skipped on resume once it has completed.

| Phase | What it does |
| --- | --- |
| `load_event` | Loads the triggering event and repository |
| `extract_changes` | Fetches changed files and patches from GitHub (no clone); masks secrets and emails before anything is stored |
| `secrets` | Credentials, sensitive files and insecure patterns in *added* lines, with file and line |
| `dependencies` | Diffs `package.json`, `composer.json`, `requirements*.txt`; flags URL/git sources, unbounded ranges, downgrades; checks exact versions against OSV.dev |
| `structure` | Change size, spread and test changes (heuristics, marked low confidence) |
| `context` | Bounded, redacted diff text for optional LLM review |
| `llm_review` | Optional; only when a provider is configured and the repository's privacy policy allows it |
| `normalize_findings` | Stores findings (`contracts/finding.v1.json`) with stable fingerprints |

Findings are signals with a stated confidence, not verdicts. Rate limits or
outages at GitHub checkpoint the run and retry it; an OSV outage is recorded
as a gap, never as "no vulnerabilities".

## Digests and notifications

Every analysis run ends with a `digest` phase that stores a summary: counts
by severity, new vs recurring findings, the top findings, and a **gaps**
list of anything that was not checked (so a quiet digest is never mistaken
for a clean one). After `DIGEST_DAILY_HOUR_UTC`, the worker also stores one
daily digest per installation for the previous UTC day.

Notifications to Slack or Discord are optional and off until
`NOTIFICATION_KEY` is set (the API and the worker read the same key).
Administrators manage destinations on the dashboard's **Integrations** page:
add, pause, change the severity threshold, replace a leaked URL, or remove.
The webhook URL is write-only: it is stored AES-256-GCM encrypted and never
returned by the API or shown again. The same page holds per-repository
settings (pause analysis; AI review off / only while public / allowed).
Viewers can see all of this but change none of it.

The worker CLI does the same from a terminal (the URL is read at a hidden
prompt):

```bash
python -m keelwatch_worker destinations add --installation <github installation id> --kind slack --label team --min-severity high
python -m keelwatch_worker destinations list
python -m keelwatch_worker destinations disable <id>
```

Safety rules: only exact Slack/Discord webhook URLs that resolve to public
addresses (the API and worker are held to the same cases in
`contracts/test-vectors/notification-destination-urls.v1.json`); no redirects; Discord messages can't ping anyone and Slack
control sequences are escaped; messages never contain code or evidence,
and for private repositories contain no repository name, paths or finding
titles. Delivery is at-least-once: a crash between sending and recording
the delivery can repeat a message.

## Analytics

The **Analytics** page shows 7/30/90-day trends (UTC calendar days), for all
repositories or one: runs per day with failures emphasised, new vs
recurring findings, completed-run time (median and p95), and findings by
severity and category. It is deliberately conservative:

- days before Keelwatch started watching are shown as *no data*, not zero;
- a day needs at least 3 completed runs before it gets a percentile, and
  failed or cancelled runs are left out of run time;
- percentiles are nearest-rank (always an observed value);
- a period with only one or two active days is labelled a snapshot, not a
  trend.

Every chart has a "Show as table" view with the same numbers. Chart colours
were checked for colour-vision separation and contrast in both themes.

## Tests

```bash
cd api && vendor/bin/phpunit --testsuite unit,contract && vendor/bin/phpunit --testsuite integration
cd worker && .venv/Scripts/python -m pytest && .venv/Scripts/python -m pytest -m integration
cd web && npm run lint && npm test && npm run test:e2e
```

Integration tests use the database named by `DB_TEST_NAME` and drop its
tables. The PHP integration suite leaves that database reset, so run
`php api/bin/migrate.php --test` before the worker integration tests. Browser tests mock the API and check for horizontal overflow at
375, 390, 768, 1024, 1280 and 1440 px.

## License

MIT (license file added in Phase 13).

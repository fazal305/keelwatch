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

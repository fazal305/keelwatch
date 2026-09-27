# ADR 0001 — Technology stack

Status: accepted · 2026-09-27

## Context

The original specification called for NestJS (TypeScript), Kafka, a FastAPI
worker, PostgreSQL with Prisma, and a Next.js dashboard. The maintainer
works in PHP, Python, React + Vite and MySQL, and has not used Kafka,
Docker, NestJS or Next.js. The development machine has no Docker or WSL.

A system nobody on the team can debug is not maintainable, and at the
expected volume (single team, hundreds of events per hour at most) Kafka's
throughput and partitioning buy nothing a database-backed queue cannot.

## Decision

| Concern | Choice |
| --- | --- |
| Webhook ingestion + dashboard API | PHP 8.4, no framework, PDO |
| Queue | MySQL tables claimed with `SELECT … FOR UPDATE SKIP LOCKED` (Phase 4) |
| Analysis worker | Python 3.12 process; no web server |
| Database | MySQL 8.4; numbered `.sql` migrations owned by the API |
| Dashboard | React 19 + Vite, JSX, plain CSS with design tokens |
| Tests | PHPUnit, pytest, Vitest, Playwright |

## Consequences

- Persisting the webhook delivery and its job in one transaction *is* the
  enqueue, so there is no separate outbox/relay and no window where an
  accepted webhook exists only in a broker.
- Workers report liveness through a `worker_heartbeats` table instead of an
  HTTP health endpoint; the API aggregates it.
- The queue sits behind a small interface (enqueue in PHP, claim in
  Python) so a broker can be introduced later without touching the phases.
- `SKIP LOCKED` requires MySQL 8.0+; managed hosts must provide 8.x.
- Only the API runs migrations. The worker never issues DDL.

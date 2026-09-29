# Performance

Everything here was **measured on one development machine** unless marked
otherwise. Treat the numbers as relative (before/after, endpoint vs endpoint),
not as production capacity. Production hardware, PHP-FPM and a tuned MySQL
will differ.

**Machine and software:** Windows 11 Pro, PHP 8.4.24, Python 3.12, MySQL
8.4.9 with default settings (`innodb_buffer_pool_size` 128 MB,
`innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`, binary log on,
`max_connections` 151). Measured 2026-09-29.

**Where it runs:** every benchmark uses the separate test database
(`DB_TEST_NAME`) and refuses to start if it equals `DB_NAME`.

| Benchmark | Command |
|---|---|
| Queue throughput and correctness | `worker/.venv/Scripts/python.exe bench/queue_throughput.py --jobs 2000 --workers 1 2 4 8` |
| Dashboard API and webhook at scale | `php bench/dashboard.php --scale=1 --iterations=15` |
| Connection cost and MySQL settings | `php bench/connect.php` |
| Query plans | `php bench/explain.php query.sql` (after seeding) |
| Frontend page loads | `cd web && npx playwright test -c perf/playwright.perf.config.js` |

## 1. Job queue ("Kafka throughput")

Keelwatch uses a MySQL queue (`SELECT … FOR UPDATE SKIP LOCKED` with leases),
not Kafka; see ADR 0001. The benchmark enqueues 2,000 no-op jobs and runs K
worker **processes**, each with its own connection, through the production
`JobQueue.claim()` / `complete()`. It then checks that every job succeeded
exactly once.

| Workers | Before (jobs/s) | After (jobs/s) | Jobs per worker (after) | Claimed twice |
|---|---|---|---|---|
| 1 | 45.2 | 62.3 | 2000 | 0 |
| 2 | 83.1 | 102.6 | 1000, 1000 | 0 |
| 4 | 41.8 | **228.6** | 497–502 | 0 |
| 8 | 84.6 | **443.9** | 248–254 | 0 |

**Bug found and fixed (migration 0010).**
- **Cause:** the claim orders by `priority DESC, id`, but the index was
  `(queue, status, run_after, priority, id)`, so MySQL sorted every due row
  and, under `FOR UPDATE`, **locked all of them** on every claim.
- **Symptom:** competing workers SKIP-LOCKED past the whole backlog and saw an
  empty queue. With 4 workers, three of them completed 7 jobs each.
- **Fix:** the index is now `(queue, status, priority DESC, id, run_after)`.
  EXPLAIN shows an ordered covering-index scan that stops at the first row.

**Remaining floor.** About 15 ms per job is two durable commits (claim, then
complete), each waiting for the redo log and binary log to reach disk
(`innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`). This is kept on
purpose: relaxing it trades crash durability for throughput.

**What this does not measure:** real job cost. An analysis job spends its
time in GitHub API calls, OSV lookups and optional AI review, which the
no-op handler excludes.

**Sizing.** Each worker holds one connection, and the API holds one per
in-flight request. Workers plus PHP-FPM children must stay under
`max_connections`.

## 2. Webhook ingestion

| Path | p50 | p95 | Conditions |
|---|---|---|---|
| In-process: signature check → dedupe → store → enqueue | 7.5 ms | 12.0 ms | 200 push deliveries, test DB already holding 40k deliveries |
| Over HTTP (PHP built-in server), handler time | 34 ms | 47 ms | Phase 3 measurement, 200 sequential requests |
| Over HTTP, client round trip | 78 ms | 110 ms | same |

The HTTP numbers include a fresh database connection per request (see §3).

**Unverified:** concurrency. The PHP built-in server handles one request at a
time, so it can't show concurrent throughput. That needs PHP-FPM behind a web
server on the target host.

## 3. Database connections ("pool tuning")

- **API:** PHP has no connection pool. Each request opens one connection
  lazily and shares it across services for that request.
  - On this machine a connection costs **14.1 ms** (p50) for the handshake,
    plus 0.6 ms for `SET time_zone`. Python's pymysql takes 63 ms for the same
    handshake, so the cost is on the server/auth side, not in PHP.
  - That is most of an API request's time at small scale.
- **Persistent PDO connections are deliberately not enabled.** A request that
  dies mid-transaction would leave the transaction and session state open for
  the next request that reuses the connection. If the target host shows the
  same connect cost, the safer fix is a pooler in front of MySQL (such as
  ProxySQL) or a check of the account's authentication path. **Unverified**
  on production hardware.
- **Worker:** long-lived connections, one per process, so the handshake cost
  is paid once.

## 4. Dashboard API at scale

The seeded history covers 90 days with 25 repositories, 40,000 events, 20,000
runs, 60,000 findings and 5,000 digests. Each figure is the p50 of in-process
requests (PHP and MySQL work, no HTTP), after two warm-up requests.

| Endpoint | Before | After | Change |
|---|---|---|---|
| `/api/readiness` | **> 77,000 ms** (one query) | 805 ms | Correlated `MAX(id)` per run became a grouped derived table, plus an index |
| `/api/analytics?days=90` | 2,899 ms | 354 ms | Stored `is_new` (migration 0012) instead of a per-row lookup |
| `/api/analytics?days=30` | 1,434 ms | 186 ms | same |
| `/api/events` | 572 ms | 4.0 ms | `MIN(id)` instead of `ORDER BY id LIMIT 1`, which scanned the runs primary key per event |
| `/api/overview` | 188 ms | 10.5 ms | index `(repository_id, status, id)` |
| `/api/repositories/{id}` | 38 ms | 8.5 ms | same |
| `/api/findings` | 9.0 ms | 4.5 ms | stored `is_new` |

Everything else was already 4–12 ms: run lists, run, finding and digest
detail, digests, destinations and system health. `/api/repositories` is
50 ms, from per-repository `MAX()` subqueries that are cheap at this count.

**Why store `is_new`.** Whether a finding is new or recurring was computed on
every read, one index probe per finding in the window. The worker now sets it
when it stores findings. It also clears it on a *later* run's copy if an
earlier run of the same repository finishes after it, so the stored value
always equals the definition. Four worker integration tests cover this,
including out-of-order runs and a check across mixed orderings.

**Known remaining cost: readiness at 805 ms.** It parses the stored JSON
change metrics of every completed run in the 90-day window (about 17,000 here)
and checks every dependency checkpoint. That's inherent to computing these
signals from JSON on demand. If a real installation reaches this volume, the
fix is to store the change metrics as numeric columns when runs complete; not
done yet, because nothing at current scale needs it.

## 5. Dashboard frontend

This uses the production build with the API mocked to answer instantly, so
it measures frontend cost only. Each figure is the median of 5 cold loads.

| Profile | Page | First paint | Heading visible | JS + CSS + HTML |
|---|---|---|---|---|
| This machine | Overview, Findings, Readiness | ~130 ms | 218–230 ms | 114 KB |
| This machine | Analytics | ~130 ms | 345 ms | 173 KB |
| Phone approximation (4× CPU, ~1.6 Mbps, 150 ms RTT) | Overview, Findings, Readiness | ~1.3 s | 1.75–1.81 s | 114 KB |
| Phone approximation | Analytics | ~1.3 s | 2.7 s | 173 KB |

- **Why Analytics is slower:** it loads Chart.js (61 KB gzip) as a separate
  chunk, so no other page pays for it.
- **Not counted:** font files. The byte column covers scripts, styles and the
  document.
- **Emulation only:** the phone row is DevTools emulation, not a real device.

## 6. AI review latency: unverified

No AI-provider calls were made: no keys are configured, and no paid credits
were used. What exists:
- a per-call timeout (`LLM_TIMEOUT_S`, default 30 s);
- the run budget, which checkpoints the run rather than overrunning;
- circuit breakers, so a failing provider is skipped;
- every call recorded in `provider_calls` with `latency_ms`, outcome, tokens
  and estimated cost.

Once a provider is configured, real latency is a query:

```sql
SELECT provider, model, COUNT(*) AS calls,
       AVG(latency_ms) AS avg_ms, MAX(latency_ms) AS max_ms
  FROM provider_calls
 WHERE outcome = 'success'
 GROUP BY provider, model;
```

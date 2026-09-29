"""Queue throughput and correctness under concurrent workers.

Runs against the TEST database only (DB_TEST_NAME), in its own queue name, and
deletes its jobs afterwards. Each worker is a separate OS process with its own
connection, claiming with the production JobQueue (SELECT ... FOR UPDATE SKIP
LOCKED) and completing each job in a transaction, with a no-op handler. So
this measures queue overhead only, not analysis work.

    worker/.venv/Scripts/python.exe bench/queue_throughput.py --jobs 2000 --workers 1 2 4 8
"""

from __future__ import annotations

import argparse
import json
import multiprocessing as mp
import os
import statistics
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "worker"))

from dotenv import dotenv_values  # noqa: E402

from keelwatch_worker.config import load_settings  # noqa: E402
from keelwatch_worker.db import connect  # noqa: E402
from keelwatch_worker.queue import JobQueue  # noqa: E402

# Queue names are constrained to the real ones; the test DB's 'scheduled' queue
# is used, guarded so the run refuses to start if it holds anything else.
QUEUE = "scheduled"
KEY_PREFIX = "bench-"


def test_settings(worker_id: str):
    values = {**dotenv_values(ROOT / ".env"), **os.environ}
    test_db = (values.get("DB_TEST_NAME") or "").strip()
    if not test_db or test_db == values.get("DB_NAME"):
        raise SystemExit("DB_TEST_NAME must be set and differ from DB_NAME; refusing to touch the dev database")
    values["WORKER_ID"] = worker_id
    return load_settings({k: v for k, v in values.items() if v is not None}).with_database(test_db)


def enqueue(n: int, run: str) -> None:
    conn = connect(test_settings("bench-seed"))
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) AS n FROM jobs WHERE queue = %s AND idempotency_key NOT LIKE %s", (QUEUE, KEY_PREFIX + "%"))
        if cur.fetchone()["n"]:
            raise SystemExit(f"test DB queue {QUEUE!r} has non-benchmark jobs; refusing to run")
        cur.execute("DELETE FROM jobs WHERE queue = %s AND idempotency_key LIKE %s", (QUEUE, KEY_PREFIX + "%"))
        rows = [(QUEUE, "noop", json.dumps({"i": i}), f"bench-{run}-{i}", "corr-bench") for i in range(n)]
        for start in range(0, n, 500):
            cur.executemany(
                "INSERT INTO jobs (queue, type, payload, idempotency_key, correlation_id) VALUES (%s, %s, %s, %s, %s)",
                rows[start : start + 500],
            )
    conn.close()


def work(worker_id: str, barrier, results) -> None:
    settings = test_settings(worker_id)
    conn = connect(settings)
    queue = JobQueue(conn, worker_id, lease_s=60)
    done = 0
    latencies = []
    barrier.wait()
    while True:
        t0 = time.perf_counter()
        job = queue.claim(QUEUE)
        if job is None:
            break
        conn.begin()
        ok = queue.complete(job)
        conn.commit() if ok else conn.rollback()
        latencies.append((time.perf_counter() - t0) * 1000)
        done += ok
    conn.close()
    results.put((worker_id, done, latencies))


def run_once(jobs: int, workers: int) -> dict:
    run = f"{workers}-{time.time_ns()}"
    enqueue(jobs, run)
    ctx = mp.get_context("spawn")
    barrier = ctx.Barrier(workers + 1)
    results = ctx.Queue()
    procs = [ctx.Process(target=work, args=(f"bench-{workers}-{i}", barrier, results)) for i in range(workers)]
    for p in procs:
        p.start()
    barrier.wait()
    t0 = time.perf_counter()
    collected = [results.get() for _ in procs]
    elapsed = time.perf_counter() - t0
    for p in procs:
        p.join()

    conn = connect(test_settings("bench-check"))
    with conn.cursor() as cur:
        cur.execute(
            "SELECT COUNT(*) AS n, SUM(status = 'succeeded') AS ok, SUM(attempts <> 1) AS retried, "
            "COUNT(DISTINCT locked_by) AS stuck FROM jobs WHERE queue = %s AND idempotency_key LIKE %s",
            (QUEUE, KEY_PREFIX + "%"),
        )
        check = cur.fetchone()
        cur.execute("DELETE FROM jobs WHERE queue = %s AND idempotency_key LIKE %s", (QUEUE, KEY_PREFIX + "%"))
    conn.close()

    lat = sorted(x for _, _, ls in collected for x in ls)
    done = sum(d for _, d, _ in collected)
    return {
        "workers": workers,
        "jobs": jobs,
        "completed_by_workers": done,
        "succeeded_in_db": int(check["ok"] or 0),
        "claimed_more_than_once": int(check["retried"] or 0),
        "seconds": round(elapsed, 2),
        "jobs_per_s": round(done / elapsed, 1),
        "claim_complete_ms_p50": round(statistics.median(lat), 2),
        "claim_complete_ms_p95": round(lat[int(0.95 * (len(lat) - 1))], 2),
        "per_worker": sorted(d for _, d, _ in collected),
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--jobs", type=int, default=2000)
    parser.add_argument("--workers", type=int, nargs="+", default=[1, 2, 4, 8])
    args = parser.parse_args()
    for k in args.workers:
        r = run_once(args.jobs, k)
        print(json.dumps(r))
        if r["succeeded_in_db"] != args.jobs or r["claimed_more_than_once"] or r["completed_by_workers"] != args.jobs:
            raise SystemExit(f"CORRECTNESS FAILURE: {r}")


if __name__ == "__main__":
    main()

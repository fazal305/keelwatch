-- Claim path index, reordered to match the claim query's ORDER BY.
--
-- The claim is:
--   WHERE queue = ? AND status = 'queued' AND run_after <= now
--   ORDER BY priority DESC, id LIMIT 1 FOR UPDATE SKIP LOCKED
--
-- 0004's (queue, status, run_after, priority, id) could not deliver rows in
-- priority/id order (run_after sits in between), so MySQL read every due row,
-- sorted them, and with FOR UPDATE locked all of them. Concurrent workers then
-- SKIP LOCKED past the whole backlog and saw an empty queue, and each claim
-- cost O(backlog). Measured in bench/queue_throughput.py: with 4 workers,
-- three of them completed 7 jobs each out of 2,000.
--
-- With (queue, status, priority DESC, id) the scan is already in claim order
-- and stops at the first unlocked, due row; run_after is last so the retry
-- delay is checked inside the index without a table lookup.

ALTER TABLE jobs
    DROP INDEX idx_jobs_claim,
    ADD INDEX idx_jobs_claim (queue, status, priority DESC, id, run_after);

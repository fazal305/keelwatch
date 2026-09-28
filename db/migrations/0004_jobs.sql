-- The job queue (ADR 0001). Replaces Kafka topics with one table:
--   queue = 'events'   ~ github.events
--   queue = 'analysis' ~ analysis.jobs
--   status = 'dead'    ~ analysis.dlq
--
-- Producers insert with an idempotency key; a duplicate insert is a no-op.
-- Workers claim with SELECT ... FOR UPDATE SKIP LOCKED and hold a lease
-- (locked_until); a crashed worker's lease expires and the job is retried.
-- Retries set status back to 'queued' with a later run_after.

CREATE TABLE jobs (
    id               BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT PRIMARY KEY,
    queue            VARCHAR(32)       NOT NULL,
    type             VARCHAR(64)       NOT NULL,
    payload          JSON              NOT NULL,
    idempotency_key  VARCHAR(191)      NOT NULL,
    status           ENUM('queued', 'running', 'succeeded', 'dead', 'cancelled') NOT NULL DEFAULT 'queued',
    priority         SMALLINT          NOT NULL DEFAULT 0,
    attempts         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts     SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    run_after        DATETIME(3)       NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    locked_by        VARCHAR(128)      NULL,
    locked_until     DATETIME(3)       NULL,
    last_error       VARCHAR(1000)     NULL,
    correlation_id   VARCHAR(128)      NOT NULL,
    created_at       DATETIME(3)       NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    updated_at       DATETIME(3)       NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    finished_at      DATETIME(3)       NULL,
    UNIQUE KEY uq_jobs_idempotency (idempotency_key),
    -- Claim path: WHERE queue = ? AND status = 'queued' AND run_after <= now ORDER BY priority DESC, id
    KEY idx_jobs_claim (queue, status, run_after, priority, id),
    -- Lease recovery path: WHERE status = 'running' AND locked_until < now
    KEY idx_jobs_lease (status, locked_until),
    CONSTRAINT chk_jobs_payload CHECK (JSON_TYPE(payload) = 'OBJECT'),
    CONSTRAINT chk_jobs_attempts CHECK (max_attempts BETWEEN 1 AND 100 AND attempts <= max_attempts),
    CONSTRAINT chk_jobs_lease CHECK ((status = 'running') = (locked_by IS NOT NULL AND locked_until IS NOT NULL)),
    CONSTRAINT chk_jobs_queue CHECK (queue IN ('events', 'analysis'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

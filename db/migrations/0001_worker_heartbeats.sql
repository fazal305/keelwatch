-- Worker liveness. Each worker process upserts its own row on an interval;
-- the API reports a worker as stale when last_seen_at falls too far behind.
-- All timestamps are UTC.
CREATE TABLE worker_heartbeats (
    worker_id    VARCHAR(128) NOT NULL PRIMARY KEY,
    pid          INT UNSIGNED NOT NULL,
    version      VARCHAR(32)  NOT NULL,
    status       ENUM('running', 'stopping', 'stopped') NOT NULL,
    started_at   DATETIME(3)  NOT NULL,
    last_seen_at DATETIME(3)  NOT NULL,
    stopped_at   DATETIME(3)  NULL,
    KEY idx_worker_heartbeats_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

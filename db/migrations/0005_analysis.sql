-- Analysis runs, their per-phase checkpoints, findings, and LLM provider
-- calls. Every finding traces back to run -> event -> delivery -> repository,
-- plus the phase and (for LLM findings) provider and model.

CREATE TABLE analysis_runs (
    id               BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT PRIMARY KEY,
    repository_id    BIGINT UNSIGNED   NOT NULL,
    event_id         BIGINT UNSIGNED   NULL,
    trigger_type     ENUM('webhook', 'schedule', 'manual', 'resume') NOT NULL,
    idempotency_key  VARCHAR(191)      NOT NULL,
    status           ENUM('queued', 'running', 'checkpointed', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'queued',
    head_sha         VARCHAR(64)       NULL,
    budget_ms        INT UNSIGNED      NOT NULL,
    deadline_at      DATETIME(3)       NULL,
    current_phase    VARCHAR(32)       NULL,
    attempt          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    failure_reason   VARCHAR(1000)     NULL,
    correlation_id   VARCHAR(128)      NOT NULL,
    created_at       DATETIME(3)       NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    updated_at       DATETIME(3)       NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    started_at       DATETIME(3)       NULL,
    finished_at      DATETIME(3)       NULL,
    UNIQUE KEY uq_runs_idempotency (idempotency_key),
    KEY idx_runs_repository_time (repository_id, created_at),
    KEY idx_runs_status (status, updated_at),
    CONSTRAINT fk_runs_repository
        FOREIGN KEY (repository_id) REFERENCES repositories (id) ON DELETE RESTRICT,
    CONSTRAINT fk_runs_event
        FOREIGN KEY (event_id) REFERENCES repository_events (id) ON DELETE SET NULL,
    CONSTRAINT chk_runs_budget CHECK (budget_ms BETWEEN 1000 AND 3600000),
    CONSTRAINT chk_runs_failure CHECK (status NOT IN ('failed', 'checkpointed') OR failure_reason IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE analysis_checkpoints (
    id           BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT PRIMARY KEY,
    run_id       BIGINT UNSIGNED   NOT NULL,
    phase        VARCHAR(32)       NOT NULL,
    attempt      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    status       ENUM('running', 'completed', 'failed', 'skipped') NOT NULL,
    started_at   DATETIME(3)       NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    finished_at  DATETIME(3)       NULL,
    duration_ms  INT UNSIGNED      NULL,
    state        JSON              NULL,
    error        VARCHAR(1000)     NULL,
    UNIQUE KEY uq_checkpoints_run_phase_attempt (run_id, phase, attempt),
    CONSTRAINT fk_checkpoints_run
        FOREIGN KEY (run_id) REFERENCES analysis_runs (id) ON DELETE CASCADE,
    CONSTRAINT chk_checkpoints_error CHECK (status <> 'failed' OR error IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE analysis_findings (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    run_id          BIGINT UNSIGNED NOT NULL,
    repository_id   BIGINT UNSIGNED NOT NULL,
    phase           VARCHAR(32)     NOT NULL,
    -- sha256 of rule/category + location + normalized evidence: the same
    -- issue in a later run gets the same fingerprint (recurring vs new).
    fingerprint     CHAR(64)        NOT NULL,
    severity        ENUM('critical', 'high', 'medium', 'low', 'info') NOT NULL,
    category        ENUM('security', 'logic', 'dependency', 'architecture', 'quality') NOT NULL,
    confidence      ENUM('low', 'medium', 'high') NOT NULL,
    title           VARCHAR(200)    NOT NULL,
    description     TEXT            NOT NULL,
    evidence        JSON            NULL,
    file_path       VARCHAR(1024)   NULL,
    line_start      INT UNSIGNED    NULL,
    line_end        INT UNSIGNED    NULL,
    recommendation  TEXT            NULL,
    source          ENUM('rule', 'llm', 'osv') NOT NULL,
    rule_id         VARCHAR(100)    NULL,
    provider        VARCHAR(64)     NULL,
    model           VARCHAR(128)    NULL,
    created_at      DATETIME(3)     NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    UNIQUE KEY uq_findings_run_fingerprint (run_id, fingerprint),
    KEY idx_findings_repository (repository_id, severity, created_at),
    KEY idx_findings_fingerprint (fingerprint),
    CONSTRAINT fk_findings_run
        FOREIGN KEY (run_id) REFERENCES analysis_runs (id) ON DELETE CASCADE,
    CONSTRAINT fk_findings_repository
        FOREIGN KEY (repository_id) REFERENCES repositories (id) ON DELETE RESTRICT,
    CONSTRAINT chk_findings_fingerprint CHECK (
        CHAR_LENGTH(fingerprint) = 64 AND REGEXP_LIKE(fingerprint, '^[0-9a-f]+$', 'c')
    ),
    CONSTRAINT chk_findings_lines CHECK (
        (line_start IS NULL AND line_end IS NULL)
        OR (line_start >= 1 AND (line_end IS NULL OR line_end >= line_start))
    ),
    CONSTRAINT chk_findings_llm_provenance CHECK (source <> 'llm' OR (provider IS NOT NULL AND model IS NOT NULL)),
    CONSTRAINT chk_findings_rule_provenance CHECK (source <> 'rule' OR rule_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE provider_calls (
    id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT PRIMARY KEY,
    run_id         BIGINT UNSIGNED   NOT NULL,
    phase          VARCHAR(32)       NOT NULL,
    provider       VARCHAR(64)       NOT NULL,
    model          VARCHAR(128)      NOT NULL,
    outcome        ENUM('success', 'timeout', 'rate_limited', 'error', 'rejected') NOT NULL,
    latency_ms     INT UNSIGNED      NOT NULL,
    tokens_in      INT UNSIGNED      NULL,
    tokens_out     INT UNSIGNED      NULL,
    est_cost_usd   DECIMAL(12, 6)    NULL,
    retry_no       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    error          VARCHAR(500)      NULL,
    created_at     DATETIME(3)       NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    KEY idx_provider_calls_run (run_id),
    KEY idx_provider_calls_provider_time (provider, created_at),
    CONSTRAINT fk_provider_calls_run
        FOREIGN KEY (run_id) REFERENCES analysis_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

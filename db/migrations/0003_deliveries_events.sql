-- Webhook deliveries and the normalized events derived from them.
--
-- Only deliveries whose signature verified are stored: unauthenticated
-- requests are logged, never persisted, so they cannot fill this table.
-- Raw payloads are never stored; repository_events keeps the normalized,
-- schema-validated envelope (contracts/github-event.v1.json) without emails.

CREATE TABLE webhook_deliveries (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    github_delivery_id      VARCHAR(64)     NOT NULL,
    event                   VARCHAR(64)     NOT NULL,
    action                  VARCHAR(64)     NULL,
    github_installation_id  BIGINT UNSIGNED NULL,
    github_repo_id          BIGINT UNSIGNED NULL,
    repository_id           BIGINT UNSIGNED NULL,
    status                  ENUM('accepted', 'ignored') NOT NULL,
    ignore_reason           VARCHAR(64)     NULL,
    payload_bytes           INT UNSIGNED    NOT NULL,
    received_at             DATETIME(3)     NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    correlation_id          VARCHAR(128)    NOT NULL,
    -- Replay protection: GitHub signs no timestamp, so the delivery ID is the key.
    UNIQUE KEY uq_deliveries_github_id (github_delivery_id),
    KEY idx_deliveries_received (received_at),
    KEY idx_deliveries_repository (repository_id, received_at),
    CONSTRAINT fk_deliveries_repository
        FOREIGN KEY (repository_id) REFERENCES repositories (id) ON DELETE SET NULL,
    CONSTRAINT chk_deliveries_ignore_reason
        CHECK ((status = 'ignored') = (ignore_reason IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Immutable: rows are inserted once and never updated.
CREATE TABLE repository_events (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    delivery_id     BIGINT UNSIGNED  NOT NULL,
    repository_id   BIGINT UNSIGNED  NOT NULL,
    schema_version  SMALLINT UNSIGNED NOT NULL,
    type            VARCHAR(64)      NOT NULL,
    actor_login     VARCHAR(100)     NULL,
    ref             VARCHAR(255)     NULL,
    head_sha        VARCHAR(64)      NULL,
    base_sha        VARCHAR(64)      NULL,
    pr_number       INT UNSIGNED     NULL,
    occurred_at     DATETIME(3)      NOT NULL,
    envelope        JSON             NOT NULL,
    created_at      DATETIME(3)      NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    UNIQUE KEY uq_events_delivery (delivery_id),
    KEY idx_events_repository_time (repository_id, occurred_at),
    KEY idx_events_pr (repository_id, pr_number),
    CONSTRAINT fk_events_delivery
        FOREIGN KEY (delivery_id) REFERENCES webhook_deliveries (id) ON DELETE CASCADE,
    CONSTRAINT fk_events_repository
        FOREIGN KEY (repository_id) REFERENCES repositories (id) ON DELETE RESTRICT,
    CONSTRAINT chk_events_envelope CHECK (JSON_TYPE(envelope) = 'OBJECT'),
    -- REGEXP_LIKE(..., 'c') is case-sensitive; plain REGEXP follows the
    -- case-insensitive column collation. The length check closes the gap
    -- where '$' also matches before a trailing newline.
    CONSTRAINT chk_events_sha CHECK (
        (head_sha IS NULL OR (CHAR_LENGTH(head_sha) IN (40, 64)
            AND REGEXP_LIKE(head_sha, '^[0-9a-f]+$', 'c')))
        AND (base_sha IS NULL OR (CHAR_LENGTH(base_sha) IN (40, 64)
            AND REGEXP_LIKE(base_sha, '^[0-9a-f]+$', 'c')))
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

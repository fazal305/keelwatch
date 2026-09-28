-- Digests and outbound notifications.
--
-- Destination URLs are stored encrypted (url_ciphertext); only the host is
-- kept in clear so the SSRF allowlist can be audited without decrypting.

CREATE TABLE digests (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    installation_id  BIGINT UNSIGNED NOT NULL,
    repository_id    BIGINT UNSIGNED NULL,
    kind             ENUM('run', 'daily') NOT NULL,
    digest_key       VARCHAR(191)    NOT NULL,
    period_start     DATETIME(3)     NOT NULL,
    period_end       DATETIME(3)     NOT NULL,
    content          JSON            NOT NULL,
    created_at       DATETIME(3)     NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    UNIQUE KEY uq_digests_key (digest_key),
    KEY idx_digests_repository_period (repository_id, period_start),
    KEY idx_digests_installation_period (installation_id, period_start),
    CONSTRAINT fk_digests_installation
        FOREIGN KEY (installation_id) REFERENCES installations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_digests_repository
        FOREIGN KEY (repository_id) REFERENCES repositories (id) ON DELETE RESTRICT,
    CONSTRAINT chk_digests_period CHECK (period_end >= period_start),
    CONSTRAINT chk_digests_content CHECK (JSON_TYPE(content) = 'OBJECT')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE digest_runs (
    digest_id  BIGINT UNSIGNED NOT NULL,
    run_id     BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (digest_id, run_id),
    KEY idx_digest_runs_run (run_id),
    CONSTRAINT fk_digest_runs_digest
        FOREIGN KEY (digest_id) REFERENCES digests (id) ON DELETE CASCADE,
    CONSTRAINT fk_digest_runs_run
        FOREIGN KEY (run_id) REFERENCES analysis_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE notification_destinations (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    installation_id  BIGINT UNSIGNED NOT NULL,
    kind             ENUM('slack', 'discord') NOT NULL,
    label            VARCHAR(100)    NOT NULL,
    url_ciphertext   VARBINARY(2048) NOT NULL,
    url_host         VARCHAR(255)    NOT NULL,
    min_severity     ENUM('critical', 'high', 'medium', 'low', 'info') NOT NULL DEFAULT 'high',
    enabled          TINYINT(1)      NOT NULL DEFAULT 1,
    created_at       DATETIME(3)     NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    updated_at       DATETIME(3)     NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    KEY idx_destinations_installation (installation_id),
    CONSTRAINT fk_destinations_installation
        FOREIGN KEY (installation_id) REFERENCES installations (id) ON DELETE CASCADE,
    -- Defense in depth for the SSRF allowlist enforced in application code.
    CONSTRAINT chk_destinations_host CHECK (
        (kind = 'slack' AND url_host = 'hooks.slack.com')
        OR (kind = 'discord' AND url_host IN ('discord.com', 'discordapp.com'))
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE notification_deliveries (
    id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT PRIMARY KEY,
    destination_id  BIGINT UNSIGNED   NOT NULL,
    digest_id       BIGINT UNSIGNED   NOT NULL,
    attempt         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    status          ENUM('sent', 'failed', 'skipped') NOT NULL,
    http_status     SMALLINT UNSIGNED NULL,
    error           VARCHAR(500)      NULL,
    attempted_at    DATETIME(3)       NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    UNIQUE KEY uq_notification_attempt (destination_id, digest_id, attempt),
    KEY idx_notification_digest (digest_id),
    CONSTRAINT fk_notification_destination
        FOREIGN KEY (destination_id) REFERENCES notification_destinations (id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_digest
        FOREIGN KEY (digest_id) REFERENCES digests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

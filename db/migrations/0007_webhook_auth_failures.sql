-- Counts failed webhook signature checks per client in fixed time windows,
-- so a flood of forged requests is refused before any HMAC work.
-- Successful deliveries never touch this table.
-- client_key is an HMAC of the client IP, not the IP itself.

CREATE TABLE webhook_auth_failures (
    client_key    CHAR(64)     NOT NULL,
    window_start  DATETIME     NOT NULL,
    failures      INT UNSIGNED NOT NULL,
    PRIMARY KEY (client_key, window_start),
    KEY idx_auth_failures_window (window_start),
    CONSTRAINT chk_auth_failures_key CHECK (
        CHAR_LENGTH(client_key) = 64 AND REGEXP_LIKE(client_key, '^[0-9a-f]+$', 'c')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

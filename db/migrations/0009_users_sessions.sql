-- Dashboard accounts, sessions and login throttling.
--
-- Passwords are stored as Argon2id hashes. Sessions are keyed by the SHA-256
-- of a random token; the token itself exists only in the user's cookie, so a
-- database leak does not yield usable sessions.

CREATE TABLE users (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username             VARCHAR(64)     NOT NULL,
    password_hash        VARCHAR(255)    NOT NULL,
    role                 ENUM('admin', 'viewer') NOT NULL DEFAULT 'viewer',
    created_at           DATETIME(3)     NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    updated_at           DATETIME(3)     NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    password_changed_at  DATETIME(3)     NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    last_login_at        DATETIME(3)     NULL,
    disabled_at          DATETIME(3)     NULL,
    UNIQUE KEY uq_users_username (username),
    CONSTRAINT chk_users_username CHECK (REGEXP_LIKE(username, '^[a-z0-9][a-z0-9._-]{2,63}$', 'c')),
    CONSTRAINT chk_users_hash CHECK (password_hash LIKE '$argon2id$%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE sessions (
    token_hash    CHAR(64)        NOT NULL PRIMARY KEY,
    user_id       BIGINT UNSIGNED NOT NULL,
    csrf_token    CHAR(64)        NOT NULL,
    created_at    DATETIME(3)     NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    last_seen_at  DATETIME(3)     NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    expires_at    DATETIME(3)     NOT NULL,
    KEY idx_sessions_user (user_id),
    KEY idx_sessions_expires (expires_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_sessions_tokens CHECK (
        REGEXP_LIKE(token_hash, '^[0-9a-f]{64}$', 'c') AND REGEXP_LIKE(csrf_token, '^[0-9a-f]{64}$', 'c')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Failed logins per bucket (an HMAC of the client IP, or of the username)
-- in fixed windows. Keyed hashes, so neither IPs nor usernames are stored.
CREATE TABLE login_failures (
    bucket        CHAR(64)     NOT NULL,
    window_start  DATETIME     NOT NULL,
    failures      INT UNSIGNED NOT NULL,
    PRIMARY KEY (bucket, window_start),
    KEY idx_login_failures_window (window_start),
    CONSTRAINT chk_login_failures_bucket CHECK (REGEXP_LIKE(bucket, '^[0-9a-f]{64}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- GitHub App installations and the repositories they grant access to.
-- Rows are soft-deleted (status/removed_at) so history stays traceable.
-- All timestamps are UTC; defaults use UTC_TIMESTAMP so they are correct
-- even for a session that forgot to set its time zone.

CREATE TABLE installations (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    github_installation_id  BIGINT UNSIGNED NOT NULL,
    account_login           VARCHAR(100)    NOT NULL,
    account_type            ENUM('User', 'Organization') NOT NULL,
    status                  ENUM('active', 'suspended', 'deleted') NOT NULL DEFAULT 'active',
    created_at              DATETIME(3)     NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    updated_at              DATETIME(3)     NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    suspended_at            DATETIME(3)     NULL,
    deleted_at              DATETIME(3)     NULL,
    UNIQUE KEY uq_installations_github_id (github_installation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE repositories (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    installation_id   BIGINT UNSIGNED NOT NULL,
    github_repo_id    BIGINT UNSIGNED NOT NULL,
    full_name         VARCHAR(200)    NOT NULL,
    is_private        TINYINT(1)      NOT NULL,
    default_branch    VARCHAR(255)    NULL,
    -- ADR 0003: what may be sent to an external LLM. Private repos start at 'none'.
    llm_policy        ENUM('none', 'public_only', 'allowed') NOT NULL DEFAULT 'none',
    analysis_enabled  TINYINT(1)      NOT NULL DEFAULT 1,
    created_at        DATETIME(3)     NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    updated_at        DATETIME(3)     NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    removed_at        DATETIME(3)     NULL,
    UNIQUE KEY uq_repositories_github_id (github_repo_id),
    KEY idx_repositories_installation (installation_id),
    CONSTRAINT fk_repositories_installation
        FOREIGN KEY (installation_id) REFERENCES installations (id) ON DELETE RESTRICT,
    CONSTRAINT chk_repositories_flags
        CHECK (is_private IN (0, 1) AND analysis_enabled IN (0, 1)),
    CONSTRAINT chk_repositories_full_name
        CHECK (REGEXP_LIKE(full_name, '^[A-Za-z0-9-]+/[A-Za-z0-9._-]+$', 'c')
            AND full_name NOT LIKE '%\n%' AND full_name NOT LIKE '%\r%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

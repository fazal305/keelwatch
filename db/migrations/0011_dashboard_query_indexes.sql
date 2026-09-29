-- Indexes for the dashboard's read paths, found by bench/dashboard.php against
-- a seeded 90-day history (25 repositories, 40k events, 20k runs, 60k
-- findings). Each query below was checked with EXPLAIN ANALYZE before and after.

-- "Latest completed run per repository" (readiness, overview, repository
-- detail): MAX(id) per (repository, status) becomes one index probe per
-- repository instead of scanning every run the repository ever had.
ALTER TABLE analysis_runs
    ADD INDEX idx_runs_repository_status (repository_id, status, id),
    -- Time windows across all repositories (analytics, overview counts).
    ADD INDEX idx_runs_created (created_at);

-- "Is this finding new or recurring?" (findings list, analytics, digests):
-- every lookup is (repository_id, fingerprint, earlier run_id), so this
-- replaces the fingerprint-only index, which no query uses on its own.
ALTER TABLE analysis_findings
    ADD INDEX idx_findings_repository_fingerprint (repository_id, fingerprint, run_id),
    DROP INDEX idx_findings_fingerprint,
    -- Time windows across all repositories (analytics, readiness).
    ADD INDEX idx_findings_created (created_at);

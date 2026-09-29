-- Store "new vs recurring" instead of recomputing it on every read.
--
-- Definition (unchanged): a finding is new when no earlier run of the same
-- repository reported the same fingerprint, i.e. its run is the fingerprint's
-- earliest run. Computing that per read cost one index probe per finding in
-- the window: ~450 ms for the analytics 30-day view over 60k findings
-- (bench/dashboard.php). The worker now sets it when findings are stored, and
-- clears it on later runs' copies if an earlier run finishes after them.

ALTER TABLE analysis_findings
    ADD COLUMN is_new TINYINT(1) NOT NULL DEFAULT 1,
    ADD CONSTRAINT chk_findings_is_new CHECK (is_new IN (0, 1)),
    -- Window aggregates read only (created_at, repository_id, is_new).
    DROP INDEX idx_findings_created,
    ADD INDEX idx_findings_created (created_at, repository_id, is_new);

-- Backfill existing rows with the same definition.
UPDATE analysis_findings f
  JOIN (SELECT repository_id, fingerprint, MIN(run_id) AS first_run
          FROM analysis_findings
         GROUP BY repository_id, fingerprint) first
    ON first.repository_id = f.repository_id AND first.fingerprint = f.fingerprint
   SET f.is_new = (f.run_id = first.first_run);

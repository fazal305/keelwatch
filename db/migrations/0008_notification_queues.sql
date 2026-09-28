-- Adds the queues used by digests and notifications:
--   notifications ~ one job per (destination, digest) delivery
--   scheduled     ~ time-based work such as the daily digest
ALTER TABLE jobs DROP CHECK chk_jobs_queue;

ALTER TABLE jobs ADD CONSTRAINT chk_jobs_queue
    CHECK (queue IN ('events', 'analysis', 'notifications', 'scheduled'));

-- Delivery fan-out looks up enabled destinations per installation.
CREATE INDEX idx_destinations_enabled ON notification_destinations (installation_id, enabled);

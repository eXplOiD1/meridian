-- Migrator applies this entire file in one transaction and rolls back on any error.
-- Copy all existing messages before replacing the table; no live data is removed.
ALTER TABLE jobs ADD COLUMN runtime_warn_percent INTEGER CHECK (runtime_warn_percent IS NULL OR runtime_warn_percent BETWEEN 110 AND 1000);
ALTER TABLE runs ADD COLUMN runtime_checked INTEGER NOT NULL DEFAULT 0 CHECK (runtime_checked IN (0, 1));
CREATE INDEX runs_runtime_pending ON runs (job_id, runtime_checked, status, id);

CREATE TABLE notification_outbox_runtime (
    id INTEGER PRIMARY KEY,
    kind TEXT NOT NULL CHECK (kind IN ('failure', 'recovery', 'test', 'disabled', 'tls_expiry', 'monitor_down', 'monitor_up', 'slow')),
    channel_id INTEGER NOT NULL REFERENCES notification_channels (id) ON DELETE CASCADE,
    job_id INTEGER,
    job_name TEXT NOT NULL,
    run_id INTEGER,
    run_status TEXT,
    occurred_at TEXT NOT NULL,
    detail TEXT,
    failures INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'sent', 'failed')),
    attempts INTEGER NOT NULL DEFAULT 0,
    next_attempt_at TEXT NOT NULL,
    last_error TEXT,
    created_at TEXT NOT NULL,
    sent_at TEXT
);
INSERT INTO notification_outbox_runtime (id, kind, channel_id, job_id, job_name, run_id, run_status, occurred_at, detail, failures, status, attempts, next_attempt_at, last_error, created_at, sent_at)
    SELECT id, kind, channel_id, job_id, job_name, run_id, run_status, occurred_at, detail, failures, status, attempts, next_attempt_at, last_error, created_at, sent_at FROM notification_outbox;
DROP TABLE notification_outbox;
ALTER TABLE notification_outbox_runtime RENAME TO notification_outbox;
CREATE INDEX notification_outbox_due ON notification_outbox (status, next_attempt_at);

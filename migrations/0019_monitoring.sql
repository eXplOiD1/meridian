-- Phase 6 (ADR 0010): Überwachung je Job — automatisches Deaktivieren nach Fehlschlägen, TLS-Ablauf-Warnung.
-- Die Ausgangstabelle bekommt die Arten 'disabled' und 'tls_expiry'. Sie hat keine Kinder, ein Neuaufbau ist daher gefahrlos.

ALTER TABLE jobs ADD COLUMN auto_disable_after INTEGER CHECK (auto_disable_after IS NULL OR auto_disable_after BETWEEN 1 AND 1000);
ALTER TABLE jobs ADD COLUMN notify_on_disable INTEGER NOT NULL DEFAULT 0 CHECK (notify_on_disable IN (0, 1));
ALTER TABLE jobs ADD COLUMN tls_warn_days INTEGER CHECK (tls_warn_days IS NULL OR tls_warn_days BETWEEN 1 AND 90);

-- Letztes beim Lauf gesehenes Ablaufdatum des Zertifikats (nur https); notified_for = Ablaufdatum, für das schon gewarnt wurde.
CREATE TABLE job_tls (
    job_id INTEGER PRIMARY KEY REFERENCES jobs (id) ON DELETE CASCADE,
    expires_at TEXT NOT NULL,
    checked_at TEXT NOT NULL,
    notified_for TEXT
);

CREATE TEMP TABLE tmp_outbox AS SELECT * FROM notification_outbox;
DROP TABLE notification_outbox;
CREATE TABLE notification_outbox (
    id INTEGER PRIMARY KEY,
    kind TEXT NOT NULL CHECK (kind IN ('failure', 'recovery', 'test', 'disabled', 'tls_expiry')),
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
INSERT INTO notification_outbox (id, kind, channel_id, job_id, job_name, run_id, run_status, occurred_at, detail, failures, status, attempts, next_attempt_at, last_error, created_at, sent_at)
    SELECT id, kind, channel_id, job_id, job_name, run_id, run_status, occurred_at, detail, failures, status, attempts, next_attempt_at, last_error, created_at, sent_at FROM tmp_outbox;
DROP TABLE tmp_outbox;
CREATE INDEX notification_outbox_due ON notification_outbox (status, next_attempt_at);

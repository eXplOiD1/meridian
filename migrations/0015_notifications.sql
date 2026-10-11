-- Phase 6 (ADR 0007): Benachrichtigungskanäle, Einstellungen je Job, Zusteller-Zustand und Ausgang.

CREATE TABLE notification_channels (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE CHECK (length(name) BETWEEN 1 AND 60),
    type TEXT NOT NULL CHECK (type IN ('email', 'ntfy')),
    config_json TEXT NOT NULL,
    secret_enc TEXT,
    is_enabled INTEGER NOT NULL DEFAULT 1 CHECK (is_enabled IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

ALTER TABLE jobs ADD COLUMN notify_on_failure INTEGER NOT NULL DEFAULT 0 CHECK (notify_on_failure IN (0, 1));
ALTER TABLE jobs ADD COLUMN notify_failure_after INTEGER NOT NULL DEFAULT 1 CHECK (notify_failure_after BETWEEN 1 AND 100);
ALTER TABLE jobs ADD COLUMN notify_on_recovery INTEGER NOT NULL DEFAULT 0 CHECK (notify_on_recovery IN (0, 1));
ALTER TABLE jobs ADD COLUMN notify_channels TEXT NOT NULL DEFAULT 'all' CHECK (notify_channels IN ('all', 'selected', 'none'));

CREATE TABLE job_channels (
    job_id INTEGER NOT NULL REFERENCES jobs (id) ON DELETE CASCADE,
    channel_id INTEGER NOT NULL REFERENCES notification_channels (id) ON DELETE CASCADE,
    PRIMARY KEY (job_id, channel_id)
);

-- Zusteller: bis zu welchem beendeten Lauf ausgewertet wurde, wie viele Fehlschläge in Folge, ob die Serie gemeldet ist.
CREATE TABLE job_notify_state (
    job_id INTEGER PRIMARY KEY REFERENCES jobs (id) ON DELETE CASCADE,
    last_run_id INTEGER NOT NULL DEFAULT 0,
    consecutive_failures INTEGER NOT NULL DEFAULT 0,
    notified INTEGER NOT NULL DEFAULT 0 CHECK (notified IN (0, 1))
);

-- Ausgang: nur Angaben, die ohnehin gespeichert und maskiert sind (Jobname, Status, Zeit, Hinweis).
CREATE TABLE notification_outbox (
    id INTEGER PRIMARY KEY,
    kind TEXT NOT NULL CHECK (kind IN ('failure', 'recovery', 'test')),
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
CREATE INDEX notification_outbox_due ON notification_outbox (status, next_attempt_at);

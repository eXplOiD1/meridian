-- Phase 6 (ADR 0007): weitere Kanalarten (webhook, telegram) und die Einstellung app.public_url (Link in Nachrichten).
-- SQLite ändert kein CHECK: settings und notification_channels werden neu aufgebaut. notification_channels hat Kinder
-- (job_channels, notification_outbox, ON DELETE CASCADE): erst deren Zeilen sichern und die Kinder entfernen, dann die
-- Tabelle ersetzen, dann alles zurückspielen — sonst würde das Entfernen der Elterntabelle die Zeilen mitnehmen.
-- Die Art ist künftig nur noch im Code erlaubt (kein CHECK mehr), damit jede weitere Art keine Migration braucht.

CREATE TABLE settings_new (
    key        TEXT    PRIMARY KEY CHECK (key IN ('http.max_timeout_seconds', 'http.response_storage', 'http.display_path', 'http.display_host', 'shell.max_timeout_seconds', 'jobs.reveal_for_edit', 'app.public_url')),
    value_json TEXT    NOT NULL CHECK (json_valid(value_json)),
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT    NOT NULL
);
INSERT INTO settings_new (key, value_json, updated_by, updated_at) SELECT key, value_json, updated_by, updated_at FROM settings;
DROP TABLE settings;
ALTER TABLE settings_new RENAME TO settings;

CREATE TEMP TABLE tmp_job_channels AS SELECT job_id, channel_id FROM job_channels;
CREATE TEMP TABLE tmp_outbox AS SELECT * FROM notification_outbox;
DROP TABLE job_channels;
DROP TABLE notification_outbox;

CREATE TABLE notification_channels_new (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE CHECK (length(name) BETWEEN 1 AND 60),
    type TEXT NOT NULL CHECK (length(type) BETWEEN 1 AND 20),
    config_json TEXT NOT NULL,
    secret_enc TEXT,
    is_enabled INTEGER NOT NULL DEFAULT 1 CHECK (is_enabled IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
INSERT INTO notification_channels_new (id, name, type, config_json, secret_enc, is_enabled, created_at, updated_at)
    SELECT id, name, type, config_json, secret_enc, is_enabled, created_at, updated_at FROM notification_channels;
DROP TABLE notification_channels;
ALTER TABLE notification_channels_new RENAME TO notification_channels;

CREATE TABLE job_channels (
    job_id INTEGER NOT NULL REFERENCES jobs (id) ON DELETE CASCADE,
    channel_id INTEGER NOT NULL REFERENCES notification_channels (id) ON DELETE CASCADE,
    PRIMARY KEY (job_id, channel_id)
);
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

INSERT INTO job_channels (job_id, channel_id) SELECT job_id, channel_id FROM tmp_job_channels;
INSERT INTO notification_outbox (id, kind, channel_id, job_id, job_name, run_id, run_status, occurred_at, detail, failures, status, attempts, next_attempt_at, last_error, created_at, sent_at)
    SELECT id, kind, channel_id, job_id, job_name, run_id, run_status, occurred_at, detail, failures, status, attempts, next_attempt_at, last_error, created_at, sent_at FROM tmp_outbox;
DROP TABLE tmp_job_channels;
DROP TABLE tmp_outbox;

-- Phase 7 (ADR 0011/0012): Herzschlag-Überwachung (eingehende Pings) und öffentliche Statusseiten.
-- Die Ausgangstabelle bekommt die Arten 'monitor_down' und 'monitor_up' (ohne Kinder, Neuaufbau gefahrlos).

CREATE TABLE monitors (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE CHECK (length(name) BETWEEN 1 AND 80),
    category_id INTEGER REFERENCES categories (id) ON DELETE RESTRICT,
    -- SHA-256 des Ping-Tokens (256 Bit Zufall); der Klartext wird nur beim Anlegen/Erneuern einmal gezeigt.
    token_hash TEXT NOT NULL UNIQUE CHECK (length(token_hash) = 64),
    interval_seconds INTEGER NOT NULL CHECK (interval_seconds BETWEEN 60 AND 2678400),
    grace_seconds INTEGER NOT NULL DEFAULT 300 CHECK (grace_seconds BETWEEN 0 AND 86400),
    is_enabled INTEGER NOT NULL DEFAULT 1 CHECK (is_enabled IN (0, 1)),
    state TEXT NOT NULL DEFAULT 'waiting' CHECK (state IN ('waiting', 'up', 'down')),
    state_since TEXT NOT NULL,
    -- Beginn der Überwachung (Anlegen oder Aktivieren): Basis der ersten Frist, solange noch kein Ping kam.
    armed_at TEXT NOT NULL,
    last_ping_at TEXT,
    notify_on_down INTEGER NOT NULL DEFAULT 1 CHECK (notify_on_down IN (0, 1)),
    notify_on_up INTEGER NOT NULL DEFAULT 1 CHECK (notify_on_up IN (0, 1)),
    notify_channels TEXT NOT NULL DEFAULT 'all' CHECK (notify_channels IN ('all', 'none')),
    recovery_pending INTEGER NOT NULL DEFAULT 0 CHECK (recovery_pending IN (0, 1)),
    created_by INTEGER REFERENCES users (id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE status_pages (
    id INTEGER PRIMARY KEY,
    -- Der unratbare Teil des öffentlichen Links (24 Zeichen aus 120 Bit Zufall).
    slug TEXT NOT NULL UNIQUE CHECK (length(slug) = 24),
    title TEXT NOT NULL CHECK (length(title) BETWEEN 1 AND 80),
    category_id INTEGER REFERENCES categories (id) ON DELETE RESTRICT,
    is_enabled INTEGER NOT NULL DEFAULT 1 CHECK (is_enabled IN (0, 1)),
    created_by INTEGER REFERENCES users (id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE status_page_items (
    id INTEGER PRIMARY KEY,
    page_id INTEGER NOT NULL REFERENCES status_pages (id) ON DELETE CASCADE,
    position INTEGER NOT NULL CHECK (position BETWEEN 0 AND 99),
    -- Öffentlicher Anzeigename, vom Bearbeiter gewählt; nie automatisch der Jobname.
    label TEXT NOT NULL CHECK (length(label) BETWEEN 1 AND 80),
    job_id INTEGER REFERENCES jobs (id) ON DELETE CASCADE,
    monitor_id INTEGER REFERENCES monitors (id) ON DELETE CASCADE,
    CHECK ((job_id IS NULL) <> (monitor_id IS NULL))
);
CREATE INDEX status_page_items_page ON status_page_items (page_id, position);

CREATE TEMP TABLE tmp_outbox AS SELECT * FROM notification_outbox;
DROP TABLE notification_outbox;
CREATE TABLE notification_outbox (
    id INTEGER PRIMARY KEY,
    kind TEXT NOT NULL CHECK (kind IN ('failure', 'recovery', 'test', 'disabled', 'tls_expiry', 'monitor_down', 'monitor_up')),
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

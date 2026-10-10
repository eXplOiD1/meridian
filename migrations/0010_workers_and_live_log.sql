-- Phase 4 (docs/decisions/0004 §3.1): getrennte Worker-Prozesse, Abbruch durch Benutzer, Live-Log.

-- Abbruch: angefordert von einem Benutzer; der Worker sieht es über den Herzschlag.
ALTER TABLE runs ADD COLUMN cancel_requested_at TEXT;
ALTER TABLE runs ADD COLUMN cancel_requested_by INTEGER REFERENCES users(id) ON DELETE SET NULL;
-- Verweis auf einen Prozess außerhalb von Meridian (Docker-Exec: Container, Exec-ID, Prozessgruppe) als JSON,
-- damit ein neu gestarteter Worker übrig gebliebene Prozesse beenden kann. Nie in API-Antworten (wie worker).
ALTER TABLE runs ADD COLUMN exec_ref TEXT CHECK (exec_ref IS NULL OR json_valid(exec_ref));
-- Gelesene Rohbytes der Ausgabe (auch der nicht gespeicherten), für „N B ausgelassen“.
ALTER TABLE runs ADD COLUMN output_bytes INTEGER;
CREATE INDEX runs_exec_ref ON runs (status) WHERE exec_ref IS NOT NULL;

-- Laufende Worker-Prozesse (Kinder). Nur Laufzustand: schreiben dürfen nur Worker selbst (und der Planer räumt auf).
CREATE TABLE workers (
    id         TEXT    PRIMARY KEY CHECK (length(id) BETWEEN 1 AND 200),
    kind       TEXT    NOT NULL CHECK (kind IN ('http', 'shell')),
    host       TEXT    NOT NULL CHECK (length(host) <= 64),
    pid        INTEGER NOT NULL,
    -- Fähigkeiten ohne Geheimnisse, z. B. {"docker":true,"host_profiles":["default"]}
    caps_json  TEXT    NOT NULL DEFAULT '{}' CHECK (json_valid(caps_json)),
    started_at TEXT    NOT NULL,
    seen_at    TEXT    NOT NULL
);
CREATE INDEX workers_kind_seen ON workers (kind, seen_at);

-- Live-Log: maskierte Stücke laufender Läufe. Wird 15 min nach Laufende gelöscht.
CREATE TABLE run_log_chunks (
    id         INTEGER PRIMARY KEY,
    run_id     INTEGER NOT NULL REFERENCES runs(id) ON DELETE CASCADE,
    seq        INTEGER NOT NULL CHECK (seq >= 1),
    stream     TEXT    NOT NULL CHECK (stream IN ('out', 'err', 'sys')),
    -- Bereits maskiert (Masker des Laufs), UTF-8, höchstens 16 KiB je Stück.
    data       TEXT    NOT NULL CHECK (length(CAST(data AS BLOB)) <= 16384),
    created_at TEXT    NOT NULL,
    UNIQUE (run_id, seq)
);

-- Offene SSE-Verbindungen (Obergrenze gegen erschöpfte PHP-Threads).
CREATE TABLE live_streams (
    id         TEXT    PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    run_id     INTEGER NOT NULL REFERENCES runs(id) ON DELETE CASCADE,
    expires_at TEXT    NOT NULL
);
CREATE INDEX live_streams_expires ON live_streams (expires_at);

-- Phase 2: Scheduler-Kern.
--
-- runs: Status 'skipped' (übersprungener Termin, z. B. Überlappung), geplanter Zeitpunkt, Versuch,
-- kurze Notiz und der ausführende Prozess. SQLite kann ein CHECK nicht ändern: Tabelle neu aufbauen,
-- alle Daten übernehmen. Auf runs verweist kein anderer Fremdschlüssel.
CREATE TABLE runs_new (
    id            INTEGER PRIMARY KEY,
    job_id        INTEGER NOT NULL REFERENCES jobs(id) ON DELETE CASCADE,
    trigger       TEXT    NOT NULL CHECK (trigger IN ('schedule', 'manual', 'retry', 'test')),
    status        TEXT    NOT NULL CHECK (status IN ('queued', 'running', 'ok', 'failed', 'timeout', 'aborted', 'skipped')),
    -- Geplanter Termin in UTC; ein wartender Lauf startet nicht vorher (Wiederholung mit Abstand).
    scheduled_for TEXT,
    -- 1 = erster Versuch, jede Wiederholung zählt eins hoch.
    attempt       INTEGER NOT NULL DEFAULT 1 CHECK (attempt >= 1),
    started_at    TEXT,
    finished_at   TEXT,
    duration_ms   INTEGER,
    exit_code     INTEGER,
    http_status   INTEGER,
    -- Ausgabe wird VOR dem Speichern maskiert.
    output        TEXT,
    -- Kurzer Grund (übersprungen, nachgeholt, abgebrochen ...). Nie Payload, immer maskiert.
    note          TEXT,
    -- Besitzer-ID des Scheduler-Prozesses, der den Lauf ausführt (für hängende Läufe).
    worker        TEXT,
    started_by    INTEGER REFERENCES users(id) ON DELETE SET NULL
);

INSERT INTO runs_new (id, job_id, trigger, status, started_at, finished_at, duration_ms, exit_code, http_status, output, started_by)
    SELECT id, job_id, trigger, status, started_at, finished_at, duration_ms, exit_code, http_status, output, started_by FROM runs;

DROP TABLE runs;
ALTER TABLE runs_new RENAME TO runs;

CREATE INDEX runs_job_started ON runs (job_id, started_at DESC);
CREATE INDEX runs_status_scheduled ON runs (status, scheduled_for);
CREATE INDEX runs_job_status ON runs (job_id, status);

-- jobs: verpasste Läufe nachholen (1) oder überspringen (0, Standard); Basisabstand für Wiederholungen.
ALTER TABLE jobs ADD COLUMN catch_up INTEGER NOT NULL DEFAULT 0 CHECK (catch_up IN (0, 1));
ALTER TABLE jobs ADD COLUMN retry_delay_seconds INTEGER NOT NULL DEFAULT 60 CHECK (retry_delay_seconds BETWEEN 1 AND 3600);

-- Sperre gegen einen zweiten Scheduler: genau eine Zeile, abgelaufen angelegt.
CREATE TABLE scheduler_lease (
    id         INTEGER PRIMARY KEY CHECK (id = 1),
    owner      TEXT    NOT NULL,
    expires_at TEXT    NOT NULL
);
INSERT INTO scheduler_lease (id, owner, expires_at) VALUES (1, '', '1970-01-01T00:00:00+00:00');

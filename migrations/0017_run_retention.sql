-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
-- SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
-- Phase 7 (ADR 0008): Aufbewahrung des Verlaufs. Beendete Läufe, die älter sind als `runs.retention_days`, werden zu Tageswerten
-- je Job (UTC) zusammengefasst und gelöscht; Ausgabe und Live-Log gehen mit dem Lauf. Neuer Schlüssel runs.retention_days
-- (SQLite ändert kein CHECK: settings neu aufbauen, alle bisherigen Schlüssel + der neue).

CREATE TABLE settings_new (
    key        TEXT    PRIMARY KEY CHECK (key IN ('http.max_timeout_seconds', 'http.response_storage', 'http.display_path', 'http.display_host', 'shell.max_timeout_seconds', 'jobs.reveal_for_edit', 'app.public_url', 'runs.retention_days')),
    value_json TEXT    NOT NULL CHECK (json_valid(value_json)),
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT    NOT NULL
);
INSERT INTO settings_new (key, value_json, updated_by, updated_at) SELECT key, value_json, updated_by, updated_at FROM settings;
DROP TABLE settings;
ALTER TABLE settings_new RENAME TO settings;

CREATE TABLE run_stats_daily (
    job_id INTEGER NOT NULL REFERENCES jobs (id) ON DELETE CASCADE,
    day TEXT NOT NULL CHECK (day GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'),
    runs INTEGER NOT NULL CHECK (runs >= 0),
    ok INTEGER NOT NULL CHECK (ok >= 0),
    failed INTEGER NOT NULL CHECK (failed >= 0),
    timeout INTEGER NOT NULL CHECK (timeout >= 0),
    aborted INTEGER NOT NULL CHECK (aborted >= 0),
    skipped INTEGER NOT NULL CHECK (skipped >= 0),
    duration_ms_total INTEGER NOT NULL CHECK (duration_ms_total >= 0),
    duration_ms_max INTEGER NOT NULL CHECK (duration_ms_max >= 0),
    PRIMARY KEY (job_id, day)
);
CREATE INDEX runs_finished_at ON runs (finished_at);

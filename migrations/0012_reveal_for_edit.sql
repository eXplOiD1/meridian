-- Gespeicherte Skripte und Links im Editor anzeigen (docs/decisions/0003 Nachtrag N1, 0004 Nachtrag N1).
-- Neuer Schlüssel jobs.reveal_for_edit (off|on, Standard off ohne Zeile). SQLite ändert kein CHECK -> Tabelle neu
-- aufbauen, Zeilen übernehmen. Schlüsselliste = alle bisher erlaubten (0007, 0008, 0011) + jobs.reveal_for_edit.
-- Nur bekannte Schlüssel; fehlt eine Zeile, gilt der Standard aus dem Code. Nie Geheimnisse.
CREATE TABLE settings_new (
    key        TEXT    PRIMARY KEY CHECK (key IN ('http.max_timeout_seconds', 'http.response_storage', 'http.display_path', 'http.display_host', 'shell.max_timeout_seconds', 'jobs.reveal_for_edit')),
    value_json TEXT    NOT NULL CHECK (json_valid(value_json)),
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT    NOT NULL
);
INSERT INTO settings_new (key, value_json, updated_by, updated_at) SELECT key, value_json, updated_by, updated_at FROM settings;
DROP TABLE settings;
ALTER TABLE settings_new RENAME TO settings;
-- Das Rate-Limit des Abrufs (60 je Benutzer und Stunde) zählt Audit-Einträge job.source_viewed über den Index
-- audit_log_user_action aus 0009.

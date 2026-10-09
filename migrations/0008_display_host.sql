-- Neue Einstellung http.display_host (docs/decisions/0003, E10, Entscheidung Alex 09.10.2026).
-- SQLite kann ein CHECK nicht ändern: Tabelle neu anlegen, Zeilen übernehmen, alte ersetzen.
-- Nur bekannte Schlüssel; fehlt eine Zeile, gilt der Standard aus dem Code. Nie Geheimnisse.
CREATE TABLE settings_new (
    key        TEXT    PRIMARY KEY CHECK (key IN ('http.max_timeout_seconds', 'http.response_storage', 'http.display_path', 'http.display_host')),
    value_json TEXT    NOT NULL CHECK (json_valid(value_json)),
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT    NOT NULL
);

INSERT INTO settings_new (key, value_json, updated_by, updated_at)
    SELECT key, value_json, updated_by, updated_at FROM settings;

DROP TABLE settings;

ALTER TABLE settings_new RENAME TO settings;

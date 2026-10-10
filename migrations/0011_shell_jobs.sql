-- Phase 4: Shell-Jobs (docs/decisions/0004 §3.2).
-- Neues gefährliches Recht: Ausführungsorte pflegen. Nur Rolle Admin, ergänzt nur Fehlendes.
INSERT INTO role_permissions (role_id, permission)
    SELECT r.id, 'shell.targets' FROM roles r
     WHERE r.name = 'Admin'
       AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission = 'shell.targets');

-- Ausführungsorte (Allowlist). kind = 'docker': name = Container-Name; kind = 'host': name = Profil.
-- category_id NULL = global; Kategorie gelöscht -> Freigabe gelöscht (CASCADE), nie global.
CREATE TABLE shell_targets (
    id           INTEGER PRIMARY KEY,
    kind         TEXT    NOT NULL CHECK (kind IN ('docker', 'host')),
    name         TEXT    NOT NULL CHECK (length(name) BETWEEN 1 AND 128),
    category_id  INTEGER REFERENCES categories(id) ON DELETE CASCADE,
    -- Erlaubte Benutzer im Container (nur docker), JSON-Liste von Texten; default_user muss darin stehen.
    users_json   TEXT    NOT NULL DEFAULT '[]' CHECK (json_valid(users_json) AND json_type(users_json) = 'array'),
    default_user TEXT    CHECK (default_user IS NULL OR length(default_user) BETWEEN 1 AND 32),
    note         TEXT    NOT NULL DEFAULT '' CHECK (length(note) <= 200),
    created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at   TEXT    NOT NULL,
    CHECK (kind = 'host' OR default_user IS NOT NULL)
);
-- NULL ist in UNIQUE nie gleich: eindeutig über COALESCE.
CREATE UNIQUE INDEX shell_targets_unique ON shell_targets (kind, name, COALESCE(category_id, 0));
CREATE INDEX shell_targets_category ON shell_targets (category_id);

-- settings: neuer Schlüssel shell.max_timeout_seconds. SQLite ändert kein CHECK -> Tabelle neu aufbauen,
-- Zeilen übernehmen. Schlüsselliste = alle bisher erlaubten (0007, 0008) + shell.max_timeout_seconds.
-- Nur bekannte Schlüssel; fehlt eine Zeile, gilt der Standard aus dem Code. Nie Geheimnisse.
CREATE TABLE settings_new (
    key        TEXT    PRIMARY KEY CHECK (key IN ('http.max_timeout_seconds', 'http.response_storage', 'http.display_path', 'http.display_host', 'shell.max_timeout_seconds')),
    value_json TEXT    NOT NULL CHECK (json_valid(value_json)),
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT    NOT NULL
);
INSERT INTO settings_new (key, value_json, updated_by, updated_at) SELECT key, value_json, updated_by, updated_at FROM settings;
DROP TABLE settings;
ALTER TABLE settings_new RENAME TO settings;

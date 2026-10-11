-- Phase 3: HTTP-Jobs (docs/decisions/0003-phase3-http-jobs.md, §3.1).
-- Zwei neue Rechte, beide lockern Schutzfunktionen: nur für die Rolle Admin
-- (neue Rechte nie automatisch an Operator/Beobachter). Ergänzt nur Fehlendes.
INSERT INTO role_permissions (role_id, permission)
    SELECT r.id, p.permission
      FROM roles r
      JOIN (SELECT 'network.internal_targets' AS permission UNION ALL SELECT 'settings.manage') p
     WHERE r.name = 'Admin'
       AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission = p.permission);

-- Globale Einstellungen. Nur bekannte Schlüssel; fehlt eine Zeile, gilt der Standard aus dem Code.
-- Nie Geheimnisse.
CREATE TABLE settings (
    key        TEXT    PRIMARY KEY CHECK (key IN ('http.max_timeout_seconds', 'http.response_storage', 'http.display_path')),
    value_json TEXT    NOT NULL CHECK (json_valid(value_json)),
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT    NOT NULL
);

-- Freigaben interner Ziele für HTTP-Jobs.
--   kind = 'cidr': value ist ein normalisiertes Netz (192.168.1.0/24, fd12:3456::/48)
--   kind = 'host': value ist ein Hostname in Kleinbuchstaben (nextcloud, intranet.firma.local)
--   port = 0 heißt „alle Ports“.
--   category_id NULL = global; sonst nur für Jobs dieser Kategorie. Kategorie gelöscht -> Freigabe gelöscht
--   (CASCADE), nie zu einer globalen erweitert.
CREATE TABLE http_internal_targets (
    id          INTEGER PRIMARY KEY,
    kind        TEXT    NOT NULL CHECK (kind IN ('cidr', 'host')),
    value       TEXT    NOT NULL CHECK (length(value) BETWEEN 1 AND 253),
    port        INTEGER NOT NULL DEFAULT 0 CHECK (port BETWEEN 0 AND 65535),
    category_id INTEGER REFERENCES categories(id) ON DELETE CASCADE,
    note        TEXT    NOT NULL DEFAULT '' CHECK (length(note) <= 200),
    created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at  TEXT    NOT NULL
);
-- NULL ist in UNIQUE nie gleich: eindeutig über COALESCE.
CREATE UNIQUE INDEX http_internal_targets_unique ON http_internal_targets (kind, value, port, COALESCE(category_id, 0));
CREATE INDEX http_internal_targets_category ON http_internal_targets (category_id);

-- Liste nach Kategorie kappen, letzter Lauf je Job.
CREATE INDEX jobs_category ON jobs (category_id);
CREATE INDEX runs_job_id ON runs (job_id, id DESC);

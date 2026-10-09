-- Phase 4a: Benutzer- und Rollenverwaltung (docs/decisions/0005-benutzer-und-rollen.md, §3.1).
-- Ergänzt nur: bestehende Benutzer, Zuweisungen, Sitzungen und Kategorien bleiben unverändert.

-- Benutzer: Soft-Delete, Pflicht-Passwortwechsel, Ablauf des Einmalpassworts.
ALTER TABLE users ADD COLUMN deleted_at TEXT;
ALTER TABLE users ADD COLUMN password_must_change INTEGER NOT NULL DEFAULT 0 CHECK (password_must_change IN (0, 1));
ALTER TABLE users ADD COLUMN password_expires_at TEXT;
ALTER TABLE users ADD COLUMN password_changed_at TEXT;

-- Gelöscht heißt immer inaktiv (beim Ändern und beim Anlegen).
CREATE TRIGGER users_deleted_stays_inactive BEFORE UPDATE OF is_active, deleted_at ON users
    WHEN NEW.deleted_at IS NOT NULL AND NEW.is_active <> 0
BEGIN
    SELECT RAISE(ABORT, 'Ein gelöschter Benutzer bleibt inaktiv.');
END;
CREATE TRIGGER users_deleted_inserted_inactive BEFORE INSERT ON users
    WHEN NEW.deleted_at IS NOT NULL AND NEW.is_active <> 0
BEGIN
    SELECT RAISE(ABORT, 'Ein gelöschter Benutzer bleibt inaktiv.');
END;

-- „Alle Kategorien“ und Einzelkategorien schließen sich aus (grantsFor() liest beides als beschränkt; die
-- Datenbank lässt den Widerspruch gar nicht erst entstehen). Bestehende Widersprüche bleiben beschränkt lesbar.
CREATE TRIGGER user_role_categories_not_with_all BEFORE INSERT ON user_role_categories
    WHEN (SELECT all_categories FROM user_roles WHERE id = NEW.user_role_id) = 1
BEGIN
    SELECT RAISE(ABORT, 'Zuweisung gilt für alle Kategorien: keine Einzelkategorien.');
END;
CREATE TRIGGER user_role_categories_not_moved_to_all BEFORE UPDATE OF user_role_id ON user_role_categories
    WHEN (SELECT all_categories FROM user_roles WHERE id = NEW.user_role_id) = 1
BEGIN
    SELECT RAISE(ABORT, 'Zuweisung gilt für alle Kategorien: keine Einzelkategorien.');
END;
CREATE TRIGGER user_roles_all_without_rows BEFORE UPDATE OF all_categories ON user_roles
    WHEN NEW.all_categories = 1 AND EXISTS (SELECT 1 FROM user_role_categories WHERE user_role_id = NEW.id)
BEGIN
    SELECT RAISE(ABORT, 'Erst die Einzelkategorien entfernen, dann „alle Kategorien“ setzen.');
END;

-- Rollen: Standardrollen kennzeichnen (Vorbereitung für eigene Rollen, E1). Nur Fehlendes ergänzen.
ALTER TABLE roles ADD COLUMN is_builtin INTEGER NOT NULL DEFAULT 0 CHECK (is_builtin IN (0, 1));
UPDATE roles SET is_builtin = 1 WHERE name IN ('Admin', 'Operator', 'Beobachter');

-- Neues gefährliches Recht nur für die Rolle Admin.
INSERT INTO role_permissions (role_id, permission)
    SELECT r.id, 'categories.manage' FROM roles r
     WHERE r.name = 'Admin'
       AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission = 'categories.manage');

-- Kategorienamen eindeutig ohne Rücksicht auf Schreibung (bisher nur im Code geprüft).
-- Bricht ab, wenn es schon Namen gibt, die sich nur in der Schreibung unterscheiden: dann vorher umbenennen
-- (Hinweis in docs/WEITERMACHEN.md beim Deploy).
CREATE UNIQUE INDEX categories_name_nocase ON categories (name COLLATE NOCASE);

-- Sitzungsübersicht (B8). Nur für den Inhaber sichtbar, beim Anlegen gekürzt und ohne Steuerzeichen geschrieben.
ALTER TABLE sessions ADD COLUMN user_agent TEXT CHECK (user_agent IS NULL OR length(user_agent) <= 120);
ALTER TABLE sessions ADD COLUMN client_ip TEXT CHECK (client_ip IS NULL OR length(client_ip) <= 45);

-- Folgen-Vorschau und Grenzen je Handelndem.
CREATE INDEX user_role_categories_category ON user_role_categories (category_id);
CREATE INDEX user_roles_role ON user_roles (role_id);
CREATE INDEX audit_log_user_action ON audit_log (user_id, action, created_at);

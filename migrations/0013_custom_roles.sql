-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
-- SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
-- Phase 5: eigene Rollen (docs/decisions/0006-eigene-rollen.md, §3 und §9.1). Ergänzt nur; bestehende Rollen, Rechte und
-- Zuweisungen bleiben unverändert (einzige Ausnahme: das neue Recht roles.manage für die Admin-Rolle).
-- Ab hier sprechen Code, CLI und jede spätere Migration die Standardrollen nur über roles.builtin_key an, nie über den Namen.

ALTER TABLE roles ADD COLUMN builtin_key TEXT CHECK (builtin_key IS NULL OR builtin_key IN ('admin', 'operator', 'observer'));
ALTER TABLE roles ADD COLUMN description TEXT CHECK (description IS NULL OR (length(description) BETWEEN 1 AND 200));

UPDATE roles SET builtin_key = 'admin'    WHERE is_builtin = 1 AND name = 'Admin';
UPDATE roles SET builtin_key = 'operator' WHERE is_builtin = 1 AND name = 'Operator';
UPDATE roles SET builtin_key = 'observer' WHERE is_builtin = 1 AND name = 'Beobachter';

-- Abbruch (Rollback der ganzen Migration), wenn nicht genau die drei Standardrollen gefunden wurden — etwa weil ein Name
-- von Hand in der Datenbank geändert wurde. Dann den Namen zurücksetzen (Admin, Operator, Beobachter) und neu starten.
CREATE TEMP TABLE migration_0013_check (standardrollen_admin_operator_beobachter_gefunden INTEGER NOT NULL
    CHECK (standardrollen_admin_operator_beobachter_gefunden = 3));
INSERT INTO migration_0013_check SELECT COUNT(*) FROM roles WHERE builtin_key IS NOT NULL;
DROP TABLE migration_0013_check;

CREATE UNIQUE INDEX roles_builtin_key ON roles (builtin_key);
CREATE UNIQUE INDEX roles_name_nocase ON roles (name COLLATE NOCASE);

-- Neues gefährliches Recht nur für die Admin-Rolle (über die Kennung, nie über den Namen).
INSERT INTO role_permissions (role_id, permission)
    SELECT r.id, 'roles.manage' FROM roles r
     WHERE r.builtin_key = 'admin'
       AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission = 'roles.manage');

-- Standardrollen: nie löschen; Name, Beschreibung und Kennung fest (F1, F2); keine neuen per INSERT.
CREATE TRIGGER roles_builtin_not_deleted BEFORE DELETE ON roles
    WHEN OLD.builtin_key IS NOT NULL OR OLD.is_builtin = 1
BEGIN
    SELECT RAISE(ABORT, 'Standardrollen lassen sich nicht löschen.');
END;
CREATE TRIGGER roles_builtin_fixed BEFORE UPDATE OF name, description, builtin_key, is_builtin ON roles
    WHEN (OLD.builtin_key IS NOT NULL OR OLD.is_builtin = 1)
     AND (NEW.name IS NOT OLD.name OR NEW.description IS NOT OLD.description
          OR NEW.builtin_key IS NOT OLD.builtin_key OR NEW.is_builtin IS NOT OLD.is_builtin)
BEGIN
    SELECT RAISE(ABORT, 'Name, Beschreibung und Kennung einer Standardrolle sind fest.');
END;
CREATE TRIGGER roles_custom_not_builtin_insert BEFORE INSERT ON roles
    WHEN NEW.builtin_key IS NOT NULL OR NEW.is_builtin <> 0
BEGIN
    SELECT RAISE(ABORT, 'Neue Rollen sind nie Standardrollen.');
END;
CREATE TRIGGER roles_custom_not_promoted BEFORE UPDATE OF builtin_key, is_builtin ON roles
    WHEN OLD.builtin_key IS NULL AND OLD.is_builtin = 0 AND (NEW.builtin_key IS NOT NULL OR NEW.is_builtin <> 0)
BEGIN
    SELECT RAISE(ABORT, 'Eine eigene Rolle wird nie zur Standardrolle.');
END;

-- Admin behält alle Rechte (neue kommen per Migration dazu, entfernt wird nie).
CREATE TRIGGER role_permissions_admin_kept BEFORE DELETE ON role_permissions
    WHEN (SELECT builtin_key FROM roles WHERE id = OLD.role_id) = 'admin'
BEGIN
    SELECT RAISE(ABORT, 'Die Rolle Admin hat immer alle Rechte.');
END;
CREATE TRIGGER role_permissions_no_update BEFORE UPDATE ON role_permissions
BEGIN
    SELECT RAISE(ABORT, 'Rechte gezielt hinzufügen oder entfernen, nie umschreiben.');
END;

-- Nur bekannte Rechte (Liste ≡ Permission::cases(); neues Recht → diesen Trigger in seiner Migration neu anlegen).
CREATE TRIGGER role_permissions_known BEFORE INSERT ON role_permissions
    WHEN NEW.permission NOT IN ('jobs.view', 'jobs.run', 'jobs.edit_http', 'jobs.edit_shell', 'status_pages.edit',
        'users.manage', 'roles.manage', 'categories.manage', 'network.internal_targets', 'settings.manage', 'shell.targets')
BEGIN
    SELECT RAISE(ABORT, 'Unbekanntes Recht.');
END;

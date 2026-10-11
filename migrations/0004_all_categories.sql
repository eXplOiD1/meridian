-- „Alle Kategorien“ ist eine ausdrückliche Eigenschaft der Zuweisung (Sicherheitsregel).
-- Bisher hieß eine Zuweisung ohne Kategorien „alle“: wurde die letzte Kategorie gelöscht,
-- wurde ein beschränkter Benutzer dadurch uneingeschränkt. Jetzt gilt: ohne Flag und ohne
-- Kategorien darf die Zuweisung nichts.
ALTER TABLE user_roles ADD COLUMN all_categories INTEGER NOT NULL DEFAULT 0 CHECK (all_categories IN (0, 1));

-- Bestehende Zuweisungen ohne Kategorien waren bisher uneingeschränkt und bleiben es.
UPDATE user_roles SET all_categories = 1 WHERE id NOT IN (SELECT user_role_id FROM user_role_categories);

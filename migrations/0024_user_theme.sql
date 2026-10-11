-- ADR 0017: Farbschema je Benutzer (dark = Standard, light).
ALTER TABLE users ADD COLUMN theme TEXT NOT NULL DEFAULT 'dark' CHECK (theme IN ('dark', 'light'));

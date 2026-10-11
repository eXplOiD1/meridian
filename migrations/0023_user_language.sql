-- ADR 0016: Oberflächensprache je Benutzer (de, en).
ALTER TABLE users ADD COLUMN language TEXT NOT NULL DEFAULT 'de' CHECK (language IN ('de', 'en'));

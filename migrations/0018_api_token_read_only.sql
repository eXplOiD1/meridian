-- Phase 8 (ADR 0009): REST-API mit Zugriffstoken. Ein Token handelt als sein Benutzer (Rechte werden bei jeder Anfrage frisch
-- geladen); read_only = 1 erlaubt nur lesende Anfragen. Standard: nur lesen (sicherer Standard). Gespeichert wird nur der Hash.
ALTER TABLE api_tokens ADD COLUMN read_only INTEGER NOT NULL DEFAULT 1 CHECK (read_only IN (0, 1));
CREATE INDEX api_tokens_user ON api_tokens (user_id);

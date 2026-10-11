-- Zwei-Faktor-Anmeldung (TOTP). Das Secret selbst liegt verschlüsselt in users.totp_secret_enc (0001).

-- 0 = nur eingerichtet, noch nicht mit einem Code bestätigt (oder aus); 1 = bei der Anmeldung verlangt.
ALTER TABLE users ADD COLUMN totp_enabled INTEGER NOT NULL DEFAULT 0 CHECK (totp_enabled IN (0, 1));
-- Zuletzt akzeptierter Zeitschritt: derselbe Code wird nie zweimal angenommen (Replay).
ALTER TABLE users ADD COLUMN totp_last_step INTEGER;

-- Wiederherstellungscodes: nur als Hash, jeder genau einmal benutzbar.
CREATE TABLE recovery_codes (
    id        INTEGER PRIMARY KEY,
    user_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash TEXT    NOT NULL,
    used_at   TEXT
);
CREATE INDEX recovery_codes_user ON recovery_codes (user_id);

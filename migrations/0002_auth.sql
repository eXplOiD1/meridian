-- Anmeldung: Sitzungen und Fehlversuche.

-- Die Sitzungs-ID steht nur als Hash in der Datenbank, der Klartext existiert nur im Cookie.
CREATE TABLE sessions (
    id           INTEGER PRIMARY KEY,
    token_hash   TEXT    NOT NULL UNIQUE,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at   TEXT    NOT NULL,
    last_seen_at TEXT    NOT NULL,
    expires_at   TEXT    NOT NULL
);
CREATE INDEX sessions_user ON sessions (user_id);
CREATE INDEX sessions_expires ON sessions (expires_at);

-- Fehlversuche je Benutzername und je Client-IP. Der Bezug (subject) ist ein Hash,
-- damit nie Eingaben von Angreifern (womöglich ein Passwort im Namensfeld) gespeichert werden.
CREATE TABLE login_failures (
    scope           TEXT    NOT NULL CHECK (scope IN ('user', 'ip')),
    subject         TEXT    NOT NULL,
    failures        INTEGER NOT NULL,
    last_failure_at TEXT    NOT NULL,
    locked_until    TEXT,
    PRIMARY KEY (scope, subject)
);

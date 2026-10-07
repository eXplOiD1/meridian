-- Meridian Grundschema (Phase 1)
-- Geheimnisse liegen nur verschlüsselt in Spalten mit dem Suffix _enc.

CREATE TABLE users (
    id              INTEGER PRIMARY KEY,
    username        TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    display_name    TEXT    NOT NULL,
    password_hash   TEXT    NOT NULL,
    totp_secret_enc TEXT,
    is_active       INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT    NOT NULL,
    last_login_at   TEXT
);

CREATE TABLE roles (
    id   INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE
);

CREATE TABLE role_permissions (
    role_id    INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission TEXT    NOT NULL,
    PRIMARY KEY (role_id, permission)
);

CREATE TABLE categories (
    id   INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE
);

-- Zuweisung Benutzer -> Rolle, optional auf Kategorien beschränkt.
CREATE TABLE user_roles (
    id      INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    UNIQUE (user_id, role_id)
);

CREATE TABLE user_role_categories (
    user_role_id INTEGER NOT NULL REFERENCES user_roles(id) ON DELETE CASCADE,
    category_id  INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
    PRIMARY KEY (user_role_id, category_id)
);

CREATE TABLE jobs (
    id              INTEGER PRIMARY KEY,
    name            TEXT    NOT NULL,
    type            TEXT    NOT NULL CHECK (type IN ('http', 'shell')),
    category_id     INTEGER REFERENCES categories(id) ON DELETE SET NULL,
    owner_id        INTEGER REFERENCES users(id) ON DELETE SET NULL,
    cron            TEXT    NOT NULL,
    timezone        TEXT    NOT NULL DEFAULT 'Europe/Berlin',
    -- Nicht geheime Einstellungen (Methode, Zielcontainer, Zeitlimit ...) als JSON.
    config_json     TEXT    NOT NULL DEFAULT '{}',
    -- URL, Header, Body, Skript: alles, was Geheimnisse enthalten kann, verschlüsselt.
    payload_enc     TEXT    NOT NULL,
    overlap_policy  TEXT    NOT NULL DEFAULT 'skip' CHECK (overlap_policy IN ('skip', 'parallel', 'queue')),
    retry_count     INTEGER NOT NULL DEFAULT 0,
    is_enabled      INTEGER NOT NULL DEFAULT 1,
    next_run_at     TEXT,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);
CREATE INDEX jobs_next_run ON jobs (is_enabled, next_run_at);

CREATE TABLE runs (
    id           INTEGER PRIMARY KEY,
    job_id       INTEGER NOT NULL REFERENCES jobs(id) ON DELETE CASCADE,
    trigger      TEXT    NOT NULL CHECK (trigger IN ('schedule', 'manual', 'retry', 'test')),
    status       TEXT    NOT NULL CHECK (status IN ('queued', 'running', 'ok', 'failed', 'timeout', 'aborted')),
    started_at   TEXT,
    finished_at  TEXT,
    duration_ms  INTEGER,
    exit_code    INTEGER,
    http_status  INTEGER,
    -- Ausgabe wird VOR dem Speichern maskiert.
    output       TEXT,
    started_by   INTEGER REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX runs_job_started ON runs (job_id, started_at DESC);

CREATE TABLE api_tokens (
    id          INTEGER PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name        TEXT    NOT NULL,
    token_hash  TEXT    NOT NULL UNIQUE,
    expires_at  TEXT,
    last_used_at TEXT,
    created_at  TEXT    NOT NULL
);

CREATE TABLE audit_log (
    id         INTEGER PRIMARY KEY,
    user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action     TEXT    NOT NULL,
    target     TEXT,
    created_at TEXT    NOT NULL
);

-- Standardrollen
INSERT INTO roles (id, name) VALUES (1, 'Admin'), (2, 'Operator'), (3, 'Beobachter');

INSERT INTO role_permissions (role_id, permission) VALUES
    (1, 'jobs.view'), (1, 'jobs.run'), (1, 'jobs.edit_http'), (1, 'jobs.edit_shell'),
    (1, 'status_pages.edit'), (1, 'users.manage'),
    (2, 'jobs.view'), (2, 'jobs.run'), (2, 'jobs.edit_http'), (2, 'status_pages.edit'),
    (3, 'jobs.view');

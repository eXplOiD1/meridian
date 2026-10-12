-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
-- SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
-- Phase 7 (ADR 0013): feste Intervalle in Sekunden (`@every <n>s`) mit einer vom Admin einstellbaren Untergrenze
-- `jobs.min_interval_seconds` (SQLite ändert kein CHECK: settings neu aufbauen, alle bisherigen Schlüssel + der neue).

CREATE TABLE settings_new (
    key        TEXT    PRIMARY KEY CHECK (key IN ('http.max_timeout_seconds', 'http.response_storage', 'http.display_path', 'http.display_host', 'shell.max_timeout_seconds', 'jobs.reveal_for_edit', 'app.public_url', 'runs.retention_days', 'jobs.min_interval_seconds')),
    value_json TEXT    NOT NULL CHECK (json_valid(value_json)),
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT    NOT NULL
);
INSERT INTO settings_new (key, value_json, updated_by, updated_at) SELECT key, value_json, updated_by, updated_at FROM settings;
DROP TABLE settings;
ALTER TABLE settings_new RENAME TO settings;

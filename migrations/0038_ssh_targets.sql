-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
-- SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
CREATE TABLE shell_targets_new (
 id INTEGER PRIMARY KEY,
 kind TEXT NOT NULL CHECK(kind IN ('docker','host','ssh')),
 name TEXT NOT NULL CHECK(length(name) BETWEEN 1 AND 128),
 category_id INTEGER REFERENCES categories(id) ON DELETE CASCADE,
 users_json TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(users_json) AND json_type(users_json)='array'),
 default_user TEXT CHECK(default_user IS NULL OR length(default_user) BETWEEN 1 AND 32),
 note TEXT NOT NULL DEFAULT '' CHECK(length(note)<=200),
 created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
 created_at TEXT NOT NULL,
 ssh_config_json TEXT CHECK(ssh_config_json IS NULL OR json_valid(ssh_config_json)),
 ssh_private_key_enc TEXT,
 CHECK(kind<>'docker' OR default_user IS NOT NULL),
 CHECK(kind<>'ssh' OR (ssh_config_json IS NOT NULL AND ssh_private_key_enc IS NOT NULL))
);
INSERT INTO shell_targets_new(id,kind,name,category_id,users_json,default_user,note,created_by,created_at)
 SELECT id,kind,name,category_id,users_json,default_user,note,created_by,created_at FROM shell_targets;
DROP TABLE shell_targets;
ALTER TABLE shell_targets_new RENAME TO shell_targets;
CREATE UNIQUE INDEX shell_targets_unique ON shell_targets(kind,name,COALESCE(category_id,0));
CREATE INDEX shell_targets_category ON shell_targets(category_id);

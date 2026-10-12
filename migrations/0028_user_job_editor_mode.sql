-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
-- SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
ALTER TABLE users ADD COLUMN job_editor_mode TEXT NOT NULL DEFAULT 'simple' CHECK (job_editor_mode IN ('simple', 'expert'));

-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
-- SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

ALTER TABLE status_pages ADD COLUMN language TEXT NOT NULL DEFAULT 'de' CHECK (language IN ('de', 'en'));

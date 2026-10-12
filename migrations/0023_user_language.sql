-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
-- SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
-- ADR 0016: Oberflächensprache je Benutzer (de, en).
ALTER TABLE users ADD COLUMN language TEXT NOT NULL DEFAULT 'de' CHECK (language IN ('de', 'en'));

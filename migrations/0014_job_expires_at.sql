-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
-- SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
-- Zeitplan läuft ab: ab diesem Zeitpunkt (UTC) plant der Planer keinen weiteren Lauf; der Job bleibt aktiv gespeichert.
ALTER TABLE jobs ADD COLUMN expires_at TEXT;

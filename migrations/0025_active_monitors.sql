-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
-- SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
-- ADR 0018: aktive Prüfungen (Web-Adresse, TCP-Port, Ping) zusätzlich zum Herzschlag.
-- Neue Spalten statt Neuaufbau (status_page_items verweist auf monitors). Das Ziel steht nur verschlüsselt (`target_enc`),
-- `display_target` ist der Ursprung ohne Pfad und Query. Aktive Prüfungen bekommen ein nie ausgegebenes Zufalls-Token,
-- damit `token_hash` eindeutig bleibt; der Ping-Endpunkt kennt nur `kind = 'heartbeat'`.

ALTER TABLE monitors ADD COLUMN kind TEXT NOT NULL DEFAULT 'heartbeat' CHECK (kind IN ('heartbeat', 'http', 'tcp', 'ping'));
ALTER TABLE monitors ADD COLUMN target_enc TEXT;
ALTER TABLE monitors ADD COLUMN display_target TEXT;
ALTER TABLE monitors ADD COLUMN expected_status TEXT NOT NULL DEFAULT '200-399' CHECK (length(expected_status) BETWEEN 3 AND 120);
ALTER TABLE monitors ADD COLUMN fail_after INTEGER NOT NULL DEFAULT 3 CHECK (fail_after BETWEEN 1 AND 10);
ALTER TABLE monitors ADD COLUMN consecutive_failures INTEGER NOT NULL DEFAULT 0 CHECK (consecutive_failures >= 0);
ALTER TABLE monitors ADD COLUMN last_check_at TEXT;
ALTER TABLE monitors ADD COLUMN last_check_ok INTEGER CHECK (last_check_ok IN (0, 1));
ALTER TABLE monitors ADD COLUMN last_latency_ms INTEGER CHECK (last_latency_ms >= 0);
ALTER TABLE monitors ADD COLUMN last_check_note TEXT CHECK (length(last_check_note) <= 300);
ALTER TABLE monitors ADD COLUMN down_pending INTEGER NOT NULL DEFAULT 0 CHECK (down_pending IN (0, 1));

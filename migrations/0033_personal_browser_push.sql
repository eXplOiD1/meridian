-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
-- SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
ALTER TABLE notification_outbox ADD COLUMN browser_monitor_id INTEGER;
ALTER TABLE notification_outbox ADD COLUMN browser_user_id INTEGER;

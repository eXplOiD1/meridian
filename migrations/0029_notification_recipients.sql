-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
-- SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
ALTER TABLE jobs ADD COLUMN notify_recipients TEXT NOT NULL DEFAULT '[]' CHECK (json_valid(notify_recipients) AND json_type(notify_recipients) = 'array');
ALTER TABLE categories ADD COLUMN notify_recipients TEXT NOT NULL DEFAULT '[]' CHECK (json_valid(notify_recipients) AND json_type(notify_recipients) = 'array');
ALTER TABLE notification_outbox ADD COLUMN recipients TEXT DEFAULT NULL CHECK (recipients IS NULL OR (json_valid(recipients) AND json_type(recipients) = 'array'));
-- NULL inherits all channels; a list selects channels while notify_channels remains all.
ALTER TABLE monitors ADD COLUMN notify_channel_ids TEXT DEFAULT NULL CHECK (notify_channel_ids IS NULL OR (json_valid(notify_channel_ids) AND json_type(notify_channel_ids) = 'array'));

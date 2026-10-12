-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
-- SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
CREATE TABLE monitor_channels (
    monitor_id INTEGER NOT NULL REFERENCES monitors (id) ON DELETE CASCADE,
    channel_id INTEGER NOT NULL REFERENCES notification_channels (id) ON DELETE CASCADE,
    PRIMARY KEY (monitor_id, channel_id)
);
INSERT INTO monitor_channels (monitor_id, channel_id)
SELECT DISTINCT m.id, c.id FROM monitors m, json_each(m.notify_channel_ids) selected
JOIN notification_channels c ON c.id = selected.value
WHERE m.notify_channels = 'all' AND m.notify_channel_ids IS NOT NULL;

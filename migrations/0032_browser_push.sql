-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
-- SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
CREATE TABLE browser_push_subscriptions (
    id INTEGER PRIMARY KEY,
    channel_id INTEGER NOT NULL REFERENCES notification_channels(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    endpoint_hash TEXT NOT NULL,
    subscription_enc TEXT NOT NULL,
    label TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE(channel_id, endpoint_hash)
);
CREATE TABLE browser_push_deliveries (
    subscription_id INTEGER NOT NULL REFERENCES browser_push_subscriptions(id) ON DELETE CASCADE,
    message_hash TEXT NOT NULL,
    delivered_at TEXT NOT NULL,
    PRIMARY KEY(subscription_id, message_hash)
);

-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
-- SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
ALTER TABLE monitors ADD COLUMN max_response_ms INTEGER NOT NULL DEFAULT 5000 CHECK(max_response_ms BETWEEN 100 AND 10000);
CREATE TABLE monitor_events (
    id INTEGER PRIMARY KEY,
    monitor_id INTEGER NOT NULL REFERENCES monitors(id) ON DELETE CASCADE,
    occurred_at TEXT NOT NULL,
    kind TEXT NOT NULL CHECK(kind IN ('check', 'ping', 'state', 'alarm')),
    state TEXT NOT NULL CHECK(state IN ('waiting', 'up', 'down', 'paused')),
    ok INTEGER CHECK(ok IN (0, 1)),
    latency_ms INTEGER,
    note TEXT,
    alerts_queued INTEGER NOT NULL DEFAULT 0,
    alerts_sent INTEGER NOT NULL DEFAULT 0,
    alerts_failed INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX monitor_events_time ON monitor_events(occurred_at, id);
CREATE INDEX monitor_events_monitor_time ON monitor_events(monitor_id, occurred_at);
ALTER TABLE notification_outbox ADD COLUMN monitor_event_id INTEGER REFERENCES monitor_events(id) ON DELETE SET NULL;
CREATE INDEX notification_outbox_monitor_event ON notification_outbox(monitor_event_id);
-- Retained alarms provide real historical evidence, without inventing missing checks.
INSERT INTO monitor_events(monitor_id, occurred_at, kind, state)
SELECT DISTINCT n.browser_monitor_id, n.occurred_at, 'alarm', CASE WHEN n.kind = 'monitor_down' THEN 'down' ELSE 'up' END
FROM notification_outbox n JOIN monitors m ON m.id = n.browser_monitor_id
WHERE n.kind IN ('monitor_down', 'monitor_up') ORDER BY n.occurred_at, n.browser_monitor_id;
UPDATE notification_outbox SET monitor_event_id = (
    SELECT e.id FROM monitor_events e WHERE e.monitor_id = notification_outbox.browser_monitor_id
    AND e.occurred_at = notification_outbox.occurred_at AND e.kind = 'alarm'
    AND e.state = CASE WHEN notification_outbox.kind = 'monitor_down' THEN 'down' ELSE 'up' END LIMIT 1
) WHERE kind IN ('monitor_down', 'monitor_up');
UPDATE monitor_events SET
    alerts_queued = (SELECT COUNT(*) FROM notification_outbox n WHERE n.monitor_event_id = monitor_events.id),
    alerts_sent = (SELECT COUNT(*) FROM notification_outbox n WHERE n.monitor_event_id = monitor_events.id AND n.status = 'sent'),
    alerts_failed = (SELECT COUNT(*) FROM notification_outbox n WHERE n.monitor_event_id = monitor_events.id AND n.status = 'failed');
CREATE TRIGGER monitor_alarm_queued AFTER INSERT ON notification_outbox WHEN NEW.monitor_event_id IS NOT NULL
BEGIN
    UPDATE monitor_events SET alerts_queued = alerts_queued + 1,
        alerts_sent = alerts_sent + (NEW.status = 'sent'), alerts_failed = alerts_failed + (NEW.status = 'failed')
    WHERE id = NEW.monitor_event_id;
END;
CREATE TRIGGER monitor_alarm_result AFTER UPDATE OF status ON notification_outbox WHEN NEW.monitor_event_id IS NOT NULL
BEGIN
    UPDATE monitor_events SET alerts_sent = alerts_sent + (NEW.status = 'sent') - (OLD.status = 'sent'),
        alerts_failed = alerts_failed + (NEW.status = 'failed') - (OLD.status = 'failed') WHERE id = NEW.monitor_event_id;
END;
CREATE TRIGGER monitor_alarm_discarded AFTER DELETE ON notification_outbox WHEN OLD.monitor_event_id IS NOT NULL AND OLD.status = 'pending'
BEGIN
    UPDATE monitor_events SET alerts_failed = alerts_failed + 1 WHERE id = OLD.monitor_event_id;
END;
CREATE TRIGGER monitor_history_check AFTER UPDATE OF last_check_ok ON monitors
WHEN NEW.is_enabled = 1 AND NEW.last_check_ok IS NOT NULL
BEGIN
    INSERT INTO monitor_events(monitor_id, occurred_at, kind, state, ok, latency_ms, note)
    VALUES(NEW.id, NEW.last_check_at, 'check', NEW.state, NEW.last_check_ok, NEW.last_latency_ms, NEW.last_check_note);
END;
CREATE TRIGGER monitor_history_ping AFTER UPDATE OF last_ping_at ON monitors
WHEN NEW.is_enabled = 1 AND NEW.last_ping_at IS NOT NULL AND NEW.last_ping_at IS NOT OLD.last_ping_at
BEGIN
    INSERT INTO monitor_events(monitor_id, occurred_at, kind, state, ok)
    VALUES(NEW.id, NEW.last_ping_at, 'ping', NEW.state, 1);
END;
CREATE TRIGGER monitor_history_state AFTER UPDATE OF state, is_enabled ON monitors
WHEN NEW.state <> OLD.state OR NEW.is_enabled <> OLD.is_enabled
BEGIN
    INSERT INTO monitor_events(monitor_id, occurred_at, kind, state, ok)
    VALUES(NEW.id, CASE WHEN NEW.is_enabled <> OLD.is_enabled THEN NEW.updated_at ELSE NEW.state_since END,
        'state', CASE WHEN NEW.is_enabled = 0 THEN 'paused' ELSE NEW.state END, NULL);
END;

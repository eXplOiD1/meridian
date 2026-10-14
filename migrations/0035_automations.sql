-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
-- SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
CREATE TABLE automations (
    id INTEGER PRIMARY KEY,
    owner_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    enabled INTEGER NOT NULL CHECK(enabled IN (0,1)),
    definition TEXT NOT NULL CHECK(json_valid(definition)),
    next_at TEXT,
    last_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
DROP TRIGGER role_permissions_known;
CREATE TRIGGER role_permissions_known BEFORE INSERT ON role_permissions
    WHEN NEW.permission NOT IN ('jobs.view', 'jobs.run', 'jobs.edit_http', 'jobs.edit_shell', 'status_pages.edit',
        'users.manage', 'roles.manage', 'categories.manage', 'network.internal_targets', 'settings.manage', 'shell.targets', 'automations.manage')
BEGIN
    SELECT RAISE(ABORT, 'Unbekanntes Recht.');
END;
INSERT OR IGNORE INTO role_permissions(role_id,permission) SELECT id,'automations.manage' FROM roles WHERE builtin_key='admin';
ALTER TABLE runs ADD COLUMN automation_id INTEGER;
ALTER TABLE runs ADD COLUMN automation_chain TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(automation_chain));
CREATE TABLE automation_events (
    id INTEGER PRIMARY KEY,
    event TEXT NOT NULL,
    source_id INTEGER NOT NULL,
    run_id INTEGER,
    chain TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(chain)),
    occurred_at TEXT NOT NULL,
    processed_at TEXT
);
CREATE INDEX automation_events_pending ON automation_events(processed_at,id);
CREATE TABLE automation_executions (
    id INTEGER PRIMARY KEY,
    automation_id INTEGER NOT NULL REFERENCES automations(id) ON DELETE CASCADE,
    event_key TEXT NOT NULL,
    occurred_at TEXT NOT NULL,
    status TEXT NOT NULL CHECK(status IN ('executed','skipped','failed')),
    detail TEXT NOT NULL,
    actions TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(actions)),
    UNIQUE(automation_id,event_key)
);
CREATE INDEX automation_executions_rule ON automation_executions(automation_id,id DESC);
CREATE TRIGGER automation_job_event AFTER UPDATE OF status ON runs
WHEN NEW.status IN ('ok','failed','timeout','aborted') AND OLD.status IS NOT NEW.status AND NEW.trigger <> 'test'
AND EXISTS(SELECT 1 FROM automations WHERE enabled=1)
BEGIN
    INSERT INTO automation_events(event,source_id,run_id,chain,occurred_at)
    VALUES('job.' || NEW.status,NEW.job_id,NEW.id,NEW.automation_chain,COALESCE(NEW.finished_at,strftime('%Y-%m-%dT%H:%M:%SZ','now')));
    INSERT INTO automation_events(event,source_id,run_id,chain,occurred_at)
    SELECT 'job.recovery',NEW.job_id,NEW.id,NEW.automation_chain,NEW.finished_at
    WHERE NEW.status='ok' AND (SELECT status FROM runs WHERE job_id=NEW.job_id AND id<>NEW.id AND trigger<>'test' AND status IN ('ok','failed','timeout','aborted') ORDER BY finished_at DESC,id DESC LIMIT 1) IN ('failed','timeout','aborted');
END;
CREATE TRIGGER automation_monitor_event AFTER INSERT ON monitor_events
WHEN NEW.kind='state' AND NEW.state IN ('up','down') AND EXISTS(SELECT 1 FROM automations WHERE enabled=1)
BEGIN
    INSERT INTO automation_events(event,source_id,occurred_at) VALUES('monitor.' || NEW.state,NEW.monitor_id,NEW.occurred_at);
END;
CREATE TABLE notification_outbox_automation (
    id INTEGER PRIMARY KEY,
    kind TEXT NOT NULL CHECK (kind IN ('failure', 'recovery', 'test', 'disabled', 'tls_expiry', 'monitor_down', 'monitor_up', 'slow', 'automation')),
    channel_id INTEGER NOT NULL REFERENCES notification_channels (id) ON DELETE CASCADE,
    job_id INTEGER,
    job_name TEXT NOT NULL,
    run_id INTEGER,
    run_status TEXT,
    occurred_at TEXT NOT NULL,
    detail TEXT,
    failures INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'sent', 'failed')),
    attempts INTEGER NOT NULL DEFAULT 0,
    next_attempt_at TEXT NOT NULL,
    last_error TEXT,
    created_at TEXT NOT NULL,
    sent_at TEXT,
    recipients TEXT,
    browser_monitor_id INTEGER,
    browser_user_id INTEGER,
    monitor_event_id INTEGER REFERENCES monitor_events(id) ON DELETE SET NULL,
    automation_id INTEGER REFERENCES automations(id) ON DELETE CASCADE
);
INSERT INTO notification_outbox_automation(id,kind,channel_id,job_id,job_name,run_id,run_status,occurred_at,detail,failures,status,attempts,next_attempt_at,last_error,created_at,sent_at,recipients,browser_monitor_id,browser_user_id,monitor_event_id) SELECT id,kind,channel_id,job_id,job_name,run_id,run_status,occurred_at,detail,failures,status,attempts,next_attempt_at,last_error,created_at,sent_at,recipients,browser_monitor_id,browser_user_id,monitor_event_id FROM notification_outbox;
DROP TABLE notification_outbox;
ALTER TABLE notification_outbox_automation RENAME TO notification_outbox;
CREATE INDEX notification_outbox_due ON notification_outbox(status,next_attempt_at);
CREATE INDEX notification_outbox_monitor_event ON notification_outbox(monitor_event_id);
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

CREATE INDEX automations_match ON automations(enabled,json_extract(definition,'$.event'),json_extract(definition,'$.source_id'));

CREATE INDEX automation_executions_time ON automation_executions(occurred_at);
CREATE INDEX automation_events_time ON automation_events(occurred_at);

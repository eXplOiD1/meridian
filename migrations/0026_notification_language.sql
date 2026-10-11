ALTER TABLE notification_channels ADD COLUMN language_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL;

UPDATE notification_channels
SET language_user_id = (
    SELECT user_id FROM audit_log
    WHERE action = 'notification.channel_added'
      AND target LIKE ('channel:' || notification_channels.id || ' %')
    ORDER BY id LIMIT 1
);

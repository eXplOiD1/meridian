-- Never reuse a deleted rule's identity: queued runs retain their original automation_id.
CREATE TABLE automation_identifiers (id INTEGER PRIMARY KEY AUTOINCREMENT);
INSERT INTO automation_identifiers(id)
SELECT MAX(value) FROM (
    SELECT MAX(id) AS value FROM automations
    UNION ALL SELECT MAX(automation_id) FROM runs
) HAVING MAX(value)>0;

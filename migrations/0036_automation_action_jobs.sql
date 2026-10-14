-- Inline automation actions reuse validated, encrypted jobs but never receive their own schedule.
ALTER TABLE jobs ADD COLUMN automation_only INTEGER NOT NULL DEFAULT 0 CHECK(automation_only IN (0,1));
CREATE TABLE automation_action_jobs (
    job_id INTEGER PRIMARY KEY REFERENCES jobs(id) ON DELETE CASCADE,
    automation_id INTEGER REFERENCES automations(id) ON DELETE SET NULL
);
CREATE INDEX automation_action_jobs_rule ON automation_action_jobs(automation_id);
CREATE TRIGGER automation_jobs_retire BEFORE DELETE ON automations BEGIN
    UPDATE jobs SET is_enabled=0,next_run_at=NULL WHERE id IN (SELECT job_id FROM automation_action_jobs WHERE automation_id=OLD.id);
END;

ALTER TABLE users ADD COLUMN job_editor_mode TEXT NOT NULL DEFAULT 'simple' CHECK (job_editor_mode IN ('simple', 'expert'));

-- Herzschlag eines laufenden Laufs (UTC). Der Worker schreibt ihn beim Übernehmen und während des Laufs;
-- hängend ist ein Lauf erst, wenn sein Herzschlag älter als die Sperrdauer ist. Ältere laufende Läufe
-- übernehmen ihren Startzeitpunkt.
ALTER TABLE runs ADD COLUMN heartbeat_at TEXT;
UPDATE runs SET heartbeat_at = started_at WHERE status = 'running';

-- Zeitplan läuft ab: ab diesem Zeitpunkt (UTC) plant der Planer keinen weiteren Lauf; der Job bleibt aktiv gespeichert.
ALTER TABLE jobs ADD COLUMN expires_at TEXT;

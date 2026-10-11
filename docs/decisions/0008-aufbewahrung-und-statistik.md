# ADR 0008 – Aufbewahrung des Verlaufs und Statistik (Phase 7)

Status: angenommen (Koordinator, 12.10.2026; Alex wünschte Statistik über Wochen und Monate und hatte bis dahin keine Aufbewahrung).

## Entscheidungen

- **A1 Aufbewahrung:** Einzelne beendete Läufe bleiben `runs.retention_days` Tage (Standard 30, 1–3650, Einstellung mit `settings.manage`). Ältere werden zu Tageswerten je Job und UTC-Tag (`run_stats_daily`) zusammengefasst und gelöscht; Ausgabe und Live-Log gehen per ON DELETE CASCADE mit. Ohne diese Regel wüchse `runs` unbegrenzt (eine Minute-Takt-Job: ~525 000 Zeilen im Jahr).
- **A2 Zusammenfassen und Löschen atomar:** je Stapel (1000 Läufe) in einer `BEGIN IMMEDIATE`-Transaktion: erst `INSERT … ON CONFLICT DO UPDATE` der Summen, dann `DELETE`. Ein Lauf ist nie zugleich gelöscht und ungezählt oder doppelt gezählt. Höchstens 20 Stapel je Durchgang.
- **A3 Wer:** der Planer-Prozess unter der Scheduler-Sperre, höchstens alle 10 Minuten; ein Fehler hält den Takt nie an (Meldung wird nicht ausgegeben). Wartende und laufende Läufe werden nie angefasst. Testläufe werden gelöscht, zählen aber nie in Tageswerte oder Statistik.
- **A4 Statistik:** `GET /api/stats?days=1..366[&job_id=]` summiert frische Läufe aus `runs` und Tageswerte aus `run_stats_daily` (lückenlose UTC-Tage, je Job: Läufe, Fehler, Erfolgsquote, mittlere und längste Dauer, höchstens 50 Jobs). Gezählt werden beendete Läufe (ok, fehlgeschlagen, Zeitlimit, abgebrochen), nie Übersprungenes.
- **A5 Sichtbarkeit:** dieselbe Kappung auf erlaubte Kategorien wie Liste und Verlauf (`jobs.view`, leerer Bereich → 403); ein `job_id` außerhalb des Bereichs liefert leere Zahlen, nie einen Hinweis auf die Existenz. Tageswerte enthalten nur Zahlen, nie Inhalt, Namen kommen maskiert aus `jobs`.
- **A6 Folge für die Oberfläche:** Läufe älter als die Aufbewahrung verschwinden aus „Verlauf“ und „Details“; die Statistik zeigt es an („Einzelne Läufe werden n Tage aufbewahrt“). Eine Verkürzung der Zeit löscht beim nächsten Durchgang und ist nicht rückgängig zu machen (Warnung an der Einstellung).

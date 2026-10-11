# ADR 0013: Feste Intervalle in Sekunden

Status: umgesetzt (Phase 7)

Wunsch von Alex: ein Zeitplan „alle n Sekunden“. Die Cron-Bibliothek kennt keine Sekunden.

## Entscheidungen

1. **Schreibweise `@every <n>s`** (n von 1 bis 86400) in der bestehenden Spalte `jobs.cron`; keine Schemaänderung am Job. `CronSchedule` erkennt sie und rechnet selbst: der nächste Termin ist das nächste Vielfache von n seit der Unix-Zeit (10 s → :00, :10, :20 …), strikt nach dem Bezugszeitpunkt. Zeitzone und Sommerzeit spielen keine Rolle (echte Zeit). Alle Aufrufer (Planer, Vorschau, Übersicht, Validierung) gehen durch `CronSchedule::forJob()` und bleiben unverändert.
2. **Untergrenze als globale Einstellung `jobs.min_interval_seconds`** (1 bis 3600, Standard 10; Migration 0021, `settings.manage`, Audit wie jede Einstellung). Sie gilt **beim Speichern**: ein schon gespeicherter Job mit kleinerem Abstand bleibt bearbeitbar, solange sein Zeitplan unverändert ist. Der Editor liest sie über `GET /api/jobs/limits`.
3. **Takt des Planers:** solange es mindestens einen aktiven Job mit festem Intervall gibt, taktet der Planer jede Sekunde (`Planner::hasIntervalJobs()`, `Scheduler::idleSeconds()`), sonst alle 5 Sekunden. Wer die Sperre nicht hält, wartet immer 5 s. Pro Takt und Job entsteht höchstens ein Lauf; nach einem Stillstand holt der Planer (wie bei Cron) nur den letzten pünktlichen Termin nach, nie hunderte.
4. **Last und Aufbewahrung:** Bei 10 s sind das über 8.000 Läufe am Tag und Job. Das trägt die Aufbewahrung (ADR 0008: nach `runs.retention_days` zu Tageswerten verdichtet). Dauert ein Lauf länger als der Abstand, entscheidet „Überlappung“; bei „überspringen“ entstehen Einträge „übersprungen“ (sie zählen nicht als Fehlschlag). Wer das nicht will, wählt „parallel“/„Warteschlange“ oder einen größeren Abstand.
5. **Übersicht (Taktband):** Die Abschnitte sind 5 Minuten breit; ein Sekunden-Job füllt sie, wird aber nicht feiner dargestellt. Die Vorschau im Editor zeigt Sekunden.
6. **Oberfläche:** Baukasten-Modus „Alle n Sekunden“ (beginnt beim erlaubten Minimum), das Cron-Textfeld akzeptiert `@every 30s`, Fehler des Zeitplans stehen jetzt am Feld (vorher wurden sie nicht angezeigt).

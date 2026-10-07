---
name: mer-scheduler
description: Scheduler-Kern von Meridian — Cron-Ausdrücke über dragonmantank/cron-expression und CronSchedule, Zeitzone pro Job, Speicherung in UTC, Sommerzeit (übersprungene und doppelte Stunde, Europe/Berlin März/Oktober), next_run_at fortschreiben, verpasste Läufe nachholen oder überspringen, Überlappung skip/parallel/queue, Wiederholen mit wachsendem Abstand, Sperre gegen zwei Scheduler, atomares Übernehmen von Läufen, hängende Läufe, SIGTERM, Testlauf, Vorschau der nächsten Läufe, Aufbewahrung. Nutze das bei jeder Arbeit an src/Schedule, scheduler:run, Warteschlange, Worker-Prozess und Zeitberechnungen.
---

# Meridian-Scheduler

Normativ für Planung und Warteschlange. Datenregeln in `mer-storage`, Ausführung in `mer-runner`.

## 1. Zeit

- Gespeichert wird in UTC; ausgewertet wird der Cron-Ausdruck in der Zeitzone des Jobs
  (`CronSchedule` mit `\DateTimeZone`). Zeitzone gegen `\DateTimeZone::listIdentifiers()` prüfen.
- Die Zeitquelle ist injizierbar; kein `new \DateTimeImmutable('now')` tief im Kern. Tests laufen mit
  festen Zeitpunkten.
- Sommerzeit: Ein Lauf in der übersprungenen Stunde findet genau **einmal** statt, in der doppelten
  Stunde **nicht zweimal**. Pflicht-Tests mit Europe/Berlin am letzten Sonntag im März und Oktober.
- `next_run_at` wird aus dem Zeitplan berechnet (nächster Termin nach dem geplanten, nicht nach „jetzt
  plus Intervall“), damit nichts driftet.

## 2. Genau ein Scheduler

- Sperre in der Datenbank als Lease: Besitzer-ID, Ablaufzeit, regelmäßig verlängert. Übernahme nur über
  bedingtes `UPDATE … WHERE abgelaufen OR besitzer = :ich` mit `rowCount() === 1`.
- Ohne gültige Sperre plant der Prozess nichts.

## 3. Läufe anlegen und übernehmen

- Fälligen Lauf anlegen und `next_run_at` fortschreiben in **einer** Transaktion.
- Worker übernimmt atomar: `UPDATE runs SET status = 'running' … WHERE id = :id AND status = 'queued'`,
  nur bei `rowCount() === 1` ausführen.
- Hängende Läufe (`running` ohne lebenden Worker, z. B. nach Neustart) werden als `aborted` markiert,
  nie stillschweigend erneut gestartet.
- Der Scheduler schreibt nur Laufzustand, nie Job-Einstellungen.

## 4. Überlappung, Wiederholen, verpasste Läufe

- Überlappung pro Job: `skip` (neuer Lauf entfällt, vermerkt), `parallel`, `queue` (höchstens ein
  wartender Lauf).
- Wiederholen: nur bei Fehler, wachsender Abstand mit Obergrenze, höchstens `retry_count` Mal, Trigger
  `retry`.
- Verpasste Läufe nach Stillstand: pro Job „nachholen“ oder „überspringen“; **nie mehr als ein**
  Nachholen pro Job, egal wie viele Termine verpasst wurden.
- Deaktivierter oder gelöschter Job startet nie, auch nicht aus der Warteschlange.

## 5. Prozess

- `SIGTERM`/`SIGINT`: keine neuen Läufe annehmen, laufende Transaktion abschließen, sauber beenden.
  Keine halben Datensätze.
- Ausgaben des Prozesses (Logs) enthalten Job-ID und Status, nie Payload; Texte durch den Masker.
- Manueller Lauf und Testlauf prüfen `jobs.run` für die Kategorie des Jobs. Testläufe zählen nicht in
  die Statistik.
- Vorschau der nächsten Läufe: Anzahl serverseitig begrenzen.

## Verboten

- ❌ Lokale Zeit in der Datenbank, „jetzt“ fest im Kern
- ❌ Zwei Scheduler ohne Sperre, Lauf übernehmen ohne bedingtes `UPDATE`
- ❌ Mehr als ein Nachholen pro Job; hängende Läufe automatisch neu starten
- ❌ Scheduler ändert Job-Einstellungen
- ❌ Unbegrenzte Vorschau oder Warteschlange

## Checkliste

| Prüfpunkt | ✓ |
|---|---|
| UTC gespeichert, Job-Zeitzone ausgewertet, Zeitquelle injiziert? | |
| Sommerzeit-Tests März und Oktober (Europe/Berlin) grün? | |
| Lease-Sperre, atomare Übernahme, Anlegen + Fortschreiben in einer Transaktion? | |
| Überlappung, Wiederholen, Nachholen (max. einmal) getestet? | |
| SIGTERM ohne halbe Datensätze, hängende Läufe auf `aborted`? | |
| Rechteprüfung für manuellen Lauf/Testlauf? | |
| `composer check` grün? | |

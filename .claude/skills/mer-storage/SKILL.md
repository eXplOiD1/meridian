---
name: mer-storage
description: Datenhaltung von Meridian — SQLite über Meridian\Database\Connection (execute, fetchAll, fetchOne, transaction), SQL nur als Literal mit Parametern, dynamische Bezeichner nur über Allowlist, Migrationen in migrations/*.sql und Migrator, executeMigrationScript, Spalten mit Suffix _enc für Geheimnisse, config_json ohne Geheimnisse, Zeitstempel in UTC, Transaktionen, Standardrollen und Grunddaten nur einmal seeden, Hintergrundprozesse schreiben keine Benutzereinstellungen, Dateirechte der Datenbank, WAL, Backup und Wiederherstellung, Aufbewahrung des Verlaufs, Roundtrip-Test. Nutze das bei jeder Arbeit an migrations/, src/Database, Repositories, neuen Tabellen oder Spalten, Speicherpfaden, Backup/Restore, und wenn Daten verschwinden, doppelt auftauchen oder nach dem Speichern anders aussehen.
---

# Meridian-Datenhaltung

Normativ für alles, was in die SQLite-Datenbank geschrieben oder daraus gelesen wird. Regel 2 und 3
in `CLAUDE.md` gelten zusätzlich.

## 1. SQL nur als Literal

```php
// richtig — Literal, Werte als benannte Parameter
$db->fetchOne('SELECT id, name FROM jobs WHERE id = :id', ['id' => $id]);

// richtig — dynamische Sortierung über eine feste Zuordnung auf Literale
$rows = match ($sort) {
    'name'    => $db->fetchAll('SELECT … FROM jobs ORDER BY name'),
    'nextRun' => $db->fetchAll('SELECT … FROM jobs ORDER BY next_run_at'),
    default   => throw new \InvalidArgumentException('Unbekannte Sortierung. Erlaubt: name, nextRun.'),
};

// falsch — Wert im SQL-String
$db->fetchAll("SELECT … FROM jobs WHERE name = '$name'");
$db->fetchAll('SELECT … FROM jobs ORDER BY ' . $sort);
```

- Nur `Connection::execute/fetchAll/fetchOne`; der Parameter ist `literal-string`. Kein `PDO` außerhalb
  von `src/Database/Connection.php`.
- `IN (…)` mit variabler Anzahl: JSON-Parameter mit `json_each(:ids)` statt Platzhalter zusammenzubauen.
- `executeMigrationScript()` ausschließlich aus `Migrator` mit Dateien aus `migrations/`.

## 2. Migrationen

- Neue Datei `migrations/NNNN_beschreibung.sql`, fortlaufend. Eine eingespielte Migration wird **nie**
  geändert — Korrekturen sind eine neue Migration.
- Jede Migration läuft in einer Transaktion (`Migrator`). `PRAGMA`-Anweisungen gehören nicht hinein.
- Spalten mit Geheimnissen enden auf `_enc` und enthalten nur `SecretBox`-Werte (`v1:…`). Neue
  Geheimnis-Spalte → im selben Schritt Leak-Test, der die Rohspalte auf Klartext prüft.
- Eine Spalte, die eine Prüfung erzwingen kann, bekommt ein `CHECK` (Status, Typ, Richtlinie).
- Fremdschlüssel bewusst wählen: `ON DELETE CASCADE` nur, wenn das Löschen der Kinder gewollt ist und
  **keine Rechte erweitert**. Eine gelöschte Kategorie darf eine Beschränkung nie in „alles“ verwandeln.

## 3. Grunddaten nur einmal

- Standardrollen und -rechte kommen aus einer Migration, also genau einmal. Kein Seeden beim Start.
- Was der Admin gelöscht oder geändert hat, bleibt so. Spätere Migrationen ergänzen nur Fehlendes
  (`INSERT … WHERE NOT EXISTS`), überschreiben nie.
- Neues Recht: per Migration nur der Rolle Admin geben; Operator/Beobachter bekommen es nie automatisch.
- „Ist eingerichtet?“ hängt nie an `COUNT(*) FROM users`. Leere Benutzertabelle = beschädigt, nicht neu.

## 4. Schreiben

- Mehrere zusammengehörige Schreibvorgänge in `Connection::transaction()`. Lesen-Ändern-Schreiben
  ebenfalls in einer Transaktion; einen Lesefehler nie als „leer“ behandeln und zurückschreiben.
- Atomare Zustandswechsel über bedingtes `UPDATE … WHERE status = :alt` und `rowCount() === 1`.
- Hintergrundprozesse (Scheduler, Runner, Benachrichtigungen) schreiben nur Laufzustand (`runs`,
  `next_run_at`, Sperren, Statistik) — **nie** Felder, die der Benutzer im Job-Editor pflegt.
- Ein Datensatz wird gezielt geschrieben, nie eine ganze Tabelle gelöscht und neu befüllt.

## 5. Zeit und Werte

- Zeitstempel in UTC, ein einziges Textformat für die ganze Datenbank, erzeugt an genau einer Stelle.
  Vergleiche in SQL (`next_run_at <= :now`) setzen dasselbe Format auf beiden Seiten voraus.
- Werte aus der DB typprüfen (`is_int`, `is_string`) — PHPStan max verlangt es, und `mixed` darf nicht
  ungeprüft weiterlaufen.

## 6. Datei, WAL, Rechte

- Pfad nur über `Config::databasePath()`. Datenverzeichnis `0750`, Datenbankdatei samt `-wal`/`-shm`
  nur für den Dienstbenutzer lesbar (`umask 077` bzw. `UMask=0077`).
- Die Datenbank liegt nie unter `public/` und nie im Repository (`*.sqlite*` in `.gitignore`).

## 7. Backup und Wiederherstellung

- Nie `copy()`/`cp` auf die laufende WAL-Datenbank. Sichern mit `VACUUM INTO` (oder SQLite-Backup-API)
  in eine `.tmp`-Datei, prüfen (`PRAGMA quick_check`, eine Tabelle lesbar), erst dann umbenennen.
- Freien Platz vorher prüfen. Fehlgeschlagene Sicherung meldet Fehler, nie Erfolg.
- Der Hauptschlüssel ist **nie** Teil der Sicherung; die Sicherung trägt nur seinen Fingerabdruck.
  Wiederherstellung vergleicht den Fingerabdruck **vor** dem ersten Schreiben.
- Wiederherstellung nimmt einen Dateinamen aus dem Sicherungsverzeichnis, nie einen Pfad. Danach
  `-wal`/`-shm` des ersetzten Standes entfernen.
- Verlauf (`runs.output`) unterliegt der Aufbewahrung (verdichten, nach Frist löschen) — per eigener,
  dokumentierter Regel, nie nebenbei.

## 8. Roundtrip-Test

„Speichern gab 200 zurück“ beweist nichts. Test: schreiben → über eine **neue** `Connection` lesen →
Wert vergleichen. Für `_enc`-Spalten zusätzlich: Rohwert enthält das Test-Geheimnis nicht, beginnt mit
`v1:`, und `SecretBox::decrypt()` liefert den Originalwert.

## Verboten

- ❌ Werte oder Bezeichner per Verkettung/Interpolation in SQL
- ❌ `PDO` oder `->exec()` außerhalb von `Connection`
- ❌ Eingespielte Migration ändern
- ❌ Geheimnis in einer Spalte ohne `_enc` oder in `config_json`
- ❌ Grunddaten beim Start nachseeden oder Admin-Änderungen per Migration überschreiben
- ❌ Hintergrundprozess schreibt Job-Einstellungen zurück
- ❌ Sicherung per Dateikopie der laufenden DB; Schlüssel in der Sicherung
- ❌ Gemischte Zeitformate in derselben Spalte

## Checkliste

| Prüfpunkt | ✓ |
|---|---|
| SQL als Literal, alle Werte als Parameter, Bezeichner über `match` auf Literale? | |
| Neue Migration statt Änderung einer alten, `CHECK`/Fremdschlüssel bewusst gesetzt? | |
| Geheimnis-Spalten auf `_enc`, Rohwert-Test ohne Klartext? | |
| Kaskaden erweitern keine Rechte? | |
| Mehrere Schreibvorgänge in einer Transaktion, Zustandswechsel atomar? | |
| Hintergrundprozess schreibt nur Laufzustand? | |
| Zeitstempel UTC im einheitlichen Format? | |
| Roundtrip-Test über neue Verbindung? | |
| `composer check` grün? | |

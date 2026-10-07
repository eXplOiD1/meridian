# Meridian

Selbst gehosteter Job-Scheduler: HTTP-Jobs (wie cron-job.org) und Shell-Jobs (wie Cronicle) mit Benutzern, Rollen und Rechten pro Kategorie. LÃ¤uft als Linux-Installation mit systemd oder als Docker-Container, **nicht** auf Webspace.

EigenstÃ¤ndiges Projekt ohne AbhÃ¤ngigkeit zu anderen Frameworks.

## Ordnerstruktur

Das Repository trennt **Produkt** und **Werkzeuge**:

| Ort | Inhalt |
|---|---|
| `Meridian/` | Das Produkt: `src/`, `bin/`, `public/`, `migrations/`, `frontend/`, `docker/`, `deploy/`, `compose.yaml`, `docs/`, `TODO.md` |
| Repo-Root | Werkzeuge: `.claude/`, `.github/`, `tests/`, `phpstan.neon`, `psalm.xml`, `phpunit.xml`, `composer.json` (+ `vendor/`), `.gitleaks.toml`, `.pre-commit-config.yaml`, diese Datei |

**Pfade in dieser Datei, in den Skills und in `TODO.md` (z. B. `src/Security`, `migrations/`, `docs/PLAN.md`) sind relativ zu `Meridian/`**, auÃŸer `.claude/`, `tests/` und die Konfigurationsdateien. Der Hook rechnet das PrÃ¤fix selbst heraus.

## Dokumente

- `Meridian/docs/PLAN.md` â€“ Funktionsumfang, Architektur, Phasen, Aufwand, Risiken
- `Meridian/TODO.md` â€“ Aufgaben je Phase, jede mit dem zustÃ¤ndigen Subagenten
- Klickdummy der OberflÃ¤che: Artifact â€žTakt â€“ Klickdummyâ€œ in Alex' claude.ai-Konto (Arbeitstitel war â€žTaktâ€œ). Farben, Schriften und Begriffe von dort Ã¼bernehmen.

## Aufbau in drei Ebenen

| Ebene | Ort | Rolle |
|---|---|---|
| **Kern** | diese Datei | Regeln, die Ã¼berall gelten, plus Routing. Wird immer geladen. |
| **Skills** | `.claude/skills/<name>/SKILL.md` | Normativ fÃ¼r ihren Bereich: Pflicht-Pattern, Verboten, Checkliste |
| **Archiv** | `docs/decisions/`, `docs/PLAN.md`, Git-Historie | Herleitung, verworfene Alternativen, Fundstellen |

Ein **PreToolUse-Hook** (`.claude/hooks/rule-router.js`) nennt bei jeder Bearbeitung den zustÃ¤ndigen Skill.
Ein **PostToolUse-Hook** prÃ¼ft `php -l`, PHPStan (falls installiert) und harte VerstÃ¶ÃŸe (Shell-Aufrufe,
fehlendes `strict_types`, `@phpstan-ignore`, SQL mit Variablen, TLS aus, CSP-VerstÃ¶ÃŸe). Steuerung:
`/hook-status`, `/hook-off`, `/hook-on`, `/hook-debug`. Bei Widerspruch gilt der Kern.

### Routing â€” welcher Skill fÃ¼r was

| Skill | ZustÃ¤ndig fÃ¼r |
|---|---|
| `mer-security` | Anmeldung, Sitzung, CSRF, Rechte/`AccessControl`, Kategorie-Scope/IDOR, XSS, SSRF, Uploads/Pfade, Header, Rate-Limit/Sperre, Geheimnisse/`SecretBox`/`SecretMasker`, Argon2id, API-Tokens, Audit-Log, Review |
| `mer-storage` | SQLite/`Connection`, Migrationen, `_enc`-Spalten, Transaktionen, Grunddaten einmal seeden, WAL, Backup/Wiederherstellung, Roundtrip-Test |
| `mer-runner` | Shell-Runner (`proc_open`), Docker-Socket-Proxy, HTTP-Runner, SSRF, Maskierung von Laufausgabe und Live-Log, Benachrichtigungen |
| `mer-scheduler` | Cron, Zeitzonen, Sommerzeit, Ãœberlappung, Sperre, verpasste LÃ¤ufe, Wiederholen |
| `mer-ui` | React/Vite, Klickdummy, CSP, kein Inline-JS, selbst gehostete Schriften |

Bei Unsicherheit den Skill laden, nicht raten.

## Tech-Stack

- PHP 8.4 (lÃ¤uft lokal ab 8.3), `declare(strict_types=1);` in **jeder** Datei
- Symfony-Komponenten: Routing, HttpFoundation, Console. Kein Full-Stack-Framework.
- FrankenPHP als Webserver, Scheduler und Runner als dauerhafte CLI-Prozesse (`bin/meridian scheduler:run`)
- SQLite Ã¼ber `Meridian\Database\Connection`, Migrationen in `migrations/*.sql`
- dragonmantank/cron-expression fÃ¼r ZeitplÃ¤ne
- OberflÃ¤che: React + Vite unter `frontend/` (ab Phase 5), Build landet in `public/app/`

## Befehle

```bash
composer install
composer check      # PHPStan max + Psalm + Taint-Analyse + PHPUnit + composer audit
composer test       # nur Tests
php tests/smoke.php # Sicherheits-Kern ohne Composer-AbhÃ¤ngigkeiten
```

Eine Aufgabe ist erst fertig, wenn `composer check` grÃ¼n ist.

## Sicherheitsregeln (nicht verhandelbar)

Ziel: **keine Leaks** von Geheimnissen, PasswÃ¶rtern oder Daten.

1. **PHPStan Level max, keine Baseline, keine `@phpstan-ignore`** ohne BegrÃ¼ndung im selben Kommentar und Zustimmung von Alex.
2. **SQL nur als Literal** in `Connection::execute/fetchAll/fetchOne`, Werte nur als Parameter. Nie Werte in SQL-Strings einbauen. `executeMigrationScript` nur fÃ¼r Dateien aus `migrations/`.
3. **Geheimnisse** (URLs mit Parametern, Header, Bodies, Skripte, TOTP-Secrets) nur Ã¼ber `SecretBox` verschlÃ¼sselt speichern, in Spalten mit Suffix `_enc`. Nie im Klartext in DB, Logs, Exceptions oder Antworten.
4. **Alles, was das System verlÃ¤sst**, lÃ¤uft durch `SecretMasker::mask()`: Lauf-Ausgabe vor dem Speichern, Logs, Benachrichtigungen, API-Antworten, Fehlermeldungen. FÃ¼r jede neue Ausgabestelle einen Test, der ein bekanntes Test-Geheimnis einspeist und prÃ¼ft, dass es nicht erscheint.
5. **Parameter mit Geheimnissen** bekommen `#[\SensitiveParameter]`, damit sie nicht in Stacktraces landen.
6. **Rechte** vor jedem Lesen oder Ã„ndern Ã¼ber `AccessControl::require()` prÃ¼fen. Standard ist verboten. FÃ¼r jeden neuen Endpunkt Tests pro Rolle (Admin, Operator, Beobachter, ohne Anmeldung).
7. **Shell-Befehle** nur Ã¼ber `proc_open` mit Argument-Array, nie als Shell-String, nie `exec`/`shell_exec`/`system`/`passthru`/Backticks. Docker nur Ã¼ber den Socket-Proxy.
8. **PasswÃ¶rter** nur Ã¼ber `PasswordHasher` (Argon2id), **API-Tokens** nur gehasht (`ApiToken`). Klartext nur einmal bei der Ausgabe an den Benutzer.
9. **Web:** CSRF-Token fÃ¼r alle Ã¤ndernden Anfragen, Cookies `HttpOnly`, `Secure`, `SameSite=Strict`, Header aus `SecurityHeaders`. Keine Stacktraces im Betrieb.
10. **Keine echten Geheimnisse im Repository**, auch nicht in Tests. Testwerte unter `tests/Fixtures/` mÃ¼ssen offensichtlich unecht sein. gitleaks lÃ¤uft als Pre-Commit-Hook und in CI.
11. **Neue AbhÃ¤ngigkeiten** nur nach RÃ¼ckfrage bei Alex, `composer audit` muss sauber bleiben.

### Sicherheitsnetz â€” Kurzform, Details im Skill

Skills werden nach Ermessen geladen. Was ein Leck oder eine Rechteausweitung bedeutet, steht deshalb auch hier:

| Kurzform | Skill |
|---|---|
| Rechte auch bei **Ansicht** prÃ¼fen; Kategorie aus dem gespeicherten Datensatz, nie aus der Anfrage. | `mer-security` |
| Listen serverseitig auf die erlaubten Kategorien kappen. `category=`, `all=1` sind Anfragen, keine Berechtigung. Liste und Detail nutzen dieselbe Sichtbarkeitsfunktion. | `mer-security` |
| â€žAlle Kategorienâ€œ nur ausdrÃ¼cklich â€” eine leere oder gelÃ¶schte Kategorieliste heiÃŸt nie â€žallesâ€œ. | `mer-security`, `mer-storage` |
| Keine Geheimnisse in Antworten â€” **auch nicht maskiert**, hÃ¶chstens ein `has_*`-Flag. | `mer-security` |
| Kein Geheimnis als Literal im Quelltext (auch nicht als Standardwert, auch nicht base64). HauptschlÃ¼ssel nur als Datei, Lesen erzeugt nie einen SchlÃ¼ssel. | `mer-security` |
| Eingaben, die in Pfad, URL, SQL-Bezeichner, Shell-Argument oder Container-Namen landen, gegen Allowlist prÃ¼fen â€” ablehnen, nie zurechtschneiden. | `mer-security` |
| Kindprozesse erben keine Geheimnisse: `proc_open` immer mit expliziter Umgebung. | `mer-runner` |
| HTTP-Jobs: DNS vor dem Verbinden prÃ¼fen, IP festhalten, Weiterleitungen neu prÃ¼fen, TLS-PrÃ¼fung nie aus. | `mer-runner` |
| Erst maskieren, dann kÃ¼rzen, dann speichern oder senden. | `mer-runner` |
| Nie `copy()` auf die laufende WAL-Datenbank; Sicherung ohne SchlÃ¼ssel, verifiziert. | `mer-storage` |
| **Roundtrip-Test statt â€ž200 reichtâ€œ:** gespeicherten Wert Ã¼ber eine neue Verbindung zurÃ¼cklesen. | `mer-storage` |
| PHPStan ohne neue Funde (Regel 1) â€” Ursache beheben, nichts unterdrÃ¼cken. | alle |

## Arbeitsweise mit Subagenten

Die Agenten unter `.claude/agents/` haben feste Modelle. Verteile Aufgaben nach der Spalte â€žAgentâ€œ in `TODO.md`:

| Agent | Modell | WofÃ¼r |
|---|---|---|
| `architekt` | Opus | Datenmodell, Schnittstellen, Entscheidungen vor jeder Phase |
| `scheduler` | Opus | Cron-Kern, Sommerzeit, verpasste LÃ¤ufe, Ãœberlappung, Wiederholen |
| `sicherheit` | Opus | Rechte, Anmeldung, Geheimnisse, Docker-Proxy; Review am Ende jeder Phase |
| `backend` | Sonnet | API, Datenbankzugriffe, HTTP-Runner, Benachrichtigungen |
| `frontend` | Sonnet | Screens nach dem Klickdummy |
| `infra` | Sonnet | Docker, Compose, systemd, Installer, CI |
| `tester` | Sonnet | Tests schreiben und ausfÃ¼hren |

Am Ende jeder Phase: `sicherheit` prÃ¼ft alle Ã„nderungen der Phase gegen die Sicherheitsregeln, bevor die Phase als erledigt gilt.

### Skill mitpflegen (Pflicht)

Wird eine Regel angelegt, geÃ¤ndert oder entfernt, wird der zustÃ¤ndige Skill **im selben Arbeitsschritt**
angepasst. Kein passender Skill â†’ neuen anlegen, in die Routing-Tabelle oben **und** in `ROUTES` des Hooks
eintragen; maschinell prÃ¼fbare VerstÃ¶ÃŸe zusÃ¤tzlich als Muster im Hook. Herleitung gehÃ¶rt ins Archiv,
nicht in den Skill. Entfernte Regeln auch aus dem Skill lÃ¶schen.

### Eigene Arbeit committen, fremde nicht (Pflicht)

Am Ende einer Aufgabe bleibt nichts Uncommittetes von dir liegen â€” es sei denn, der Auftrag sagt
ausdrÃ¼cklich â€žnicht committenâ€œ. Committet wird nur, was du selbst angefasst hast: `git add <deine Pfade>`,
nie `git add -A` oder `git add .`. Vorher `git status --short` durchgehen und jede Zeile einem eigenen
Arbeitsschritt zuordnen; was du nicht zuordnen kannst, gehÃ¶rt einem anderen Agenten und bleibt liegen.

## Konventionen

- Code und Bezeichner auf Englisch, Kommentare, Fehlermeldungen und OberflÃ¤che auf Deutsch.
- Klassen `final`, Wertobjekte `readonly`.
- Fehlermeldungen sagen, was falsch ist und wie man es behebt, und enthalten nie Geheimnisse.

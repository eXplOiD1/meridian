# Meridian – Aufgaben

Agent = zuständiger Subagent aus `.claude/agents/`. Jede Phase endet mit einem Review durch `sicherheit`.

## Phase 1 – Fundament (MVP 6 PT)

| Status | Aufgabe | Agent |
|---|---|---|
| [x] | Projektgerüst, Composer, PHPStan max, Psalm mit Taint-Analyse, PHPUnit, gitleaks, CI | infra |
| [x] | `SecretBox` (libsodium), `KeyLoader`, `key:generate` | sicherheit |
| [x] | `SecretMasker` mit Mustern und bekannten Werten | sicherheit |
| [x] | `PasswordHasher` (Argon2id), `ApiToken` | sicherheit |
| [x] | `Permission`, `RoleGrant`, `AccessControl` mit Kategorie-Beschränkung | sicherheit |
| [x] | SQLite-`Connection`, `Migrator`, Grundschema mit Standardrollen | backend |
| [x] | `user:create`, `migrate`, Health-Endpunkt, Sicherheits-Header | backend |
| [x] | Dockerfile, compose.yaml, systemd-Dienste, Installer | infra |
| [x] | `composer install` und `composer check` erstmals ausführen, Befunde beheben (PHPStan max mit Strict-Rules, Psalm Level 1, Taint-Analyse, PHPUnit 159 Tests und `composer audit` grün; Psalm lief in der Entwicklungsumgebung auf PHP 8.3.6 mit umgangener Patch-Versionsprüfung, bitte einmal lokal bestätigen) | tester |
| [x] | Anmeldung: Login, Sitzung, CSRF, Abmelden, Sperre nach Fehlversuchen | sicherheit |
| [x] | 2FA mit TOTP (Secret verschlüsselt in `totp_secret_enc`) | sicherheit |
| [x] | Audit-Log für Anmeldungen und Rechteänderungen: Anmeldung, Fehlschlag, Sperre, Entsperren, 2FA und das Anlegen von Benutzern per Befehlszeile werden protokolliert und sind über `GET /api/audit` lesbar. Rechteänderungen per API folgen mit der Benutzerverwaltung | backend |
| [ ] | Review Phase 1: durchgeführt und nachgeprüft. Alle Funde behoben (zuletzt: Versuche werden vor der Passwortprüfung atomar reserviert). Offen ist nur eine Entscheidung von Alex zum Restrisiko „Proxy ohne MERIDIAN_TRUSTED_PROXIES“ (Sperre trifft dann alle Benutzer hinter dem Proxy) | sicherheit |
| [ ] | 2FA-Reset durch einen Administrator (mit Audit-Eintrag), gehört zur Benutzerverwaltung | sicherheit |

## Phase 2 – Scheduler (MVP 6 PT)

| Status | Aufgabe | Agent |
|---|---|---|
| [x] | Entwurf: Warteschlange, Sperre gegen zwei Scheduler, Ablauf eines Laufs (festgehalten im Skill `mer-scheduler`; Migration `0005_scheduler.sql`: Status `skipped`, `runs.scheduled_for/attempt/note/worker`, `jobs.catch_up/retry_delay_seconds`, Tabelle `scheduler_lease`) | architekt |
| [x] | `next_run_at` berechnen und nach jedem Lauf fortschreiben (`Planner`, `CronSchedule::nextAfter()` mit Filter gegen Sommerzeit-Fehler der Bibliothek; `Planner::reschedule()` für Anlegen/Ändern/Aktivieren eines Jobs) | scheduler |
| [x] | Warteschlange und Worker-Prozess (`Worker`, `Runner`-Schnittstelle mit `RunnerRegistry`; bis Phase 3/4 endet ein Lauf ohne Runner als `failed` mit Notiz), Lease-Sperre, `scheduler:run` mit 5-s-Takt und SIGTERM | scheduler |
| [x] | Überlappung `skip`/`parallel`/`queue` | scheduler |
| [x] | Wiederholen mit wachsendem Abstand (`retry_delay_seconds · 2^(Versuch−1)`, höchstens 1 h) | scheduler |
| [x] | Verpasste Läufe nach Neustart (nachholen oder überspringen, höchstens ein Nachholen), hängende Läufe → `aborted` | scheduler |
| [x] | Tests Sommerzeit März und Oktober, Neustart, Überlappung, Sperre, atomare Übernahme (auch mit parallelen Prozessen), SIGTERM | tester |
| [ ] | Manuellen Lauf und Testlauf auslösen (mit `jobs.run`-Prüfung) – kommt mit der Job-API | backend |
| [ ] | Review Phase 2: durchgeführt; Befunde behoben (Herzschlag pro Lauf statt Abbruch lebender Läufe, Ersatzeintrag bei Speicherfehler, Fehlerausgabe ohne Meldung, strenges `Timestamp::parse`); Punkte H1–H4, H6 stehen unter Phase 3. Offen: Nachprüfung der Behebung | sicherheit |

## Phase 3 – HTTP-Jobs (MVP 3 PT)

| Status | Aufgabe | Agent |
|---|---|---|
| [ ] | HTTP-Runner: Methode, Header, Body, Zeitlimit, Weiterleitungen begrenzen | backend |
| [ ] | Payload verschlüsselt speichern, nur im Runner entschlüsseln, im Masker registrieren | sicherheit |
| [ ] | Antwort maskiert und gekürzt im Verlauf speichern | backend |
| [ ] | Testlauf ohne Statistik | backend |
| [ ] | Schutz vor Anfragen ins interne Netz konfigurierbar (SSRF) | sicherheit |
| [ ] | Leak-Tests: Geheimnis in URL, Header und Body taucht in keiner Ausgabe auf | tester |
| [ ] | H1 Manueller Lauf/Testlauf: Recht beim Einreihen mit `AccessControl::require()` (Kategorie aus der DB) plus Audit-Eintrag; in `Worker::claim()` bei Trigger `manual`/`test` prüfen, ob `started_by` noch aktiv und berechtigt ist, sonst `skipped`; Wiederholungen übernehmen `started_by` | sicherheit |
| [ ] | H2 `runs.worker` (Rechnername/PID) nie in API oder Oberfläche ausgeben | backend |
| [ ] | H3 Runner begrenzen die Rohausgabe schon beim Lesen (Speicher); die API nutzt `JSON_INVALID_UTF8_SUBSTITUTE` | backend |
| [ ] | H4 Masker pro Lauf statt ewig wachsender globaler Liste erwägen | sicherheit |
| [ ] | H6 Job-API ruft beim Anlegen, bei Zeitplanänderung und beim Aktivieren `Planner::reschedule()` auf, nach der Rechteprüfung | backend |
| [ ] | HTTP-Runner ruft `Heartbeat::beat()` mindestens alle 20 s, auch während des Wartens auf die Antwort (curl-Fortschritt bzw. `curl_multi`); bei `false` die Übertragung sofort abbrechen | backend |
| [ ] | Review Phase 3 | sicherheit |

## Phase 4 – Shell-Jobs (MVP 6 PT)

| Status | Aufgabe | Agent |
|---|---|---|
| [ ] | Entwurf: Shell-Runner, Docker-Proxy-Anbindung, Container-Auswahl | architekt |
| [ ] | Shell-Runner mit `proc_open` und Argument-Array, Zeitlimit, Abbruch | sicherheit |
| [ ] | Shell-Runner ruft `Heartbeat::beat()` mindestens alle 20 s, auch während des Wartens auf den Kindprozess (`stream_select` mit Zeitlimit); bei `false` sofort SIGTERM/SIGKILL an die Prozessgruppe | sicherheit |
| [ ] | Ausführung in Containern über docker-socket-proxy | backend |
| [ ] | Live-Log per Server-Sent Events | backend |
| [ ] | docker-socket-proxy in compose.yaml aktivieren | infra |
| [ ] | Review Phase 4 | sicherheit |

## Phase 5 – Oberfläche (MVP 8 PT)

| Status | Aufgabe | Agent |
|---|---|---|
| [x] | Vite + React unter `frontend/`, Build nach `frontend/dist` (im Image `/app/ui`, vom Kernel unter `/app/` ausgeliefert), Schriften selbst gehostet | frontend |
| [x] | Scheibe 1 (vorgezogen): Rahmen mit Seitenleiste, Anmeldung mit 2FA, Mein Konto mit 2FA-Einrichtung und QR-Code, Audit-Log, Sperre aufheben, Übersicht mit leerer Abfahrtstafel | frontend |
| [ ] | Übersicht mit Abfahrtstafel und Taktband | frontend |
| [ ] | Jobliste mit Filtern, Job-Editor mit Zeitplan-Vorschau | frontend |
| [ ] | Verlauf mit Live-Log | frontend |
| [ ] | Benutzer und Rollen mit Rechte-Matrix | frontend |
| [ ] | API-Endpunkte für alle Screens mit Rechte-Tests | backend |
| [ ] | Review Phase 5 | sicherheit |

## Phase 6 bis 9 – nach dem MVP

| Status | Aufgabe | Agent |
|---|---|---|
| [ ] | Benachrichtigungen: E-Mail und ntfy (MVP), Telegram, Webhook, Schwellen, Herzschlag | backend |
| [ ] | Statistiken über Wochen und Monate, Verdichtung alter Läufe | backend |
| [ ] | Öffentliche Statusseiten | frontend |
| [ ] | REST-API mit Tokens, curl-Import | backend |
| [ ] | Backup und Wiederherstellung, Update-Ablauf, Doku | infra |
| [ ] | Abschluss-Review vor Version 1.0 | sicherheit |

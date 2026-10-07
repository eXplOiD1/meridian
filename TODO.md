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
| [x] | Review Phase 2: durchgeführt; Befunde behoben (Herzschlag pro Lauf statt Abbruch lebender Läufe, Ersatzeintrag bei Speicherfehler, Fehlerausgabe ohne Meldung, strenges `Timestamp::parse`); Punkte H1–H4, H6 stehen unter Phase 3. Nachprüfung durch sicherheit: alle behoben, N1 (Herzschlag wirft nie) in a8235f0 behoben | sicherheit |

## Phase 3 – HTTP-Jobs (MVP 3 PT)

Entwurf: `docs/decisions/0003-phase3-http-jobs.md` (Abschnitte in Klammern). O1–O13 von Alex entschieden (§8.1); R1–R3 ebenfalls (R1 `http.display_path` Standard `auto`, R2 neue Rechte per Migration nur an Admin, R3 Sperr-Notiz nennt die Freigabe durch einen Admin, ohne IP). Regeländerungen für `CLAUDE.md` und Skills: §10.

| Status | Aufgabe | Agent |
|---|---|---|
| [x] | Entwurf Phase 3: Datenmodell, API, HTTP-Runner, Einstellungen, Oberfläche, Reihenfolge | architekt |
| [x] | S1 Rechte `network.internal_targets` und `settings.manage` (nur Admin, gefährlich), Migration `0007_http_jobs.sql` mit `settings` und `http_internal_targets` (§3.1), `CategoryScope` + `AccessControl::scope()` mit Test `scope ≡ can` (dazu: `Connection` bindet Parameter mit Typ, sonst wäre `:scope_all = 1` nie wahr) | sicherheit |
| [x] | S2 Kernel `put`/`delete`/`post` mit Pfadparametern, `ValidationFailed` → 422, JSON-Helfer mit `no-store` und `JSON_INVALID_UTF8_SUBSTITUTE` (H3), `JsonBody` mit Tiefe | backend |
| [x] | S3 `HttpPayload` (Format v1), `UrlPolicy`, `UrlDisplay` (maskierte Anzeige-URL, E2, mit allen Leak-Tests), `AddressPolicy` mit Freigaben global/je Kategorie, `TargetGuard`, `HostResolver` – Fälle §7.1/§7.2 (dazu `InternalTarget::create()` als gemeinsame Prüfung für Anlegen und Laden, `DbInternalTargetSource`, `DisplayPathMode` mit Standard `auto` nach R1) | sicherheit |
| [x] | S4 `HttpJobConfig`, Validierung §3.2, `JobRepository`/`RunRepository` mit gemeinsamem Scope-Prädikat, `Settings` (Standardwerte, Validierung), Roundtrip-Test | backend |
| [x] | S5 Lesende Job-API (Liste, Detail mit `display_url`, Verlauf, Lauf, Kategorien, Vorschau, `/api/jobs/limits`) mit Rollen-Matrix, IDOR-Tests, H2 | backend |
| [x] | S6 Schreibende Job-API (Anfrage nur als Ganzes ersetzen, Zeitlimit ≤ globales Maximum), löschen, aktivieren/deaktivieren, CSRF, Audit, H6, Leak-Tests | backend |
| [x] | S7 Worker: Masker pro Lauf (H4, neue `Runner`-Signatur), H1 im `claim()` über `RunAuthorizer` (`DbRunAuthorizer`), Wiederholung übernimmt `started_by`, Testlauf auch für deaktivierte Jobs, `RunResult::retryable` | sicherheit |
| [x] | S8 Manueller Lauf und Testlauf (202 über die Warteschlange, H1 beim Einreihen + Audit, 409/429-Grenzen); erledigt auch den offenen Punkt aus Phase 2 | backend |
| [x] | S9 `HttpRunner` + `CurlTransport` (§5): gepinnte IP, Freigaben je Hop, Weiterleitungen manuell, TLS an, Proxy ignoriert, Zeitlimit `min(Job, Maximum)`, Antwort speichern global/Job (`never` gewinnt), Rohausgabe beim Lesen begrenzt (H3), `Heartbeat::beat()` in der `curl_multi`-Schleife; Verdrahtung in `bin/meridian` (Schlüssel erst beim Start von `scheduler:run`) | backend |
| [ ] | S10 Freigaben interner Ziele (Host/CIDR + Port, global oder je Kategorie): API + CLI `http:internal-targets`, Audit, nie freigebbare Netze | sicherheit |
| [ ] | S11 Leak- und SSRF-Gesamtsuite: Geheimnis in URL, Header und Body taucht in keiner Ausgabe auf (Verlauf, Notiz, API inkl. `display_url`, Audit, Prozessausgabe, `error_log`) | tester |
| [x] | S12 `ext-curl` in `composer.json`, `php-curl` im Installer, Image und CA-Bündel prüfen | infra |
| [ ] | S13 `category:create` (CLI, Audit) | backend |
| [ ] | S14 Einstellungs-API `GET /api/settings`, `PUT /api/settings/{key}` (Allowlist, Validierung, Audit); bei `http.display_path = hidden` bestehende `display_url` verschärfen | sicherheit |
| [x] | U1 Router mit Parametern, `request()` PUT/DELETE, Typen, Rechte-Helfer, Menüpunkte „Jobs“ und „Einstellungen“ | frontend |
| [x] | U2 Übersicht „Nächste Abfahrten“ befüllen (Ziel nur Host), Jobliste mit Filtern | frontend |
| [x] | U3 Job anlegen/bearbeiten: maskierte `display_url` als Text, „Anfrage ersetzen“, Cron mit Presets und Vorschau, Zeitlimit mit Hinweis, Antwort speichern (erben/an/aus) | frontend |
| [x] | U4 Job-Detail: Verlauf, Ausgabe als Text, „Jetzt ausführen“, Testlauf mit Abfrage des Ergebnisses | frontend |
| [ ] | U5 Einstellungen für Admins: HTTP-Grenzwerte und Freigaben interner Ziele | frontend |
| [ ] | Regeländerungen aus §10 in `CLAUDE.md` und Skills eintragen | Koordinator |
| [ ] | Review Phase 3 (inkl. Ausnahme `display_url`) | sicherheit |

## Phase 4 – Shell-Jobs (MVP 6 PT)

| Status | Aufgabe | Agent |
|---|---|---|
| [ ] | Entwurf: Shell-Runner, Docker-Proxy-Anbindung, Container-Auswahl | architekt |
| [ ] | Shell-Runner mit `proc_open` und Argument-Array, Zeitlimit, Abbruch | sicherheit |
| [ ] | Shell-Runner ruft `Heartbeat::beat()` mindestens alle 20 s, auch während des Wartens auf den Kindprozess (`stream_select` mit Zeitlimit); bei `false` sofort SIGTERM/SIGKILL an die Prozessgruppe | sicherheit |
| [ ] | Ausführung in Containern über docker-socket-proxy | backend |
| [ ] | Live-Log per Server-Sent Events | backend |
| [ ] | docker-socket-proxy in compose.yaml aktivieren | infra |
| [ ] | Adressen des docker-socket-proxy in `AddressPolicy` fest sperren, nicht freigebbar (sonst wird ein HTTP-Job zum Shell-Job, siehe `docs/decisions/0003-phase3-http-jobs.md` E5) | sicherheit |
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

# Meridian – Aufgaben

Agent = zuständiger Subagent aus `.claude/agents/`. Jede Phase endet mit einem Review durch `sicherheit`.

> **Weitermachen:** siehe `docs/WEITERMACHEN.md` (Stand 09.10.2026, Phase 3 abgeschlossen, nächster Schritt Phase 4).

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
| [x] | S10 Freigaben interner Ziele (Host/CIDR + Port, global oder je Kategorie): API `GET/POST/DELETE /api/settings/internal-targets` (`Network\InternalTargetController`, `InternalTargetStore`) + CLI `http:internal-targets` (`list`, `add`, `remove`), Audit, nie freigebbare Netze | sicherheit |
| [x] | S11 Leak- und SSRF-Gesamtsuite: Geheimnis in URL, Header und Body taucht in keiner Ausgabe auf (Verlauf, Notiz, API inkl. `display_url`, Audit, Prozessausgabe, `error_log`) | tester |
| [x] | S12 `ext-curl` in `composer.json`, `php-curl` im Installer, Image und CA-Bündel prüfen | infra |
| [x] | S13 `category:create` (CLI, Audit) | backend |
| [x] | S14 Einstellungs-API `GET /api/settings`, `PUT /api/settings/{key}` (Allowlist, Validierung, Audit); bei `http.display_path = hidden` bestehende `display_url` verschärfen | sicherheit |
| [x] | U1 Router mit Parametern, `request()` PUT/DELETE, Typen, Rechte-Helfer, Menüpunkte „Jobs“ und „Einstellungen“ | frontend |
| [x] | U2 Übersicht „Nächste Abfahrten“ befüllen (Ziel nur Host), Jobliste mit Filtern | frontend |
| [x] | U3 Job anlegen/bearbeiten: maskierte `display_url` als Text, „Anfrage ersetzen“, Cron mit Presets und Vorschau, Zeitlimit mit Hinweis, Antwort speichern (erben/an/aus) | frontend |
| [x] | U4 Job-Detail: Verlauf, Ausgabe als Text, „Jetzt ausführen“, Testlauf mit Abfrage des Ergebnisses | frontend |
| [x] | U5 Einstellungen für Admins: HTTP-Grenzwerte und Freigaben interner Ziele | frontend |
| [x] | Regeländerungen aus §10 in `CLAUDE.md` und Skills eintragen (lokal, gitignored) | Koordinator |
| [x] | Review Phase 3 (inkl. Ausnahme `display_url`): Befunde behoben (a2ca5f6), Nachprüfung: Phase 3 erledigt. Bedingung: PHPStan max unter PHP 8.4 bzw. in CI belegen | sicherheit |

## Phase 4a – Benutzer und Rollen (vor Phase 4 Umsetzung)

Entwurf: `docs/decisions/0005-benutzer-und-rollen.md` (Abschnitte in Klammern). Offene Entscheidungen B1–B14 (§9): bis Alex entscheidet, gilt die Empfehlung. Regeländerungen für `CLAUDE.md`, Skills und Hook: §10. Erledigt zusätzlich „2FA-Reset durch einen Administrator“ (Phase 1) und „Benutzer und Rollen mit Rechte-Matrix“ (Phase 5).

| Status | Aufgabe | Agent |
|---|---|---|
| [x] | Entwurf Phase 4a: Datenmodell, Rechte-Katalog, API mit Rechte-Matrix, Oberfläche, Reihenfolge | architekt |
| [x] | S1 Migration `0009_users_roles.sql` (nächste freie Nummer, §3.1), Recht `categories.manage` (gefährlich, nur Admin), `GrantPolicy` (`mayAssign`/`mayManage`), `AdminInvariant`, `AssignmentValidator`, `grantsFor()` leer bei Pflicht-Passwortwechsel (E1–E4) | sicherheit |
| [x] | S2 `OneTimePassword` (Sealed), `AuthService::confirmPassword()`/`changeOwnPassword()`, Ablauf des Einmalpassworts, `/me` mit `password_change_required`, `SessionManager` mit Browser/IP und Einzel-Beenden (E6, E7, E10) | sicherheit |
| [ ] | S3 Lesende Benutzer-API `GET /api/users`, `/api/users/{id}`, `/api/roles`, `UserPresenter`, `unlock` CSRF vor Recht (§4.2, §4.4) | backend |
| [x] | S4 Schreibende Benutzer-API: anlegen (Einmalpasswort), Anzeigename, Zuweisungen ersetzen, deaktivieren/aktivieren, Soft-Delete, Audit, Grenzen (E3–E5, §4.3–§4.6) | backend |
| [x] | S5 Admin-Passwort-Reset, 2FA-Reset (Sitzungen enden, Audit), Sitzungen eines Benutzers beenden, jeweils mit Passwort-Bestätigung (E7, E8) | sicherheit |
| [x] | S6 Eigene Daten: `PUT /api/auth/profile`, `POST /api/auth/password`, Sitzungsübersicht/-beenden, Pflichtwechsel sperrt alles außer `me`/`password`/`logout` (E10) | backend |
| [x] | S7 Kategorien-Verwaltung: `GET /api/categories/manage`, anlegen, umbenennen, löschen nur ohne Jobs (409), Freigaben per CASCADE weg, Zuweisungen werden „wirkungslos“, nie „alle“; CLI `category:rename`/`category:delete` (E9) | backend |
| [x] | S8 Gesamtsuite: Rollen-Matrix, IDOR, Rechteausweitung, letzter Admin (auch parallel), H1, Leak-Tests aller Ausgabestellen (§5), Roundtrip, Mutation | tester |
| [x] | U1 Menü „Benutzer & Rollen“ und „Kategorien“ nach Recht, Benutzerliste, Rechte-Matrix (nur lesend) (§7) | frontend |
| [x] | U2 Benutzer anlegen/bearbeiten mit Zuweisungs-Editor, Einmalpasswort einmal anzeigen (§7) | frontend |
| [x] | U3 Sicherheits- und Kontoaktionen (Passwort-/2FA-Reset, Sitzungen, Sperre, Deaktivieren, Löschen) mit `Confirm` + Passwortfeld (§7) | frontend |
| [x] | U4 Kategorien-Seite mit Folgen-Vorschau (§7) | frontend |
| [x] | U5 Mein Konto (Anzeigename, Passwort, Sitzungen), Screen „Passwort festlegen“ (§7) | frontend |
| [ ] | Regeländerungen aus §10 in `CLAUDE.md`, Skills und Hook eintragen | Koordinator |
| [x] | Review Phase 4a (Befunde M1 Admin aktivieren/deaktivieren mit Passwort, N1 Rechte in jeder Verwaltungs-Transaktion frisch, N2 `user:password` löscht Pflichtwechsel und lehnt Gelöschte ab — behoben; dazu `csrf_failed` + Token-Reload in der Oberfläche) | sicherheit |

## Phase 4 – Shell-Jobs (MVP 6 PT)

Entwurf: `docs/decisions/0004-phase4-shell-jobs.md` (Abschnitte in Klammern). Beginnt nach Phase 4a. Offene Entscheidungen O1–O12 (§11): bis Alex entscheidet, gilt die Empfehlung. Regeländerungen für `CLAUDE.md`, Skills und Hook: §13. Ein Agent je Schritt; Abhängigkeiten §9 (S1 → S2 → S3 → S7 → S8; S1 → S6; S3 → S4 → S5; S2 → S9; S8 + S9 → S12; U1 nach S6, U2 nach S4/S5, U3 nach S6).

| Status | Aufgabe | Agent |
|---|---|---|
| [x] | Entwurf Phase 4: Ausführungsorte (Docker-Exec, Sandbox, Host-Agent), Worker-Prozesse, Live-Log, Proxy, Datenmodell, API, Reihenfolge | architekt |
| [x] | S1 Migrationen `0010_workers_and_live_log.sql` und `0011_shell_jobs.sql` (nächste freie Nummern nach 0009, §3), Recht `shell.targets` (gefährlich, nur Admin), Einstellung `shell.max_timeout_seconds`. Abnahme: zweimal einspielen ändert nichts; `settings` überlebt den Neuaufbau (Roundtrip); `SettingKey` ≡ CHECK; Kategorie gelöscht → Freigabe weg; CHECKs von `run_log_chunks`/`exec_ref`/`shell_targets` greifen | sicherheit |
| [x] | S2 Planer und Worker trennen (E5): `worker:run --type=http\|shell` mit Aufseher und Kindern (Umgebungs-Allowlist), `RunHeartbeat` mit Worker-ID (Abbruch je 1 s, keine Sperre), `claim()` ohne Sperre mit Typfilter, `workers`, `StaleRuns` im Planer, `scheduler:run --inline-worker` nur dev. Tests: zwei Worker → jeder Lauf genau einmal, Überlappung nie doppelt, Takt läuft während langer Läufe, Absturz/kein Worker → `aborted`, SIGTERM → `NOTE_WORKER_STOPPED`, Kind-Umgebung ohne Geheimnisse | scheduler |
| [x] | S3 `StreamMasker` (zeilenweise, Überhang), `OutputCollector` (Kopf 16 KiB + Ende 48 KiB), `LiveLog`/`DbLiveLog` (1 MiB je Lauf), neue `Runner`-Signatur, `RunResult::aborted()` (E8, §5.1). Tests: Geheimnis über jede Blockgrenze nie sichtbar, lange Zeilen, Nicht-UTF-8, Eigenschaftstest | sicherheit |
| [x] | S4 `POST /api/runs/{id}/cancel` (E7, Audit `run.cancel_requested`), `GET /api/runs/{id}/log?after=`, neue Lauf-Felder (`exit_code`, `cancelled_by`, `output_bytes`, `live`). Tests: Rollen-Matrix §4.1, IDOR → 404, CSRF, 202/200/409, H2 um `exec_ref` | backend |
| [x] | S5 Live-Log per SSE `GET /api/runs/{id}/live` (§6): Sitzung, `Sec-Fetch-Site`, Scope → 404, `require(jobs.view)` → 403, Belegung `live_streams` (4 global, 2 je Benutzer → 429), 60-s-Verbindungen mit `Last-Event-ID`, Rechte-Neuprüfung alle 15 s, JSON-kodierte Stücke erneut maskiert. Tests §10.6 | backend |
| [x] | S6 Shell-Domäne: `ShellPayload` (Sealed), `ShellJobConfig`, `ShellTargetPolicy`, Ausführungsorte API `/api/settings/shell-targets` + `GET /api/shell/targets` + CLI `shell:targets`, Job-API für `type = shell` mit `jobs.edit_shell` (Skript nur schreibend, nur als Ganzes ersetzen, E6). Tests: Rollen-Matrix, Validierung §4.3, Leak (Skript/Umgebung in keiner Antwort, keinem Audit, nicht in `config_json`), Roundtrip `payload_enc` | sicherheit |
| [x] | S7 `Executor`-Schnittstelle, `LocalProcessExecutor` (`proc_open` mit Argument-Array und `setsid`, explizite Umgebung, PGID-Prüfung, SIGTERM → 10 s → SIGKILL an die Gruppe, reapen), `ShellRunner` (Zeitlimit, Abbruch, `beat()` ≤ 1 s auch bei stiller Ausgabe, 64-MiB-Grenze). Tests §10.4 (Timeout, Gruppenkill, Zombie, Ausgabeflut, ignoriertes SIGTERM) | sicherheit |
| [x] | S8 Ausführung in Containern: `DockerProxyClient` (curl über Unix-Socket, hochgestufter Strom, Rahmen), `DockerExecExecutor` mit Wrapper (`head -c`, kein halbes Schließen, PGID-Kennung), Kill-Exec, `exec_ref`-Aufräumen (§5.5). Tests: Fake-Proxy §10.5, `@group docker` gegen echten Proxy mit alpine/debian (**offen:** echter Docker/wollomatic hier nicht prüfbar, nur Fake-Proxy) | backend |
| [x] | S9 docker-socket-proxy in compose.yaml aktivieren (§7.1): wollomatic mit Digest (O1), Unix-Socket in eigenem Volume, Pfad-Allowlist für jeden gültigen Containernamen (Freigabe nur in der Oberfläche, ADR 0004 N2), `network_mode: none` für Proxy/Shell-Worker/Planer, Planer ohne Schlüssel, Sandbox-Container (O4), `FRANKENPHP_CONFIG num_threads 16`, `posix`-Prüfung, systemd `meridian-worker@`, Installer, Deploy-Hinweise. Abnahme: `containers/create` über den Proxy → 403, Exec in Sandbox → 201, `worker-http` ohne Socket | infra |
| [ ] | S10 Proxy für HTTP-Jobs unerreichbar (E11): Abgleich mit `InfrastructureTargets` (Ports 2375/2376 und Proxy-Namen nie freigebbar), `MERIDIAN_DOCKER_PROXY` nur `unix://`. Tests: Freigabe `10.0.0.0/8` öffnet `:2375` nicht, Freigabe mit Port 2375 → 422, `tcp://` → Start abgelehnt. Ersetzt den bisherigen Punkt „Adressen des docker-socket-proxy in `AddressPolicy` fest sperren“ | sicherheit |
| [ ] | S11 Host-Ausführung für systemd (E3, §5.6): `bin/meridian-shell-agent`, `HostSocketExecutor`, `meridian-shell.socket`/`@.service` als `meridian-run`. Darf nach Phase 9 rutschen (O3). Tests: kein Zugriff auf Daten/Schlüssel, Protokoll-Fuzz, Verbindungsabbruch beendet die Gruppe | sicherheit |
| [ ] | S12 Gesamt-Leak- und Prozess-Suite `tests/Integration/Phase4` (§10): Geheimnis in Skript, Umgebung und Ausgabe erscheint an keiner Ausgabestelle aus §12 | tester |
| [x] | U1 Job-Editor für Shell (§8): Ausführungsort, Interpreter, Benutzer (Warnung bei `root`), Arbeitsverzeichnis, Skript und Umgebung nur schreibend, „Skript ersetzen“ | frontend |
| [x] | U2 Live-Log-Ansicht mit EventSource und Rückfall auf Abfragen, Ausgabe als Text, „Lauf abbrechen“ mit `Confirm` (§8) | frontend |
| [x] | U3 Einstellungen: Karte „Shell-Jobs“ (Maximum Zeitlimit) und „Ausführungsorte“ (§8) | frontend |
| [ ] | Regeländerungen aus §13 in `CLAUDE.md`, Skills und Hook eintragen | Koordinator |
| [ ] | Review Phase 4 (Proxy-Allowlist, Kind-Umgebungen, Strom-Maskierung, SSE-Autorisierung) | sicherheit |

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

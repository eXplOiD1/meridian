# Weitermachen – Stand vom 10.10.2026

Branch `claude/great-clarke-ubr0vc`. Phase 1–3 sind abgeschlossen (Review inkl. Nachprüfung). Phase 4a (Benutzer und Rollen)
ist begonnen, Phase 4 (Shell-Jobs) ist entworfen. Normativ: `docs/decisions/0003-phase3-http-jobs.md` (+Nachtrag E13–E17),
`0004-phase4-shell-jobs.md`, `0005-benutzer-und-rollen.md`. Aufgabenliste: `TODO.md`.

## Stand (11.10.2026)
Phase 1–3 und **Phase 4a (Benutzer und Rollen)** sind abgeschlossen. **Phase 4 (Shell-Jobs)** ist bis auf die unten genannten Punkte fertig:
S1–S10 und S12 sowie U1–U3 sind erledigt und gepusht (Planer/Worker getrennt, StreamMasker/Live-Log, Abbruch- und Log-API, SSE,
Shell-Domäne, `LocalProcessExecutor`, Docker-Exec über Proxy, Compose, Proxy für HTTP unerreichbar, Gesamt-Leak-Suite).
Die Suiten liegen lokal unter `tests/` (gitignored), u. a. `tests/Integration/Phase4/ShellLeakPipelineTest.php`.

## Offen in Phase 4 (in dieser Reihenfolge)
1. **S11 Host-Agent** (`bin/meridian-shell-agent`, `HostSocketExecutor`, systemd-Units): darf nach Phase 9 rutschen (O3); bis dahin endet ein
   Host-Lauf sofort mit fester Notiz.
2. **Phasenreview Sicherheit** (Agent `sicherheit`) über alle Änderungen der Phase 4.
3. **PHPStan max unter PHP 8.4 bzw. in CI belegen** (lokal läuft PHP 8.3); neue ZIP an Alex nach jedem Abschluss.
4. **Abnahme gegen echten Docker und wollomatic-Proxy** (`@group docker`, alpine und debian:stable-slim): bisher nur Fake-Proxy geprüft.

## Offene Entscheidungen von Alex (bis dahin gelten die Empfehlungen aus ADR 0004 O1–O12 und 0005 B1–B14)
- Proxy-Image `wollomatic/socket-proxy` mit Digest pinnen (lokal kein Netz: Digest selbst eintragen, `MERIDIAN_PROXY_IMAGE`).
- Compose-Betrieb: Scheduler erreicht Meridian über `web:8080`/Host-Port; optional `MERIDIAN_SELF_HOSTS` (0003 E17).

## Arbeitsweise (Sparmodus, Entscheidung Alex 10.10.)
- Agenten starten mit Stufe **mittel** (Umsetzung); **hoch** nur für Entwürfe und das Phasen-Review der Sicherheit. Stufe nur beim Start einstellbar.
- Zwischendurch nur gezielte Tests; volle Suite, PHPStan, Psalm, Taint, smoke, audit einmal pro Commit. Mutationsprüfung nur für zentrale Sicherheitsregeln.
- Höchstens zwei Agenten parallel, wenige größere Schritte, Review nur am Phasenende, kurze Berichte.

## Beim Deploy zu beachten
- Compose auf dem NAS aus dem Repository neu einfügen (Raw-Datei `compose.yaml`; **keine** alte Git-Context-Fassung: das NAS hat kein `git`).
  Vorher klären, ob der Hauptschlüssel im Volume `meridian-secrets` oder in einer Datei `secrets/master.key` liegt (nicht verlieren!).
- Migrationen 0005–0009 und das Verschärfen der Anzeige-URLs laufen bei `migrate` beim Start automatisch.
  **0009 bricht ab**, wenn zwei Kategorienamen sich nur in der Groß-/Kleinschreibung unterscheiden (Prüfabfrage siehe ADR 0005 §3).
- Jobs im internen Netz: Einstellungen → „Freigaben interner Ziele“ oder `docker exec -it meridian-web php bin/meridian http:internal-targets add …`.
- Kategorien: `category:create|rename|delete`. Einstellungen: `settings:get|set`.

## Eigene Rollen (Phase 5, Stand 10.10.2026)
- ADR `docs/decisions/0006-eigene-rollen.md`, F1–F9 von Alex entschieden (§9.1). **R1–R3 erledigt und gepusht**: Migration 0013,
  `roles.manage`, `BuiltinRole`, `RoleAdminService` (alle Prüfungen E3–E5, F5-Ausnahme für volle Admins, Passwort einmal je
  Anfrage, Grenzen), Rollen-API `/api/roles/manage`, `POST/PUT/DELETE /api/roles…`, `GET /api/users?role_id=`, `/me` maskiert.
  Tests lokal: `tests/Unit/Database/CustomRolesMigrationTest.php`, `tests/Unit/User/RoleAdminServiceTest.php`,
  `tests/Unit/Http/RoleApiTest.php` (inkl. Wettlauf zweier Prozesse, Leak, Mutanten der zentralen Regeln).
- Offen: R4 (Gesamtsuite: Wirkung in offenen Sitzungen, SSE, wartende Läufe), R5 (Oberfläche), R6 (Review Phase 5).
- Ab Migration 0013 Standardrollen nur über `roles.builtin_key` ansprechen (nie über den Namen); neues Recht → Trigger
  `role_permissions_known` in seiner Migration neu anlegen. **0013 bricht ab**, wenn eine der Standardrollen per Hand
  umbenannt wurde (dann Namen Admin/Operator/Beobachter zurücksetzen). `migrate` warnt, wenn kein Admin mit allen Rechten bleibt.

## Offene Entscheidungen von Alex (bis dahin gelten die Empfehlungen aus den ADRs)
- ADR 0004 O1–O12 (u. a. Proxy-Image `wollomatic/socket-proxy`, Host-Ausführung für systemd erst Phase 9, Sandbox-Container, Skript nicht wieder lesbar).
- ADR 0005 B2–B14 (B1 „feste Rollen“ abgelöst durch 0006; u. a. Soft-Delete, Einmalpasswort 7 Tage, eigenes Passwort bei gefährlichen Aktionen).
- Compose-Betrieb: Scheduler erreicht Meridian über `web:8080`/Host-Port; optional `MERIDIAN_SELF_HOSTS` (0003 E17).
- Sieht ein Host mit Geheimnis in der Subdomain (z. B. pipedream) → `http.display_host = hidden` einstellbar.

## Bekannte Punkte
- Zweite IP-Prüfung (`CURLOPT_PREREQFUNCTION`) greift erst unter PHP 8.4 vor dem Senden; lokal 8.3 danach.
- `Australia/Lord_Howe` weicht im Zeitplan ab. Optionale Härtung: v1-Anzeige-URLs ohne `=` komplett schwärzen.
- Tests, `.claude/` und `CLAUDE.md` sind gitignored und liegen nur lokal (ZIP an Alex nach jedem Abschluss).
- Agenten dürfen nie `git stash`/`git checkout` auf ungestagte Dateien anwenden.

## Stand Feierabend (11.10.2026)
Phase 4 abgeschlossen (Review durch Koordinator, Fund Container-ID-Umgehung behoben, Skript lief auf dem NAS im Container). Eigene Rollen R1–R3, R5 fertig (R4 Gesamtsuite und R6 Review offen). Job-Editor mit Reitern und Zeitplan-Baukasten, Quelltext-Abruf auch über HTTP, Verlauf `#/verlauf` (`GET /api/runs`).
Als Nächstes: (1) Taktband der Übersicht (Vorlage aus dem Klickdummy nötig) oder Lauf-Details/Live-Log im Verlauf, (2) Rest Zeitplan/Reiter („Zeitplan läuft ab“ braucht Backend), (3) R4, Review Phase 5, (4) Phase 6: Benachrichtigungen mit eigenem SMTP-Versand (Host, Port, STARTTLS/SSL, Passwort verschlüsselt, Testmail) und ntfy; PHPMailer nur nach Freigabe von Alex, (5) S11 Host-Agent darf nach Phase 9. Offen bei Alex: NAS-Test Skript im Editor und neue Reiter, PHPStan max unter PHP 8.4/CI.

# Weitermachen – Stand vom 10.10.2026

Branch `claude/great-clarke-ubr0vc`. Phase 1–3 sind abgeschlossen (Review inkl. Nachprüfung). Phase 4a (Benutzer und Rollen)
ist begonnen, Phase 4 (Shell-Jobs) ist entworfen. Normativ: `docs/decisions/0003-phase3-http-jobs.md` (+Nachtrag E13–E17),
`0004-phase4-shell-jobs.md`, `0005-benutzer-und-rollen.md`. Aufgabenliste: `TODO.md`.

## Stand (11.10.2026)
Phase 1–3 und **Phase 4a (Benutzer und Rollen)** sind abgeschlossen (Review inkl. Fixes, d5860bf).
**Phase 4 (Shell-Jobs) läuft:** fertig und gepusht: S9 (Compose mit docker-socket-proxy, worker-http/-shell, Sandbox), S1 (Migrationen 0010/0011,
Recht `shell.targets`, `shell.max_timeout_seconds`), S2 (Planer/Worker getrennt, `worker:run --type=http|shell`).
**S3 (StreamMasker, OutputCollector, LiveLog) ist angefangen, ungeprüft:** Stand als `docs/wip/phase4-s3-streammasker-unfertig.patch`
(`git apply`; betrifft bin/meridian, HttpRunner, RunResult, Runner, Worker, SecretMasker und neue Klassen LiveLog*, Utf8, `src/Runner/Shell/`,
DbLiveLog*). Tests liegen teils lokal (gitignored). Danach S3 abschließen, Patch löschen.

## Nächste Schritte (in dieser Reihenfolge)
1. **Phase 4 S3** fertig (StreamMasker zeilenweise mit Überhang, OutputCollector Kopf 16 KiB + Ende 48 KiB, LiveLog 1 MiB, Runner-Signatur).
2. S4 (Abbruch- und Log-API), S5 (Live-Log per SSE), S6 (Shell-Domäne, Ausführungsorte), S7 (LocalProcessExecutor, ShellRunner),
   S8 (Docker-Exec über Proxy), S10 (Proxy für HTTP unerreichbar), S11 (Host-Agent, darf nach Phase 9), S12 (Gesamtsuite), U1–U3, Review.
3. PHPStan max unter PHP 8.4 bzw. in CI belegen; neue ZIP an Alex nach jedem Abschluss.

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

## Offene Entscheidungen von Alex (bis dahin gelten die Empfehlungen aus den ADRs)
- ADR 0004 O1–O12 (u. a. Proxy-Image `wollomatic/socket-proxy`, Host-Ausführung für systemd erst Phase 9, Sandbox-Container, Skript nicht wieder lesbar).
- ADR 0005 B1–B14 (u. a. feste Rollen, Soft-Delete, Einmalpasswort 7 Tage, eigenes Passwort bei gefährlichen Aktionen).
- Compose-Betrieb: Scheduler erreicht Meridian über `web:8080`/Host-Port; optional `MERIDIAN_SELF_HOSTS` (0003 E17).
- Sieht ein Host mit Geheimnis in der Subdomain (z. B. pipedream) → `http.display_host = hidden` einstellbar.

## Bekannte Punkte
- Zweite IP-Prüfung (`CURLOPT_PREREQFUNCTION`) greift erst unter PHP 8.4 vor dem Senden; lokal 8.3 danach.
- `Australia/Lord_Howe` weicht im Zeitplan ab. Optionale Härtung: v1-Anzeige-URLs ohne `=` komplett schwärzen.
- Tests, `.claude/` und `CLAUDE.md` sind gitignored und liegen nur lokal (ZIP an Alex nach jedem Abschluss).
- Agenten dürfen nie `git stash`/`git checkout` auf ungestagte Dateien anwenden.

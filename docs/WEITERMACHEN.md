# Weitermachen – Stand vom 10.10.2026

Branch `claude/great-clarke-ubr0vc`. Phase 1–3 sind abgeschlossen (Review inkl. Nachprüfung). Phase 4a (Benutzer und Rollen)
ist begonnen, Phase 4 (Shell-Jobs) ist entworfen. Normativ: `docs/decisions/0003-phase3-http-jobs.md` (+Nachtrag E13–E17),
`0004-phase4-shell-jobs.md`, `0005-benutzer-und-rollen.md`. Aufgabenliste: `TODO.md`.

## Stand Phase 4a
Fertig und gepusht: S1 (Migration 0009, Rechte, GrantPolicy, letzter Admin), S2 (Einmalpasswort, Passwort-Bestätigung, Sitzungen),
S3 (lesende Benutzer-API), S7 (Kategorien-Verwaltung API + CLI).
**Angefangen, ungeprüft: S4 (schreibende Benutzer-API).** Der Stand liegt als
`docs/wip/phase4a-s4-benutzer-schreib-api-unfertig.patch` (`git apply`; betrifft `AppFactory`, `UserController`, `UserRepository`
und neue Klassen `Actor`, `CreatedUser`, `DisplayName`, `UserAdminService`, `UserRequestRefused`). Danach Tests, Qualitätskette,
committen, Patch löschen.

## Nächste Schritte (in dieser Reihenfolge)
1. **S4** fertigstellen (anlegen mit Einmalpasswort, Anzeigename, Zuweisungen, deaktivieren/aktivieren, Soft-Delete, Audit, Grenzen).
2. **S6** eigene Daten (`PUT /api/auth/profile`, `POST /api/auth/password`, Sitzungen), **S5** Admin-Passwort-Reset/2FA-Reset/Sitzungen beenden
   (sicherheit), **S8** Gesamtsuite (tester).
3. **U1–U5** Oberfläche (frontend): Benutzer & Rollen, Zuweisungs-Editor, Konto-/Sicherheitsaktionen, Kategorien-Seite, Mein Konto,
   Screen „Passwort festlegen“ (403 mit `password_change_required`), Bezeichnung für `categories.manage` in `lib/permissions.ts`.
4. Review Phase 4a (sicherheit); dabei auch prüfen: einmaliges 403 bei `POST /api/jobs` direkt nach frischer Sitzung (nicht reproduziert).
5. **Phase 4 – Shell-Jobs** nach ADR 0004 (neue Compose-Struktur mit Proxy `wollomatic/socket-proxy`, Sandbox-Container, Worker).
6. PHPStan max unter PHP 8.4 bzw. in CI belegen; neue ZIP an Alex nach jedem Abschluss.

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

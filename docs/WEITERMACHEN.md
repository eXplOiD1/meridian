# Weitermachen – Stand vom 07.10.2026

Branch `claude/great-clarke-ubr0vc`. Phase 1 und 2 sind abgeschlossen (Review inkl. Nachprüfung).
Phase 3 (HTTP-Jobs) ist zum großen Teil fertig. Normativ: `docs/decisions/0003-phase3-http-jobs.md`, Aufgabenliste: `TODO.md`.

## Fertig in Phase 3
S1–S10, S12–S14 und U1–U5: Rechte und Migration 0007, Kernel (PUT/DELETE, 422), URL-/Payload-/SSRF-Bausteine,
Job-Repositories und -API (lesen und schreiben), manueller Lauf/Testlauf, HTTP-Runner mit Schutz vor internen Zielen,
Freigaben interner Zielen (API + CLI `http:internal-targets`), `category:create`, `ext-curl`, Job-Bildschirme.

## Offen (in dieser Reihenfolge)
1. **S14 Einstellungs-API** (sicherheit): **erledigt** (09.10.2026). `GET /api/settings`, `PUT /api/settings/{key}`,
   CLI `settings:get [key]` / `settings:set <key> <wert>|--reset`, Audit `settings.changed`; `http.display_path = hidden`
   verschärft bestehende `display_url` in derselben Transaktion, zurück auf `auto` lockert nichts. Tests (lokal, gitignored):
   `tests/Unit/Settings/SettingsApiTest.php`, `tests/Unit/Console/SettingsCommandTest.php`,
   `tests/Unit/Runner/Http/UrlDisplayHideStoredTest.php`. Der WIP-Patch ist entfernt.
2. **S11 Leak- und SSRF-Gesamtsuite** (tester): Ende-zu-Ende über API → Scheduler → HttpRunner → Verlauf/Audit/Ausgabe.
   Nur lokal (`tests/Integration/Phase3/`, gitignored), war noch nicht fertig (Hilfsmethode `LeakServer::url()` fehlte).
3. **U5 Einstellungsseite** (frontend): **erledigt** (09.10.2026). `#/einstellungen`, `frontend/src/screens/Settings.tsx`: drei HTTP-Einstellungen
   mit Standard/Quelle/Zurücksetzen, Freigaben interner Ziele (Liste, Anlegen, Entfernen mit Rückfrage). Im Chromium geprüft (Admin, Operator, Beobachter, 375 px).
4. **Regeländerungen aus ADR §10** in `CLAUDE.md` und Skills: Ausnahme `display_url` und Einstellungs-/Freigabe-Regeln
   sind lokal eingetragen; Rest prüfen (mer-ui, mer-runner, Hook-Muster). Dateien liegen nur lokal (gitignored).
5. **Review Phase 3** (sicherheit), inkl. Ausnahme `display_url`. Danach `TODO.md` abhaken.
6. Neue ZIP mit dem Stand an Alex (Repo + `tests/`, `.claude/`, `CLAUDE.md`, Konfigurationen).

## Beim Deploy zu beachten
- Compose auf dem NAS neu einfügen (Dockerfile-Block hat sich geändert: curl-Prüfung im Build), Image neu bauen.
  Falls der Build an der curl-Prüfung scheitert: Meldung an Claude.
- Migrationen 0005–0007 laufen beim Start automatisch.
- Für Jobs im internen Netz: `docker exec -it meridian-web php bin/meridian http:internal-targets add …`.

## Offene Entscheidungen von Alex
- **Proxy-Frage** (Phase 1): A = Login ablehnen, wenn `X-Forwarded-For` ohne `MERIDIAN_TRUSTED_PROXIES` kommt (Empfehlung),
  B = Risiko akzeptieren und dokumentieren. Alex entscheidet am Schluss.
- Admin-2FA-Zurücksetzen gehört zur Benutzerverwaltung (späterer Schritt).

## Bekannte Punkte
- Zweite IP-Prüfung (`CURLOPT_PREREQFUNCTION`) greift erst unter PHP 8.4 vor dem Senden (Produktiv-Image); lokal 8.3 danach.
- Lange Läufe halten den Scheduler-Takt auf, bis Phase 4 einen eigenen Prozess bringt.
- `Australia/Lord_Howe` (30-Minuten-Umstellung) weicht im Zeitplan ab.
- Commit-Trailer mancher Agenten nannten „Opus“ statt „Sonnet“ (kosmetisch).
- Anzeige-URL im Modus `auto` zeigt reine Kleinbuchstaben-Pfadsegmente (dokumentiertes Restrisiko).

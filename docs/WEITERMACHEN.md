# Weitermachen – Stand vom 09.10.2026

Branch `claude/great-clarke-ubr0vc`. Phase 1, 2 und 3 sind abgeschlossen (Review inkl. Nachprüfung durch `sicherheit`).
Normativ für Phase 3: `docs/decisions/0003-phase3-http-jobs.md`. Aufgabenliste: `TODO.md`.

## Was jetzt da ist
- Anmeldung (auch über HTTP mit Warnhinweis), 2FA, Audit-Log, Sperre, Rollen und Kategorien.
- Scheduler-Kern (Lease, Planer, Warteschlange, Überlappung, Wiederholen, Nachholen, Herzschlag).
- HTTP-Jobs: Job-API (lesen/schreiben), manueller Lauf und Testlauf, HTTP-Runner mit SSRF-Schutz, Freigaben interner Ziele
  (API, CLI `http:internal-targets`, Einstellungsseite), Einstellungen (API, CLI `settings:get|set`), `category:create`.
- Oberfläche: Übersicht „Nächste Läufe“, Jobliste, Job anlegen/bearbeiten, Job-Detail mit Verlauf, Einstellungen.

## Nächste Schritte
1. **Phase 4 – Shell-Jobs** (siehe `TODO.md`): zuerst Entwurf durch `architekt` (Shell-Runner, Docker-Socket-Proxy, eigener
   Prozess für lange Läufe, Live-Log per SSE), dann Umsetzung, Review durch `sicherheit`.
2. **PHPStan max unter PHP 8.4 oder in CI belegen** (lokal läuft PHP 8.3, PHPStan nur über die Sonderkonfiguration `tools/`).
3. Phase 5 (restliche Oberfläche: Verlauf, Statusseiten, Benutzer & Rollen, Kategorien-Verwaltung), Benachrichtigungen,
   Admin-2FA-Zurücksetzen, optional ghcr.io-Image.

## Beim Deploy zu beachten
- Compose auf dem NAS neu einfügen (Dockerfile-Block hat sich geändert: curl-Prüfung im Build), Image neu bauen.
  Falls der Build an der curl-Prüfung scheitert: Meldung an Claude.
- Migrationen 0005–0007 und das Verschärfen der Anzeige-URLs laufen bei `migrate` beim Start automatisch.
- Migration 0009 (Phase 4a) bricht ab, wenn zwei Kategorien sich nur in der Groß-/Kleinschreibung unterscheiden
  (neuer eindeutiger Index ohne Schreibung). Vorher prüfen: `SELECT lower(name), COUNT(*) FROM categories GROUP BY 1 HAVING COUNT(*) > 1;`
  — Treffer umbenennen, dann starten. Die Migration rollt sonst vollständig zurück.
- Für Jobs im internen Netz: Einstellungen → „Freigaben interner Ziele“ oder
  `docker exec -it meridian-web php bin/meridian http:internal-targets add …`.
- Kategorien anlegen: `docker exec -it meridian-web php bin/meridian category:create "Name"`.

## Offene Entscheidungen von Alex
- **Proxy-Frage** (Phase 1): A = Login ablehnen, wenn `X-Forwarded-For` ohne `MERIDIAN_TRUSTED_PROXIES` kommt (Empfehlung),
  B = Risiko akzeptieren und dokumentieren.
- **R1:** Standard von `http.display_path` auf `hidden` stellen? (`auto` zeigt reine Kleinbuchstaben-Pfadwörter, z. B. ntfy-Themen.)
- **Sieht ein Beobachter die Anzeige-URL** (`display_url`) oder nur, wer bearbeiten darf?
- **Host mit Geheimnis in der Subdomain** (z. B. `*.pipedream.net`) erscheint in Liste/Abfahrtstafel (O2).
- **Loopback-/Docker-Netz-Freigaben** erreichen Meridian selbst bzw. ab Phase 4 den Socket-Proxy: ausschließen?
- Admin-2FA-Zurücksetzen gehört zur Benutzerverwaltung (späterer Schritt).

## Bekannte Punkte
- Zweite IP-Prüfung (`CURLOPT_PREREQFUNCTION`) greift erst unter PHP 8.4 vor dem Senden (Produktiv-Image); lokal 8.3 danach.
- Lange Läufe halten den Scheduler-Takt auf, bis Phase 4 einen eigenen Prozess bringt (Verpasst-Fenster 300 s).
- `Australia/Lord_Howe` (30-Minuten-Umstellung) weicht im Zeitplan ab.
- Optionale Härtung: v1-Anzeige-URLs ohne `=` komplett schwärzen (`UrlDisplay::upgradeStored`), siehe Nachprüfung.
- Commit-Trailer mancher Agenten nannten „Opus“ statt „Sonnet“ (kosmetisch).
- Anzeige-URL im Modus `auto` zeigt reine Kleinbuchstaben-Pfadsegmente (dokumentiertes Restrisiko).

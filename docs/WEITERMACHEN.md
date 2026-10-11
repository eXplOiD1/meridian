# Weiterarbeiten

Arbeitsverzeichnis ist ausschließlich das Produktverzeichnis `Meridian/`. GitHub-Arbeitsbranch: `claude/great-clarke-ubr0vc`.

Die offenen Aufgaben stehen in [TODO.md](../TODO.md). Docker- und Host-Agent-Abnahme auf einem echten Ubuntu-Server mit systemd sind erfolgreich, einschließlich Prozessgruppen-Abbruch, Zeitlimit, Isolation und Geheimnismaskierung über API und Worker. Alex hat den SMTP-Versand erfolgreich getestet. GHCR-Freigabe und Update-Abruf bleiben offen. Alex übernimmt die Veröffentlichung auf `main` selbst. Der Laufzeitvergleich ist implementiert: optionale Warnung nach erfolgreichem Lauf, Median der letzten 20 erfolgreichen Läufe, mindestens fünf Vergleichswerte, Schwelle 110–1000 Prozent (UI-Vorgabe 200). Testläufe werden ausgeschlossen. Migration 0027 erhält bestehende Outbox-Nachrichten atomar; Datenerhalt und Rollback sind getestet.

## Veröffentlichung

`compose.yaml` verwendet GHCR-Images mit `latest`; `compose.build.yaml` baut alternativ den Quellcode aus GitHub (Standard `main`). Der Container-Workflow veröffentlicht auf dem Arbeitsbranch `candidate`, auf `main` zusätzlich `main` und `latest`. Die Pakete müssen öffentlich abrufbar sein, bevor die Registry-Compose-Datei ohne Anmeldung eingesetzt werden kann.

## Prüfungen

PHPStan max unter PHP 8.4 und Psalm-Taint-Analyse waren lokal erfolgreich. Frontend-Build sowie gezielte API-Tests für interne Ziele und Benachrichtigungssprache waren erfolgreich. Die vollständige Suite unter Linux/PHP 8.4 ist erfolgreich: 1.153 Tests, 179.805 Assertions, ohne Fehler oder Warnungen. Auch der Container-Workflow besteht die gesamte Suite und PHPStan. Testbericht: https://github.com/eXplOiD1/meridian/actions/runs/38169748594. Auf Ubuntu 26.04.1 mit PHP CLI 8.5.4 war die vorherige Suite mit 1.147 Tests ebenfalls grün. FrankenPHP 1.13.0 (PHP 8.5.11), Node.js 22.22.1 und Docker 29.1.3 wurden für die reale Abnahme verwendet. Installation, erneute Installation mit unverändertem Schlüssel und automatischer Dienststart nach Server-Neustart sind geprüft. SMTP-Versand ist durch Alex als funktionierend bestätigt.

## Sprache

Die UI-Sprache wird im Benutzerkonto gespeichert. Benachrichtigungskanäle bieten `ui`, `de` und `en`. `ui` liest beim Versand die aktuelle Kontosprache der Person, die den Kanal zuletzt gespeichert hat. Migration 0026 ordnet bestehende Kanäle anhand ihres ursprünglichen Audit-Eintrags zu; ohne zuordenbares Konto gilt Deutsch. Eigene Namen und Inhalte bleiben unverändert.

## Agent im Entwicklungscontainer

`MERIDIAN_ENV=dev php bin/meridian-shell-agent --development-container` überspringt ausschließlich die Container-Markierungen. Ohne beide Einstellungen bleiben die normalen Regeln aktiv. root-Ausführung und lesbare Schlüssel-/Datenpfade bleiben verboten. Die normalen systemd-Units und Compose-Dateien verwenden den Schalter nicht. Agent-Funktionstests im Container ersetzen keine Abnahme der systemd-Socket-Aktivierung.

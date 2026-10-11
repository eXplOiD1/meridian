# Weiterarbeiten

Arbeitsverzeichnis ist ausschließlich das Produktverzeichnis `Meridian/`. GitHub-Arbeitsbranch: `claude/great-clarke-ubr0vc`.

Die offenen Aufgaben stehen in [TODO.md](../TODO.md). Docker/systemd, SMTP sowie GHCR-Freigabe und Update-Abruf sind auf Alex' Wunsch zurückgestellt. Alex übernimmt die Veröffentlichung auf `main` selbst. Der gewünschte Laufzeitvergleich ist noch offen.

## Veröffentlichung

`compose.yaml` verwendet GHCR-Images mit `latest`; `compose.build.yaml` baut alternativ den Quellcode aus GitHub (Standard `main`). Der Container-Workflow veröffentlicht auf dem Arbeitsbranch `candidate`, auf `main` zusätzlich `main` und `latest`. Die Pakete müssen öffentlich abrufbar sein, bevor die Registry-Compose-Datei ohne Anmeldung eingesetzt werden kann.

## Prüfungen

PHPStan max unter PHP 8.4 und Psalm-Taint-Analyse waren lokal erfolgreich. Frontend-Build sowie gezielte API-Tests für interne Ziele und Benachrichtigungssprache waren erfolgreich. Die vollständige Suite unter Linux/PHP 8.4 ist erfolgreich: 1.136 Tests, 179.599 Assertions, ohne Fehler oder Warnungen. Auch der Container-Workflow besteht die gesamte Suite und PHPStan. Testbericht: https://github.com/eXplOiD1/meridian/actions/runs/38148332392. Echte Docker-, systemd- und SMTP-Abnahme stehen weiterhin aus.

## Sprache

Die UI-Sprache wird im Benutzerkonto gespeichert. Benachrichtigungskanäle bieten `ui`, `de` und `en`. `ui` liest beim Versand die aktuelle Kontosprache der Person, die den Kanal zuletzt gespeichert hat. Migration 0026 ordnet bestehende Kanäle anhand ihres ursprünglichen Audit-Eintrags zu; ohne zuordenbares Konto gilt Deutsch. Eigene Namen und Inhalte bleiben unverändert.

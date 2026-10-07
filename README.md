# Meridian

Selbst gehosteter Job-Scheduler für HTTP- und Shell-Jobs, mit Benutzern, Rollen und Rechten pro Kategorie.

> Stand: Phase 1 (Fundament) in Arbeit. Noch nicht für den Betrieb geeignet.

Plan und Aufgaben: [`docs/PLAN.md`](docs/PLAN.md), [`TODO.md`](TODO.md).

## Betrieb mit Docker

Es genügt die Datei `compose.yaml`, vorbereitet werden muss nichts. Als Projekt in Docker bereitstellen
(UGOS-Docker-App) oder auf dem Server:

```bash
docker compose up -d --build
```

Das Image wird dabei aus GitHub gebaut. Beim ersten Start legt der Container selbst an: den Hauptschlüssel
(Volume `meridian-secrets`, wird nie überschrieben), die Datenbank (Volume `meridian-data`) und den ersten Admin.
Ohne Angabe heißt er `admin` und bekommt ein zufälliges Passwort, das einmalig im Log steht
(`docker compose logs web`). Eigene Zugangsdaten über `MERIDIAN_ADMIN_USER` und `MERIDIAN_ADMIN_PASSWORD`
(mind. 8 Zeichen, oder `MERIDIAN_ADMIN_PASSWORD_FILE`), siehe `.env.example`. Erreichbar unter
`http://<host>:8090` (Port über `MERIDIAN_PORT`).

Aktualisieren auf die neueste Version:

```bash
docker compose build --pull --no-cache && docker compose up -d
```

Das Volume `meridian-secrets` sicher aufbewahren (Sicherung mitnehmen): ohne den Schlüssel sind gespeicherte
Geheimnisse verloren.

## Betrieb auf Linux mit systemd

```bash
sudo ./deploy/install.sh
```

Der Installer fragt nach dem Benutzernamen und dem Passwort des ersten Admins.

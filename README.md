# Meridian

Selbst gehosteter Job-Scheduler für HTTP- und Shell-Jobs, mit Benutzern, Rollen und Rechten pro Kategorie.

> Stand: Phase 1 (Fundament) in Arbeit. Noch nicht für den Betrieb geeignet.

Plan und Aufgaben: [`docs/PLAN.md`](docs/PLAN.md), [`TODO.md`](TODO.md).

## Betrieb mit Docker

Auf dem Server genügt die Datei `compose.yaml` (das Image wird direkt aus GitHub gebaut):

```bash
mkdir -p data secrets
openssl rand -base64 32 > secrets/master.key
chmod 600 secrets/master.key && sudo chown 1000:1000 secrets/master.key data
cp .env.example .env     # MERIDIAN_ADMIN_USER und MERIDIAN_ADMIN_PASSWORD eintragen (mind. 8 Zeichen)
docker compose up -d --build
```

Beim ersten Start legt Meridian die Datenbank an und erstellt den Admin aus `MERIDIAN_ADMIN_USER` und
`MERIDIAN_ADMIN_PASSWORD` (oder `MERIDIAN_ADMIN_PASSWORD_FILE` für ein Docker-Secret). Danach können die
beiden Zeilen aus der `.env` entfernt werden. Erreichbar unter `http://<host>:8090` (Port über `MERIDIAN_PORT`).

Aktualisieren auf die neueste Version:

```bash
docker compose build --pull --no-cache && docker compose up -d
```

Den Schlüssel in `secrets/master.key` sicher aufbewahren: ohne ihn sind gespeicherte Geheimnisse verloren.

## Betrieb auf Linux mit systemd

```bash
sudo ./deploy/install.sh
```

Der Installer fragt nach dem Benutzernamen und dem Passwort des ersten Admins.

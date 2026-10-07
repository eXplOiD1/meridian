# Meridian

Selbst gehosteter Job-Scheduler für HTTP- und Shell-Jobs, mit Benutzern, Rollen und Rechten pro Kategorie.

> Stand: Phase 1 (Fundament) in Arbeit. Noch nicht für den Betrieb geeignet.

## Entwicklung

```bash
composer install
composer check        # PHPStan max, Psalm, Taint-Analyse, Tests, composer audit
php tests/smoke.php   # Schnelltest ohne Abhängigkeiten
```

Pre-Commit-Hook gegen eingecheckte Geheimnisse:

```bash
pipx install pre-commit && pre-commit install
```

## Betrieb mit Docker

```bash
mkdir -p data secrets
openssl rand -base64 32 > secrets/master.key
chmod 600 secrets/master.key && sudo chown 1000:1000 secrets/master.key data
docker compose build
docker compose run --rm web php bin/meridian migrate
docker compose run --rm -it web php bin/meridian user:create alex --name Alex --role Admin
docker compose up -d
```

Danach erreichbar unter `http://<host>:8089`. Den Schlüssel in `secrets/master.key` sicher aufbewahren: ohne ihn sind gespeicherte Geheimnisse verloren.

## Betrieb auf Linux mit systemd

```bash
sudo ./deploy/install.sh
```

Installiert nach `/opt/meridian`, Daten in `/var/lib/meridian`, Konfiguration und Schlüssel in `/etc/meridian`.

## Mit Claude Code weiterentwickeln

`CLAUDE.md` enthält Regeln und Arbeitsweise, `.claude/agents/` die Subagenten mit festem Modell, `TODO.md` die Aufgaben je Phase.

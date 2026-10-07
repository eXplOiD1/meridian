#!/usr/bin/env bash
# Meridian auf einem Linux-Server installieren (Debian/Ubuntu, als root).
# Voraussetzungen: PHP 8.3+ mit pdo_sqlite, sodium, pcntl; Composer; rsync; FrankenPHP unter /usr/local/bin.
set -euo pipefail

SRC="$(cd "$(dirname "$0")/../.." && pwd)"   # Repository-Root (dort liegt composer.json)
APP=/opt/meridian
DATA=/var/lib/meridian
ETC=/etc/meridian

if [[ $EUID -ne 0 ]]; then
  echo "Bitte als root ausführen." >&2
  exit 1
fi

id meridian &>/dev/null || useradd --system --home "$DATA" --shell /usr/sbin/nologin meridian

install -d -o root -g root -m 0755 "$APP"
install -d -o meridian -g meridian -m 0750 "$DATA"
install -d -o root -g meridian -m 0750 "$ETC"

rsync -a --delete --exclude vendor --exclude .git --exclude .github --exclude .claude --exclude tests "$SRC/" "$APP/"
(cd "$APP" && composer install --no-dev --no-interaction --classmap-authoritative)

if [[ ! -f "$ETC/meridian.env" ]]; then
  cat > "$ETC/meridian.env" <<'ENV'
MERIDIAN_ENV=prod
MERIDIAN_DATA_DIR=/var/lib/meridian
MERIDIAN_TIMEZONE=Europe/Berlin
ENV
  chmod 0640 "$ETC/meridian.env"
fi

# Schlüssel nur beim ersten Mal erzeugen, nie überschreiben.
if [[ ! -f "$ETC/master.key" ]]; then
  # Ohne Datenverzeichnis, damit root keine Datenbankdatei anlegt.
  MERIDIAN_DATA_DIR=/nonexistent php "$APP/Meridian/bin/meridian" key:generate "$ETC/master.key"
  chown root:root "$ETC/master.key"
  chmod 0600 "$ETC/master.key"
fi

sudo -u meridian env MERIDIAN_DATA_DIR="$DATA" php "$APP/Meridian/bin/meridian" migrate

install -m 0644 "$APP/Meridian/deploy/systemd/meridian-web.service" /etc/systemd/system/
install -m 0644 "$APP/Meridian/deploy/systemd/meridian-scheduler.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now meridian-web meridian-scheduler

echo "Meridian ist installiert und läuft."

# Ersten Admin interaktiv anlegen (nur auf der Linux-Installation; bei Docker per MERIDIAN_ADMIN_*).
if [[ -t 0 ]]; then
  read -r -p "Benutzername für den ersten Admin: " ADMIN_USER
  if [[ -n "$ADMIN_USER" ]]; then
    sudo -u meridian env MERIDIAN_DATA_DIR="$DATA" php "$APP/Meridian/bin/meridian" user:create "$ADMIN_USER" --role Admin
  fi
else
  echo "Ersten Admin anlegen:"
  echo "  sudo -u meridian env MERIDIAN_DATA_DIR=$DATA php $APP/Meridian/bin/meridian user:create <name> --role Admin"
fi

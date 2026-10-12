#!/usr/bin/env bash
# Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
# SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
# Meridian auf einem Linux-Server installieren (Debian/Ubuntu, als root).
# Voraussetzungen: PHP 8.3+ mit pdo_sqlite, sodium, pcntl, curl (Debian/Ubuntu: php-curl, ca-certificates); Composer; rsync; FrankenPHP unter /usr/local/bin.
set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"
APP=/opt/meridian
DATA=/var/lib/meridian
ETC=/etc/meridian

if [[ $EUID -ne 0 ]]; then
  echo "Bitte als root ausführen." >&2
  exit 1
fi

for command in php composer rsync sudo systemctl /usr/local/bin/frankenphp; do
  command -v "$command" >/dev/null || { echo "Voraussetzung fehlt: $command" >&2; exit 1; }
done
php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || { echo "PHP 8.3 oder neuer wird benötigt." >&2; exit 1; }
if [[ ! -f "$SRC/frontend/dist/index.html" ]]; then
  echo "Weboberfläche zuerst bauen: cd frontend && npm ci && npm run build" >&2
  exit 1
fi

id meridian &>/dev/null || useradd --system --home "$DATA" --shell /usr/sbin/nologin meridian

# Erweiterungen und CA-Bündel prüfen, bevor etwas installiert wird (der HTTP-Runner braucht curl mit TLS).
for ext in pdo_sqlite sodium pcntl posix curl; do
  if ! php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$ext"; then
    echo "PHP-Erweiterung $ext fehlt. Bitte installieren (z. B. apt install php-cli php-sqlite3 php-curl) und erneut starten." >&2
    exit 1
  fi
done
/usr/local/bin/frankenphp php-cli -r 'foreach (["pdo_sqlite", "sodium", "curl"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "FrankenPHP-Erweiterung $ext fehlt.\n"); exit(1); } }'
if [[ ! -s /etc/ssl/certs/ca-certificates.crt ]]; then
  echo "CA-Bündel /etc/ssl/certs/ca-certificates.crt fehlt. Bitte 'apt install ca-certificates' ausführen und erneut starten." >&2
  exit 1
fi

install -d -o root -g root -m 0755 "$APP"
install -d -o meridian -g meridian -m 0750 "$DATA"
install -d -o root -g meridian -m 0750 "$ETC"

# Vor Codewechsel und Migrationen auch die Worker anhalten; laufende Aufträge werden sauber beendet.
for unit in meridian-web.service meridian-scheduler.service meridian-worker-http.service meridian-worker-shell.service meridian-shell.socket; do
  if systemctl cat "$unit" &>/dev/null; then systemctl stop "$unit"; fi
done

# Besitzer root und feste Rechte, egal wie die Quelle aussieht (sonst könnte ein lokaler Benutzer den Code ersetzen,
# der später mit Zugriff auf Schlüssel und Datenbank läuft). Nur Produktcode, nie Schlüssel, Daten oder Werkzeuge.
rsync -a --delete --chown=root:root --chmod=D0755,F0644 \
  --exclude vendor --exclude .git --exclude .github --exclude .claude --exclude tests \
  --exclude .env --exclude '*.key' --exclude '*.sqlite*' --exclude data --exclude secrets --exclude node_modules \
  "$SRC/" "$APP/"
chmod 0755 "$APP/bin/meridian" "$APP/deploy/install.sh"
(cd "$APP" && composer install --no-dev --no-interaction --classmap-authoritative)
install -d -o root -g root -m 0755 "$APP/ui"
rsync -a --delete --chown=root:root --chmod=D0755,F0644 "$SRC/frontend/dist/" "$APP/ui/"

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
  MERIDIAN_DATA_DIR=/nonexistent php "$APP/bin/meridian" key:generate "$ETC/master.key"
  chown root:root "$ETC/master.key"
  chmod 0600 "$ETC/master.key"
fi

sudo -u meridian env MERIDIAN_DATA_DIR="$DATA" php "$APP/bin/meridian" migrate

# Benutzer für den Host-Agenten: ohne Zugriff auf Schlüssel und Datenbank (ADR 0014).
id meridian-run &>/dev/null || useradd --system --home /var/lib/meridian-run --shell /usr/sbin/nologin meridian-run

# /run ist nach jedem Neustart leer. Beide Pfade werden für die systemd-Isolation benötigt.
cat > /etc/tmpfiles.d/meridian.conf <<'TMPFILES'
d /run/meridian-shell 0750 root meridian -
d /run/meridian-docker 0750 root meridian -
TMPFILES
systemd-tmpfiles --create /etc/tmpfiles.d/meridian.conf

for unit in meridian-web.service meridian-scheduler.service meridian-worker-http.service meridian-worker-shell.service meridian-shell.socket meridian-shell@.service; do
  install -m 0644 "$APP/deploy/systemd/$unit" /etc/systemd/system/
done
systemctl daemon-reload
# Ohne Worker läuft kein Job: der Planer plant nur. Der HTTP-Worker stellt auch Benachrichtigungen zu.
systemctl enable meridian-web meridian-scheduler meridian-worker-http
systemctl restart meridian-web meridian-scheduler meridian-worker-http

# Shell-Jobs auf dem Host sind aus: MERIDIAN_ENABLE_SHELL=1 ./deploy/install.sh schaltet Agent und Shell-Worker ein.
# Danach den Ausführungsort „host default“ in Meridian freigeben (Einstellungen → Ausführungsorte).
if [[ "${MERIDIAN_ENABLE_SHELL:-0}" == "1" ]] || systemctl is-enabled --quiet meridian-worker-shell.service 2>/dev/null; then
  grep -q '^MERIDIAN_SHELL_HOST_SOCKETS=' "$ETC/meridian.env" || echo 'MERIDIAN_SHELL_HOST_SOCKETS=default=/run/meridian-shell/default.sock' >> "$ETC/meridian.env"
  systemctl enable meridian-shell.socket meridian-worker-shell
  systemctl restart meridian-shell.socket meridian-worker-shell
  systemctl is-active --quiet meridian-shell.socket meridian-worker-shell
else
  echo "Shell-Jobs auf dem Host sind nicht aktiviert (MERIDIAN_ENABLE_SHELL=1 beim Installieren, siehe docs/BETRIEB.md)."
fi

systemctl is-active --quiet meridian-web meridian-scheduler meridian-worker-http

echo "Meridian ist installiert und läuft."

# Ersten Admin interaktiv anlegen (nur auf der Linux-Installation; bei Docker per MERIDIAN_ADMIN_*).
if [[ -t 0 ]]; then
  read -r -p "Benutzername für den ersten Admin: " ADMIN_USER
  if [[ -n "$ADMIN_USER" ]]; then
    sudo -u meridian env MERIDIAN_DATA_DIR="$DATA" php "$APP/bin/meridian" user:create "$ADMIN_USER" --role Admin
  fi
else
  echo "Ersten Admin anlegen:"
  echo "  sudo -u meridian env MERIDIAN_DATA_DIR=$DATA php $APP/bin/meridian user:create <name> --role Admin"
fi

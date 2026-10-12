#!/usr/bin/env bash
# Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
# SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Bitte als root ausführen." >&2; exit 1; }
for tool in python3 git npm composer rsync sudo systemctl; do
  command -v "$tool" >/dev/null || { echo "Voraussetzung fehlt: $tool" >&2; exit 1; }
done
SRC="$(cd "$(dirname "$0")/.." && pwd)"
install -d -m 0755 /usr/local/lib
install -m 0644 "$SRC/deploy/updater.py" /usr/local/lib/meridian-updater.py
install -m 0644 "$SRC/deploy/systemd/meridian-updater.service" /etc/systemd/system/meridian-updater.service
install -d -m 0700 /var/lib/meridian-updater
install -d -m 0750 /etc/meridian
if [[ ! -f /etc/meridian/updater.env ]]; then
  {
    echo MERIDIAN_UPDATE_MODE=systemd
    echo "MERIDIAN_UPDATE_UID=$(id -u meridian)"
    echo "MERIDIAN_UPDATE_GID=$(id -g meridian)"
  } > /etc/meridian/updater.env
  chmod 0600 /etc/meridian/updater.env
fi
systemctl daemon-reload
systemctl enable --now meridian-updater.service
echo "Update-Dienst aktiv. Einstellungen → Updates öffnen."

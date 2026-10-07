#!/bin/sh
# Einstieg im Container. Mit MERIDIAN_AUTO_SETUP=1 (nur beim web-Dienst) werden vor dem
# Start die Migrationen eingespielt und der Admin aus MERIDIAN_ADMIN_* angelegt.
# Der Scheduler setzt das nicht, damit beide nicht gleichzeitig die Datenbank anlegen.
set -eu

if [ "${MERIDIAN_AUTO_SETUP:-0}" = "1" ]; then
  php /app/bin/meridian migrate
  php /app/bin/meridian admin:bootstrap
fi

exec "$@"

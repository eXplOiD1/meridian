#!/bin/sh
# Einstieg im Container. Mit MERIDIAN_AUTO_SETUP=1 (nur beim web-Dienst) bereitet er alles vor:
# Hauptschlüssel erzeugen (nie überschreiben), Migrationen einspielen, ersten Admin anlegen.
# Der Scheduler setzt das nicht und startet erst, wenn web gesund ist (depends_on in der compose.yaml).
set -eu

if [ "${MERIDIAN_AUTO_SETUP:-0}" = "1" ]; then
  if [ -n "${MERIDIAN_KEY_FILE:-}" ] && [ ! -e "$MERIDIAN_KEY_FILE" ]; then
    php /app/bin/meridian key:generate "$MERIDIAN_KEY_FILE"
  fi
  php /app/bin/meridian migrate
  php /app/bin/meridian admin:bootstrap
fi

exec "$@"

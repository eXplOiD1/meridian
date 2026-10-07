# syntax=docker/dockerfile:1
#
# Build-Kontext ist das Repository-Root:
#   docker build -f docker/Dockerfile .
#   docker build -f docker/Dockerfile https://github.com/eXplOiD1/meridian.git#main

# --- Abhängigkeiten -------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
# Die PHP-Extensions bringt das Laufzeit-Image mit; hier nur die Pakete laden.
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader \
    --ignore-platform-req=ext-pdo_sqlite --ignore-platform-req=ext-sodium --ignore-platform-req=ext-curl
COPY src/ src/
RUN composer dump-autoload --no-dev --classmap-authoritative \
    --ignore-platform-req=ext-pdo_sqlite --ignore-platform-req=ext-sodium --ignore-platform-req=ext-curl

# --- Oberfläche (React + Vite) --------------------------------------------
FROM node:22-alpine AS ui
WORKDIR /ui
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

# --- Laufzeit -------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4-alpine

RUN install-php-extensions pdo_sqlite sodium pcntl opcache \
 && addgroup -S -g 1000 meridian \
 && adduser -S -u 1000 -G meridian -h /var/lib/meridian meridian \
 && mkdir -p /var/lib/meridian /etc/meridian /config/caddy /data/caddy \
 && chown -R meridian:meridian /var/lib/meridian /etc/meridian /config/caddy /data/caddy \
 && chmod 0700 /etc/meridian \
 # Build-Zeit-Prüfung für den HTTP-Runner: curl-Erweiterung mit TLS und CA-Bündel müssen vorhanden sein.
 && php -m | grep -qi '^curl$' \
 && php -r 'exit(in_array("https", curl_version()["protocols"], true) ? 0 : 1);' \
 && test -s /etc/ssl/certs/ca-certificates.crt \
 # Ohne diese Datei-Capability startet FrankenPHP nicht mit cap_drop ALL + no-new-privileges
 # (exec: "Operation not permitted"). Port 8080 braucht sie nicht.
 && setcap -r /usr/local/bin/frankenphp \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'expose_php=Off\ndisplay_errors=Off\nlog_errors=On\nsession.cookie_httponly=1\nsession.cookie_secure=1\nsession.cookie_samesite=Strict\n' > "$PHP_INI_DIR/conf.d/meridian.ini"

WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY bin/ bin/
COPY migrations/ migrations/
COPY public/ public/
COPY src/ src/
# Die gebaute Oberfläche liegt außerhalb von public/: der Kernel liefert sie aus (mit allen Sicherheits-Headern).
COPY --from=ui /ui/dist /app/ui
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/meridian-entrypoint

ENV MERIDIAN_DATA_DIR=/var/lib/meridian \
    MERIDIAN_KEY_FILE=/etc/meridian/master.key \
    MERIDIAN_ENV=prod \
    SERVER_NAME=":8080" \
    SERVER_ROOT=/app/public

USER meridian
EXPOSE 8080
# Daten und Schlüssel in Volumes; Docker übernimmt dabei die Besitzer aus dem Image.
VOLUME ["/var/lib/meridian", "/etc/meridian"]

ENTRYPOINT ["meridian-entrypoint", "docker-php-entrypoint"]
# Ein eigener ENTRYPOINT verwirft das CMD des Basis-Images, deshalb hier wieder setzen.
CMD ["--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD wget -qO- http://127.0.0.1:8080/api/health || exit 1

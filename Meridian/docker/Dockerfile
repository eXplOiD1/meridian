# syntax=docker/dockerfile:1
#
# Build-Kontext ist das Repository-Root (nicht Meridian/), weil composer.json dort liegt:
#   docker build -f Meridian/docker/Dockerfile .
#   docker build -f Meridian/docker/Dockerfile https://github.com/eXplOiD1/meridian.git#main

# --- Abhängigkeiten -------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY Meridian/src/ Meridian/src/
RUN composer dump-autoload --no-dev --classmap-authoritative

# --- Laufzeit -------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4-alpine

RUN install-php-extensions pdo_sqlite sodium pcntl opcache \
 && addgroup -S -g 1000 meridian \
 && adduser -S -u 1000 -G meridian -h /var/lib/meridian meridian \
 && mkdir -p /var/lib/meridian /config/caddy /data/caddy \
 && chown -R meridian:meridian /var/lib/meridian /config/caddy /data/caddy \
 # Ohne diese Datei-Capability startet FrankenPHP nicht mit cap_drop ALL + no-new-privileges
 # (exec: "Operation not permitted"). Port 8080 braucht sie nicht.
 && setcap -r /usr/local/bin/frankenphp \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'expose_php=Off\ndisplay_errors=Off\nlog_errors=On\nsession.cookie_httponly=1\nsession.cookie_secure=1\nsession.cookie_samesite=Strict\n' > "$PHP_INI_DIR/conf.d/meridian.ini"

# Die Ordnerstruktur Meridian/ bleibt erhalten: der Composer-Autoloader verweist darauf.
WORKDIR /app/Meridian
COPY --from=vendor /app/Meridian/vendor ./vendor
COPY Meridian/bin/ bin/
COPY Meridian/migrations/ migrations/
COPY Meridian/public/ public/
COPY Meridian/src/ src/
COPY --chmod=0755 Meridian/docker/entrypoint.sh /usr/local/bin/meridian-entrypoint

ENV MERIDIAN_DATA_DIR=/var/lib/meridian \
    MERIDIAN_ENV=prod \
    SERVER_NAME=":8080" \
    SERVER_ROOT=/app/Meridian/public

USER meridian
EXPOSE 8080
VOLUME ["/var/lib/meridian"]

ENTRYPOINT ["meridian-entrypoint", "docker-php-entrypoint"]
# CMD kommt unverändert aus dem FrankenPHP-Image.

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD wget -qO- http://127.0.0.1:8080/api/health || exit 1

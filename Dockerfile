# syntax=docker/dockerfile:1
FROM dunglas/frankenphp:1-php8.4 AS dev

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip acl file gettext \
    && rm -rf /var/lib/apt/lists/*

# pdo_sqlite ships with the official PHP image.
RUN install-php-extensions \
        @composer \
        apcu \
        intl \
        opcache \
        zip \
        pdo_pgsql \
        bcmath

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=dev \
    PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

WORKDIR /srv
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY docker/php/app.ini $PHP_INI_DIR/app.conf.d/10-app.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/xray-entrypoint

ENTRYPOINT ["sh", "/usr/local/bin/xray-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# PHP-FPM с pdo_pgsql. Этот же образ крутит планировщик синхронизации.
FROM php:8.3-fpm-alpine

RUN apk add --no-cache libpq \
 && apk add --no-cache --virtual .build-deps postgresql-dev \
 && docker-php-ext-install pdo_pgsql \
 && apk del .build-deps \
 && php -m | grep -qi sodium

WORKDIR /app
COPY . /app
RUN mkdir -p /app/var/log && chown -R www-data:www-data /app/var

ENV APP_CONFIG=/app/deploy/config.docker.php

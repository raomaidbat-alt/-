#!/bin/sh
# Первичная настройка на сервере: создаёт .env со случайными секретами и config.local.php.
# Повторный запуск ничего не перезаписывает.
set -eu
cd "$(dirname "$0")/.."

rand() { head -c "$1" /dev/urandom | base64 | tr -d '\n=+/' | cut -c1-"$2"; }

if [ ! -f .env ]; then
  printf "Домен дашборда (например, stats.ufcstudio.ru): "
  read -r DOMAIN
  printf "URL входящего вебхука Bitrix24 (https://...bitrix24.ru/rest/<id>/<token>/): "
  read -r WEBHOOK
  cat > .env <<ENV
DOMAIN=${DOMAIN}
B24_WEBHOOK_URL=${WEBHOOK}
API_TOKEN=$(rand 48 40)
APP_ENCRYPTION_KEY=$(head -c 32 /dev/urandom | base64 | tr -d '\n')
POSTGRES_PASSWORD=$(rand 48 32)
APP_TIMEZONE=Europe/Moscow
ENV
  chmod 600 .env
  echo ".env создан. Токен для входа в дашборд: $(grep '^API_TOKEN=' .env | cut -d= -f2)"
else
  echo ".env уже есть, не трогаю"
fi

if [ ! -f config.local.php ]; then
  cp deploy/config.local.example.php config.local.php
  echo "config.local.php создан из примера"
fi

#!/bin/sh
# Планировщик внутри контейнера: миграции, первая выгрузка, потом цикл раз в 2 часа.
# Каждый 84-й запуск (раз в 7 дней) полный: подтягивает пропущенное и помечает удалённые лиды.
set -u
cd /app

until php bin/install.php; do
  echo "DB is not ready yet, retry in 5s"
  sleep 5
done

INTERVAL="${SYNC_INTERVAL_SECONDS:-7200}"
n=0
while true; do
  if [ "$n" -gt 0 ] && [ $((n % 84)) -eq 0 ]; then
    php bin/sync_leads.php --full
  else
    php bin/sync_leads.php
  fi
  n=$((n + 1))
  sleep "$INTERVAL"
done

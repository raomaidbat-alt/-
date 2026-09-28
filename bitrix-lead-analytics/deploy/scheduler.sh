#!/bin/sh
# Планировщик внутри контейнера: миграции, первая выгрузка, потом цикл с интервалом
# SYNC_INTERVAL_SECONDS (по умолчанию 900 = 15 минут).
# Раз в 7 дней запуск полный: подтягивает пропущенное и помечает удалённые лиды.
set -u
cd /app

until php bin/install.php; do
  echo "DB is not ready yet, retry in 5s"
  sleep 5
done

INTERVAL="${SYNC_INTERVAL_SECONDS:-900}"
FULL_EVERY=604800   # 7 дней в секундах
last_full=$(date +%s)
while true; do
  started=$(date +%s)
  if [ $((started - last_full)) -ge "$FULL_EVERY" ]; then
    php bin/sync_leads.php --full && last_full=$started
  else
    php bin/sync_leads.php
  fi
  # Следующий запуск ровно через INTERVAL от начала этого, а не от его конца.
  elapsed=$(( $(date +%s) - started ))
  pause=$(( INTERVAL - elapsed ))
  [ "$pause" -lt 30 ] && pause=30
  sleep "$pause"
done

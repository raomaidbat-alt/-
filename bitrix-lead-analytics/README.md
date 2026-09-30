# Bitrix24 Lead Analytics

Выгрузка лидов из Bitrix24 каждые 2 часа, история стадий в отдельной таблице и маркетинговый дашборд воронки и каналов.

```
Bitrix24 REST (вебхук)
        │  crm.lead.list / crm.status.list / batch
        ▼
bin/sync_leads.php  ── cron 0 */2 * * *
        │  upsert                    append-only
        ▼                            ▼
leads_current  ◄──────────────►  leads_snapshots   sync_runs
statuses_directory, sources_directory
        │
        ▼
public/api/events.php     сырые события для React-дашборда
public/api/analytics.php  готовые агрегаты (KPI, воронка, скорость стадий, таймлайн)
        │
        ▼
public/index.html  ← собранный dashboard/src/MarketingFunnelDashboard.tsx
```

Нужно: PHP 8.1+ с `pdo_mysql` или `pdo_pgsql`, `curl`, `json`, `mbstring`; MySQL 8.0.19+ или PostgreSQL 14+. Composer не нужен. Node.js нужен только для пересборки дашборда.

## Структура

| Путь | Что там |
|---|---|
| `config.example.php` | шаблон конфига: вебхук, БД, токен API, каналы, пояс |
| `bin/inspect_fields.php` | этап 1: разведка полей лида, стадий и источников портала |
| `bin/install.php` | этап 2: создаёт таблицы (MySQL или PostgreSQL по DSN) |
| `sql/mysql/001_schema.sql`, `sql/pgsql/001_schema.sql` | DDL |
| `bin/sync_leads.php` | этап 3: ETL для cron |
| `bin/generate_key.php` | ключ шифрования для пользовательских полей |
| `src/Crypto.php` | шифрование libsodium |
| `src/Bitrix/Client.php` | клиент REST: троттлинг, batch, ретраи с backoff |
| `src/Sync/*` | маппинг лида, справочники, запись текущего состояния и снапшотов |
| `public/api/analytics.php` | этап 4: агрегаты |
| `public/api/events.php` | лента событий для дашборда |
| `dashboard/src/MarketingFunnelDashboard.tsx` | этап 5: дашборд, один файл (React + Tailwind + Recharts + Lucide) |
| `public/index.html`, `public/assets/` | собранный дашборд, готов к выкладке без Node |
| `tests/mock-bitrix/` | мок-портал Bitrix24 для локальной проверки |

## Где разместить

| Вариант | Когда подходит | Инструкция |
|---|---|---|
| Хостинг reg.ru (или Beget, Timeweb) | есть тариф хостинга с PHP и MySQL, не хочется администрировать сервер | [`deploy/REGRU.md`](deploy/REGRU.md) |
| Свой сервер с Docker | уже есть VPS | `sh deploy/init.sh`, затем `docker compose up -d` (PostgreSQL, PHP-FPM, планировщик и Caddy с автоматическим HTTPS) |

## Установка

```bash
cp config.example.php config.php         # заполнить: webhook_url, db, api.token, channels
php bin/generate_key.php                 # ключ шифрования → APP_ENCRYPTION_KEY (нужен, если задан sync.custom_fields)
php bin/inspect_fields.php               # проверить доступ и посмотреть ID стадий/источников
php bin/install.php                      # создать таблицы
php bin/sync_leads.php                   # первый запуск сам станет полной выгрузкой
```

Вебхук: в Bitrix24 «Разработчикам → Другое → Входящий вебхук», право **CRM**. Хватает прав на чтение. URL вебхука секретный: держите его в `config.php` или в переменной окружения `B24_WEBHOOK_URL`, в git он попадать не должен (`config.php` уже в `.gitignore`).

Веб-сервер должен смотреть в `public/` и работать по HTTPS. Всё остальное (`config.php`, `src/`, `var/`) снаружи недоступно.

### Cron

```cron
# инкремент каждые 15 минут
*/15 * * * *  php /srv/b24-analytics/bin/sync_leads.php >> /srv/b24-analytics/var/log/cron.log 2>&1
# раз в неделю полная сверка: подтягивает пропущенное и помечает удалённые лиды
30 3 * * 0   php /srv/b24-analytics/bin/sync_leads.php --full >> /srv/b24-analytics/var/log/cron.log 2>&1
```

Коды выхода: `0` успех, `1` ошибка (подробности в `var/log/sync-YYYY-MM.log`), `2` уже идёт другой запуск (lock-файл).

## Как работает синхронизация

**Инкремент.** Берутся лиды с `DATE_MODIFY >= старт прошлого успешного запуска − 15 минут`. Перекрытие страхует от лидов, изменённых во время прошлой выгрузки. Если успешных запусков ещё не было, запуск будет полным.

**Пагинация.** Первый `crm.lead.list` узнаёт `total`, остальные страницы идут через `batch` по 50 команд. Один HTTP-запрос приносит до 2500 лидов. Сортировка строго по `ID`, дубли, появившиеся из-за сдвига страниц, отбрасываются.

**Лимиты и сбои.** Между запросами выдерживается `min_interval_ms` (портал держит около 2 запросов в секунду). На `429`, `5xx`, `QUERY_LIMIT_EXCEEDED`, `OPERATION_TIME_LIMIT` и сетевые ошибки cURL идут повторы с экспоненциальной задержкой (1, 2, 4, 8 с… плюс джиттер, не больше `max_delay_ms`), `Retry-After` учитывается. Ошибки авторизации (`401`, `INVALID_CREDENTIALS`) не повторяются: вебхук надо чинить руками.

**Транзакция.** Весь запуск идёт одной транзакцией. Упавший синк не оставляет в базе половину данных, в `sync_runs` пишется `failed` с текстом ошибки, а следующий запуск начнёт с того же водяного знака.

**Снапшоты.** Для каждого лида считается SHA-256 от значимых полей: стадия, сумма, валюта, ответственный, источник, UTM. Строка в `leads_snapshots` появляется, только если хеш изменился, лид новый или вернулся после удаления. `change_type`: `created`, `status`, `update`, `deleted`. При смене стадии пишется `prev_status_id` и `seconds_in_prev_stage`. Время входа в стадию берётся из `MOVED_TIME`, если портал его отдаёт, иначе это момент синхронизации (точность 2 часа). UPDATE и DELETE в журнале запрещены триггером.

**Удалённые лиды.** Инкремент удаления не видит, поэтому раз в неделю нужен `--full`. Лиды, которых нет в полной выгрузке, получают `is_deleted = 1` и снапшот `deleted`.

**Что не хранится.** Персональные данные лида не запрашиваются и не хранятся, подробности в разделе ниже.

## Персональные данные и шифрование

**Что не уходит из Bitrix24.** Синк запрашивает у портала короткий белый список полей: ID, стадия, сумма, валюта, даты, источник и три UTM-метки (source, medium, campaign). ID сотрудников (ответственный, автор) и `utm_content`/`utm_term` не запрашиваются. Имя, фамилия, название лида (там часто стоит имя клиента), телефоны, e-mail, мессенджеры, адрес, компания, комментарии и «Дополнительно об источнике» не запрашиваются. Если портал всё же пришлёт лишнее, маппер возьмёт только поля из белого списка.

**Пользовательские поля.** По умолчанию `UF_CRM_*` не выгружаются. Нужные аналитике (например, город или бюджет) перечисляются в `sync.custom_fields` и хранятся в `leads_current.custom_fields_enc` в зашифрованном виде. Поля с персональными данными (`NAME`, `PHONE`, `EMAIL`, `TITLE` и другие из `LeadMapper::FORBIDDEN`) из этого списка вырезаются автоматически, в лог пишется предупреждение.

**Шифрование хранения.** libsodium secretbox (XSalsa20-Poly1305), ключ 32 байта:

```bash
php bin/generate_key.php            # напечатает ключ
export APP_ENCRYPTION_KEY='…'       # храните в окружении/секретах сервера, не в git и не в базе
```

Без ключа зашифрованные поля из дампа базы не прочитать, подменённые данные не расшифруются. Если ключ сменили, запустите `php bin/sync_leads.php --full`: поля перезапишутся новым ключом.

**Шифрование при передаче.**
- Bitrix24: только `https://` с проверкой сертификата и TLS 1.2+; `http://` вебхук отклоняется (исключение `allow_insecure_http` нужно только мок-порталу в тестах).
- API дашборда: запросы не по HTTPS получают `403 https_required` (кроме запросов с этой же машины), ответы идут с HSTS. За прокси учитывается `X-Forwarded-Proto`.
- База: для MySQL укажите `db.ssl_ca`, для PostgreSQL добавьте в DSN `;sslmode=verify-full;sslrootcert=/path/ca.pem`.

**Что видит дашборд.** В `api/events.php` и `api/analytics.php` нет ни одного персонального поля: только ID лида, канал, этапы, даты и суммы. В логах только счётчики и тексты ошибок API.

**Как проверялось.** Мок-портал отдавал имя, телефон, e-mail и ник в каждом лиде и вообще игнорировал список полей (`MOCK_IGNORE_SELECT=1`). После 37 синхронизаций в полном дампе базы, логах и ответах API не нашлось ни одного из этих значений; город из разрешённого поля лежал только в зашифрованном виде.

## Дашборд

Открывается по адресу `public/`. При первом входе спросит токен из `api.token`, кнопка «Посмотреть демо» или `?demo=1` показывает моковые данные без API.

- **Период:** 7 дней, 30 дней, квартал, всё время. Дельты считаются к такому же предыдущему периоду.
- **Канал:** «Все каналы» или один из `channels` в конфиге.
- **KPI:** всего лидов, сквозная конверсия (когорта лидов периода, дошедших до оплаты), SQL и C2, выручка и средний чек (оплаты с датой в периоде).
- **Воронка:** охват → лид → SQL → консультация → оплата. Для каждого шага Step CR, Total CR, потери и медиана времени от входа (по снапшотам).
- **Каналы:** лиды, C1 (охват → лид), выручка, ROMI; сортировка по клику на заголовок.
- **Динамика:** новые лиды против оплат по дням, для длинных периодов по неделям.

Как шаги воронки сопоставляются со стадиями Bitrix24:

| Шаг | Условие |
|---|---|
| Лид | любой лид, созданный в периоде |
| SQL | лид ушёл дальше первой рабочей стадии (брак не считается) |
| Консультация | лид дошёл до стадии `funnel.consultation_status` или дальше |
| Оплата | стадия с семантикой «успех» (обычно `CONVERTED`) |

Охвата и рекламных расходов в Bitrix24 нет. Их задают в конфиге (`reach_per_month`, `spend_per_month` у каждого канала). Если не задать, шаг «Охват» покажет «нет данных», а ROMI будет прочерком.

### Каналы

Лид попадает в канал по `SOURCE_ID` (список `sources`), а если источник ни за кем не закреплён, то по `utm_source` (список `utm_sources`, регистр не важен). Всё остальное уходит в «Другие источники». ID источников портала печатает `inspect_fields.php`. Например, чтобы лиды Telegram-бота шли в канал Telegram, добавьте ID его источника в `channels.telegram.sources`.

### Компонент отдельно

`dashboard/src/MarketingFunnelDashboard.tsx` не зависит от остального проекта. Контракт данных описан в начале файла (`DashboardData`), без `apiUrl` компонент работает на моках:

```tsx
import MarketingFunnelDashboard from "./MarketingFunnelDashboard";

<MarketingFunnelDashboard />                                          // демо
<MarketingFunnelDashboard apiUrl="/api/events.php" apiToken={token} /> // живые данные
<MarketingFunnelDashboard initialData={myData} />                      // свои данные (Supabase, n8n…)
```

Пересборка:

```bash
cd dashboard
npm install
npm run dev     # http://localhost:5173, /api проксируется на php -S 127.0.0.1:8098 -t public
npm run build   # кладёт index.html и assets/ в ../public
```

## Расходы и выручка каналов

Блок «Расходы и окупаемость» показывает по каждому каналу: расход, лиды, цену лида, продажи, цену продажи, выручку, прибыль и ROMI.

**Выручка.** Если в Bitrix24 из лидов создаются сделки, синк подтягивает их (`crm.deal.list`, только ID, связь с лидом, стадия, сумма и даты) и выручкой канала считается сумма **выигранных сделок**, датой продажи дата выигрыша. Если сделок из лидов нет, выручка берётся из суммы лида в успешной стадии. Отключить сделки: `SYNC_DEALS=false`.

**Расход.** Два источника:
- бюджет в настройках канала `spend_per_month` в `config.local.php` (делится на дни);
- записи из дашборда: кнопка «Добавить расход», за месяц, день или период. Если по каналу есть хоть одна запись, бюджет из настроек для него не используется, считаются только записи.

Записи хранятся в таблице `channel_spend`, API: `GET/POST/DELETE api/spend.php` с тем же токеном.

## API

Оба эндпоинта: `GET`, заголовок `Authorization: Bearer <api.token>` (или `X-Api-Token`).

`api/events.php?period=7d|30d|quarter|all`: `DashboardData` для компонента, лиды за два периода подряд (текущий и предыдущий).

`api/analytics.php`: готовые агрегаты.

| Параметр | Значения |
|---|---|
| `period` | `24h`, `7d`, `30d`, `90d`, `quarter`, `all`, `custom` |
| `from`, `to` | `YYYY-MM-DD` для `custom` (не больше 400 дней) |
| `sources` | `SOURCE_ID` через запятую, `_none` = без источника |
| `utm_source` | значение или `_none` |
| `channel` | ключ канала из конфига или `other` |

Ответ: `kpi`, `previous`, `channels`, `marketing_funnel`, `sources`, `utm_sources`, `utm_campaigns`, `funnel` (все стадии Bitrix24 и брак), `velocity` (среднее время между стадиями и на стадии по снапшотам), `timeline` (переходы по каждому 2-часовому циклу), `daily`, `meta.sync`.

## Проверка на мок-портале

```bash
php -S 127.0.0.1:8099 tests/mock-bitrix/router.php &            # MOCK_FAIL_RATE=0.15 даст 15% ответов 503
# в config.php: webhook_url = http://127.0.0.1:8099/rest/1/testtoken/, bitrix.allow_insecure_http = true
php bin/install.php
php tests/mock-bitrix/advance.php --seed=700 --start="-45 days" --end="-3 days"
SYNC_NOW="$(php tests/mock-bitrix/advance.php --now)" php bin/sync_leads.php
for i in $(seq 1 36); do SYNC_NOW="$(php tests/mock-bitrix/advance.php --hours=2)" php bin/sync_leads.php; done
php -S 127.0.0.1:8098 -t public                                  # дашборд + API
```

`SYNC_NOW` подменяет текущее время только для тестов, чтобы прогнать 3 дня двухчасовых циклов за минуту.

## Диагностика

| Симптом | Что проверить |
|---|---|
| `INVALID_CREDENTIALS`, `401` | вебхук удалён или перевыпущен, обновите `webhook_url` |
| `insufficient_scope`, `ACCESS_DENIED` | у вебхука нет права CRM |
| в логе много `retrying` c `QUERY_LIMIT_EXCEEDED` | увеличьте `min_interval_ms`, не запускайте другие интеграции в ту же минуту |
| запуск `failed` с `OPERATION_TIME_LIMIT` | портал исчерпал лимит времени на метод; уменьшите `batch_commands` (например, до 20) |
| exit code `2` | предыдущий запуск ещё идёт или завис; проверьте `var/sync.lock` и процессы |
| на дашборде «Данные устарели» | последний успешный синк старше 2,5 часов: смотрите `sync_runs` и `var/log` |
| `Ошибка синхронизации` в шапке | последний запуск `failed`, текст ошибки в `sync_runs.error_message` |
| у старых лидов нет «медианы до шага» | история стадий до первой синхронизации неизвестна, она копится с момента установки |

Полезные запросы:

```sql
SELECT id, mode, status, started_at, leads_fetched, snapshots_written, api_calls, error_message
  FROM sync_runs ORDER BY id DESC LIMIT 20;

SELECT prev_status_id, status_id, COUNT(*), AVG(seconds_in_prev_stage) / 3600 AS avg_hours
  FROM leads_snapshots WHERE change_type = 'status' GROUP BY 1, 2;
```

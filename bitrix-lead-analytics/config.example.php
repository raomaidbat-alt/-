<?php
/**
 * Скопируйте в config.php и заполните. Любое значение можно переопределить
 * переменной окружения (удобно для cron и Docker).
 */
declare(strict_types=1);

$env = static fn (string $key, $default = null) => (($v = getenv($key)) !== false && $v !== '') ? $v : $default;

return [
    'bitrix' => [
        // Входящий вебхук: Разработчикам → Другое → Входящий вебхук, права: CRM (crm).
        'webhook_url'     => $env('B24_WEBHOOK_URL', 'https://your-portal.bitrix24.ru/rest/1/xxxxxxxxxxxxxxxx/'),
        'timeout'         => (int) $env('B24_TIMEOUT', 60),         // секунд на весь запрос
        'connect_timeout' => (int) $env('B24_CONNECT_TIMEOUT', 10),
        'max_retries'     => (int) $env('B24_MAX_RETRIES', 6),      // повторов на один запрос
        'base_delay_ms'   => (int) $env('B24_BASE_DELAY_MS', 1000), // 1s, 2s, 4s, 8s ... + джиттер
        'max_delay_ms'    => (int) $env('B24_MAX_DELAY_MS', 60000),
        'min_interval_ms' => (int) $env('B24_MIN_INTERVAL_MS', 550),// лимит Bitrix24: ~2 запроса/сек
        'batch_commands'  => (int) $env('B24_BATCH_COMMANDS', 50),  // команд в одном batch (максимум 50)
        // Только для мок-портала в тестах. В бою вебхук всегда https://.
        'allow_insecure_http' => false,
    ],

    'db' => [
        // MySQL:      mysql:host=127.0.0.1;port=3306;dbname=b24_analytics;charset=utf8mb4
        // PostgreSQL: pgsql:host=127.0.0.1;port=5432;dbname=b24_analytics
        'dsn'      => $env('DB_DSN', 'mysql:host=127.0.0.1;port=3306;dbname=b24_analytics;charset=utf8mb4'),
        'user'     => $env('DB_USER', 'b24'),
        'password' => $env('DB_PASSWORD', ''),
        // TLS до MySQL: путь к CA-сертификату сервера. Для PostgreSQL добавьте в DSN
        // ";sslmode=verify-full;sslrootcert=/path/ca.pem".
        'ssl_ca'   => $env('DB_SSL_CA', null),
    ],

    'sync' => [
        // Инкремент берёт лиды с DATE_MODIFY >= (старт прошлого успешного запуска − overlap).
        'overlap_minutes' => (int) $env('SYNC_OVERLAP_MINUTES', 15),
        'lock_file'       => $env('SYNC_LOCK_FILE', __DIR__ . '/var/sync.lock'),
        // Глубина истории: лиды, созданные за последние N дней. 0 = все лиды портала.
        // 60 дней покрывают период "30 дней" и сравнение с предыдущими 30 днями.
        'history_days'    => (int) $env('SYNC_HISTORY_DAYS', 0),
        // Пользовательские поля лида, которые нужны аналитике (коды из bin/inspect_fields.php).
        // По умолчанию пусто: UF_CRM_* не выгружаются. Выбранные хранятся зашифрованными.
        // Поля с именем, телефоном, e-mail и т.п. сюда не добавляйте.
        'custom_fields'   => [],
    ],

    'security' => [
        // Ключ шифрования (32 байта, base64): php bin/generate_key.php.
        // Храните в переменной окружения, не в git и не в базе.
        'encryption_key' => $env('APP_ENCRYPTION_KEY', ''),
    ],

    'api' => [
        // Токен для api/analytics.php. Пустая строка отключает проверку
        // (только если доступ уже закрыт на уровне веб-сервера).
        'token'          => $env('API_TOKEN', 'change-me-to-a-long-random-string'),
        // Разрешённый Origin для CORS, если дашборд лежит на другом домене. null = только same-origin.
        'allowed_origin' => $env('API_ALLOWED_ORIGIN', null),
        // Отказывать запросам не по HTTPS (кроме запросов с 127.0.0.1 / ::1).
        'require_https'  => true,
    ],

    /*
     * Маркетинговые каналы дашборда. Лид относится к каналу, если его SOURCE_ID есть в sources,
     * иначе если utm_source (без учёта регистра) есть в utm_sources. Остальное попадает в "Другие".
     * ID источников смотрите в выводе bin/inspect_fields.php.
     * spend_per_month нужен для ROMI, reach_per_month для первого шага воронки (охват):
     * в Bitrix24 этих данных нет, их задают вручную или подтягивают из рекламных кабинетов.
     */
    'channels' => [
        'reels' => [
            'label' => 'Reels / Органика',
            'sources' => ['WEB'],
            'utm_sources' => ['instagram', 'reels', 'google', 'yandex'],
            'spend_per_month' => 60000,
            'reach_per_month' => 120000,
        ],
        'telegram' => [
            'label' => 'Telegram-канал',
            'sources' => ['UC_TELEGRAM'],          // например, ID источника "Заявки из чатов (ТГ Бот)"
            'utm_sources' => ['telegram', 'tg'],
            'spend_per_month' => 40000,
            'reach_per_month' => 45000,
        ],
        'base' => [
            'label' => 'База / Рассылки',
            'sources' => ['EMAIL', 'CALL'],
            'utm_sources' => ['sendpulse', 'email', 'unisender'],
            'spend_per_month' => 8000,
            'reach_per_month' => 15000,
        ],
        'partners' => [
            'label' => 'Партнеры / Инвайтинг',
            'sources' => ['PARTNER', 'RECOMMENDATION'],
            'utm_sources' => ['partner'],
            'spend_per_month' => 25000,
            'reach_per_month' => 10000,
        ],
    ],

    'funnel' => [
        // Стадия лида, начиная с которой считаем "консультация / демо / КП" (шаг 4 воронки).
        'consultation_status' => 'UC_MEETING',
    ],

    'app' => [
        'timezone' => $env('APP_TIMEZONE', 'Europe/Moscow'), // для пресетов периода и группировки по дням
        'log_dir'  => $env('APP_LOG_DIR', __DIR__ . '/var/log'),
        'log_level'=> $env('APP_LOG_LEVEL', 'info'),        // debug | info | warning | error
    ],
];

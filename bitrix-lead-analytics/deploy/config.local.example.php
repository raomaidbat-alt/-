<?php
/**
 * Настройки аналитики под ваш портал. Секреты сюда не пишите, они в .env.
 * После правки: docker compose restart app scheduler
 * ID источников и стадий: docker compose exec app php bin/inspect_fields.php
 */
return [
    'channels' => [
        'reels' => [
            'label' => 'Reels / Органика',
            'sources' => ['WEB'],
            'utm_sources' => ['instagram', 'reels'],
            'spend_per_month' => 0,
            'reach_per_month' => 0,
        ],
        'telegram' => [
            'label' => 'Telegram-канал',
            'sources' => ['64'],                // "Заявки из чатов (ТГ Бот)"
            'utm_sources' => ['telegram', 'tg'],
            'spend_per_month' => 0,
            'reach_per_month' => 0,
        ],
        'base' => [
            'label' => 'База / Рассылки',
            'sources' => ['EMAIL'],
            'utm_sources' => ['email', 'sendpulse', 'unisender'],
            'spend_per_month' => 0,
            'reach_per_month' => 0,
        ],
        'partners' => [
            'label' => 'Партнеры / Инвайтинг',
            'sources' => ['PARTNER', 'RECOMMENDATION'],
            'utm_sources' => ['partner'],
            'spend_per_month' => 0,
            'reach_per_month' => 0,
        ],
    ],
    'funnel' => [
        // Стадия "консультация / демо / КП": поменяйте на ID своей стадии после inspect_fields.
        'consultation_status' => 'IN_PROCESS',
    ],
    'sync' => [
        // Нужные аналитике UF_CRM_* (хранятся зашифрованными). Персональные данные сюда не добавлять.
        'custom_fields' => [],
    ],
];

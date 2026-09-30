<?php
/**
 * Настройки аналитики под ваш портал. Секреты сюда не пишите, они в .env.
 * После правки: docker compose restart app scheduler
 * ID источников и стадий: docker compose exec app php bin/inspect_fields.php
 */
return [
    /*
     * Каналы = группы источников лидов. source_names: названия источников точно как в Bitrix24
     * (регистр не важен), можно несколько на один канал. sources: то же по ID, если удобнее.
     * spend_per_month: постоянный расход в месяц (для ROMI). Если для канала внести расходы в дашборде
 *   (кнопка «Добавить расход»), они заменят это значение. reach_per_month: охват в месяц (0 = не считать).
     * icon: send, mail, bot, users, briefcase, megaphone, handshake, clapperboard, target, layers.
     */
    'channels' => [
        'email_marketer' => [
            'label' => 'E-mail рассылка от маркетолога',
            'source_names' => ['E-mail рассылка от маркетолога'],
            'icon' => 'mail',
            'spend_per_month' => 0,
            'reach_per_month' => 0,
        ],
        'coldy' => [
            'label' => 'Рассылка Coldy',
            'source_names' => ['Рассылка Coldy'],
            'icon' => 'megaphone',
            'spend_per_month' => 10000,  // Coldy
            'reach_per_month' => 0,
        ],
        'lead_harvester' => [
            // Профи.ру: лиды приходят с двумя источниками, считаем их одним каналом.
            'label' => 'Профи.ру (Lead Harvester)',
            'source_names' => ['Lead Harvester', 'Профи'],
            'icon' => 'target',
            'spend_per_month' => 11500,  // подписка Профи.ру
            'reach_per_month' => 0,
        ],
        'tg_bot' => [
            'label' => 'Заявки из чатов (ТГ Бот)',
            'source_names' => ['Заявки из чатов (ТГ Бот)'],
            'sources' => ['64'],
            'icon' => 'bot',
            'spend_per_month' => 1500,  // ТГ-бот
            'reach_per_month' => 0,
        ],
    ],
    'funnel' => [
        // Стадия "консультация / демо / КП": поменяйте на ID своей стадии после inspect_fields.
        'consultation_status' => 'IN_PROCESS',
        // Квал. лид: прошёл первый звонок и попал на эту стадию (код или название). Пусто: ищем стадию со словом "аудит".
        'qualified_status' => '',
    ],
    'sync' => [
        // Нужные аналитике UF_CRM_* (хранятся зашифрованными). Персональные данные сюда не добавлять.
        'custom_fields' => [],
        // Поле "Причина отказа" (код UF_CRM_..., см. inspect_fields.php), если оно есть в карточке лида.
        'loss_reason_field' => '',
    ],
];

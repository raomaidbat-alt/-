<?php
declare(strict_types=1);

/**
 * Состояние мок-портала: JSON-файл с лидами и "текущим временем" портала.
 * Время двигает advance.php, чтобы имитировать двухчасовые циклы.
 */

const MOCK_TZ = 'Europe/Moscow';

function mock_state_file(): string
{
    return getenv('MOCK_STATE') ?: sys_get_temp_dir() . '/b24-mock-state.json';
}

function mock_load(): array
{
    $f = mock_state_file();
    if (!is_file($f)) {
        return ['now' => gmdate('c'), 'next_id' => 1, 'leads' => []];
    }
    return json_decode((string) file_get_contents($f), true);
}

function mock_save(array $state): void
{
    file_put_contents(mock_state_file(), json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function mock_iso(int $ts): string
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(MOCK_TZ))->format('Y-m-d\TH:i:sP');
}

/** Стадии: как в стандартном портале плюс одна пользовательская ("Встреча назначена"). */
function mock_stages(): array
{
    return [
        ['STATUS_ID' => 'NEW',        'NAME' => 'Не обработан',        'SORT' => 10, 'COLOR' => '#39A8EF', 'SEMANTICS' => null],
        ['STATUS_ID' => 'IN_PROCESS', 'NAME' => 'В работе',            'SORT' => 20, 'COLOR' => '#2FC6F6', 'SEMANTICS' => null],
        ['STATUS_ID' => 'UC_MEETING', 'NAME' => 'Встреча назначена',   'SORT' => 30, 'COLOR' => '#55D0E0', 'SEMANTICS' => null],
        ['STATUS_ID' => 'PROCESSED',  'NAME' => 'Обработан',           'SORT' => 40, 'COLOR' => '#47E4C2', 'SEMANTICS' => null],
        ['STATUS_ID' => 'CONVERTED',  'NAME' => 'Качественный лид',    'SORT' => 50, 'COLOR' => '#7BD500', 'SEMANTICS' => 'S'],
        ['STATUS_ID' => 'JUNK',       'NAME' => 'Некачественный лид',  'SORT' => 60, 'COLOR' => '#FF5752', 'SEMANTICS' => 'F'],
    ];
}

function mock_sources(): array
{
    return [
        ['STATUS_ID' => 'CALL',           'NAME' => 'Звонок',              'SORT' => 10],
        ['STATUS_ID' => 'WEB',            'NAME' => 'Веб-сайт',            'SORT' => 20],
        ['STATUS_ID' => 'ADVERTISING',    'NAME' => 'Реклама',             'SORT' => 30],
        ['STATUS_ID' => 'EMAIL',          'NAME' => 'Электронная почта',   'SORT' => 40],
        ['STATUS_ID' => 'RECOMMENDATION', 'NAME' => 'По рекомендации',     'SORT' => 50],
        ['STATUS_ID' => 'UC_TELEGRAM',    'NAME' => 'Telegram-бот',        'SORT' => 60],
        ['STATUS_ID' => 'OTHER',          'NAME' => 'Другое',              'SORT' => 70],
    ];
}

function mock_directories(): array
{
    $out = [];
    $id = 1;
    foreach (mock_stages() as $s) {
        $sem = $s['SEMANTICS'] === 'S' ? 'success' : ($s['SEMANTICS'] === 'F' ? 'failure' : 'process');
        $out[] = $s + ['ID' => (string) $id++, 'ENTITY_ID' => 'STATUS', 'NAME_INIT' => $s['NAME'], 'SYSTEM' => 'Y',
            'CATEGORY_ID' => null, 'EXTRA' => ['SEMANTICS' => $sem, 'COLOR' => $s['COLOR']]];
    }
    foreach (mock_sources() as $s) {
        $out[] = $s + ['ID' => (string) $id++, 'ENTITY_ID' => 'SOURCE', 'NAME_INIT' => $s['NAME'], 'SYSTEM' => 'N',
            'COLOR' => null, 'SEMANTICS' => null, 'CATEGORY_ID' => null];
    }
    return $out;
}

function mock_fields(): array
{
    $f = static fn (string $type, string $title, array $extra = []) => array_merge([
        'type' => $type, 'isRequired' => false, 'isReadOnly' => false, 'isImmutable' => false,
        'isMultiple' => false, 'isDynamic' => false, 'title' => $title,
    ], $extra);
    return [
        'ID' => $f('integer', 'ID', ['isReadOnly' => true]),
        'TITLE' => $f('string', 'Название лида'),
        'STATUS_ID' => $f('crm_status', 'Стадия', ['statusType' => 'STATUS']),
        'OPPORTUNITY' => $f('double', 'Сумма'),
        'CURRENCY_ID' => $f('crm_currency', 'Валюта'),
        'DATE_CREATE' => $f('datetime', 'Дата создания', ['isReadOnly' => true]),
        'DATE_MODIFY' => $f('datetime', 'Дата изменения', ['isReadOnly' => true]),
        'MOVED_TIME' => $f('datetime', 'Дата перемещения', ['isReadOnly' => true]),
        'SOURCE_ID' => $f('crm_status', 'Источник', ['statusType' => 'SOURCE']),
        'SOURCE_DESCRIPTION' => $f('string', 'Дополнительно об источнике'),
        'ASSIGNED_BY_ID' => $f('user', 'Ответственный'),
        'UTM_SOURCE' => $f('string', 'Рекламная система'),
        'UTM_MEDIUM' => $f('string', 'Тип трафика'),
        'UTM_CAMPAIGN' => $f('string', 'Обозначение рекламной кампании'),
        'UTM_CONTENT' => $f('string', 'Содержание кампании'),
        'UTM_TERM' => $f('string', 'Условие поиска кампании'),
        'PHONE' => $f('crm_multifield', 'Телефон', ['isMultiple' => true]),
        'UF_CRM_CITY' => $f('string', 'UF_CRM_CITY', ['formLabel' => 'Город', 'listLabel' => 'Город']),
        'UF_CRM_BUDGET' => $f('enumeration', 'UF_CRM_BUDGET', ['formLabel' => 'Бюджет клиента', 'items' => [
            ['ID' => '101', 'VALUE' => 'до 100 тыс.'], ['ID' => '102', 'VALUE' => '100–500 тыс.'], ['ID' => '103', 'VALUE' => 'от 500 тыс.'],
        ]]),
        'UF_CRM_TAGS' => $f('string', 'UF_CRM_TAGS', ['formLabel' => 'Теги', 'isMultiple' => true]),
    ];
}

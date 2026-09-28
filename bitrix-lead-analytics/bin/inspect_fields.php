<?php
declare(strict_types=1);

/**
 * Этап 1. Разведка схемы лидов портала.
 *
 *   php bin/inspect_fields.php               # таблица в консоль + var/schema_map.json
 *   php bin/inspect_fields.php --json        # только JSON в stdout
 *   php bin/inspect_fields.php --out=path    # куда сохранить карту
 *
 * Вызывает crm.lead.fields и crm.status.list (стадии STATUS и источники SOURCE),
 * сопоставляет стандартные поля с колонками leads_current и находит все UF_CRM_*.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Bitrix\Client;
use App\Logger;

$opts = getopt('', ['json', 'out:']);
$cfg = app_config();
$log = new Logger('inspect', $cfg['app']['log_dir'] ?? null, $cfg['app']['log_level'] ?? 'info', !isset($opts['json']));
$b24 = new Client($cfg['bitrix']['webhook_url'], $log, $cfg['bitrix']);

/** Стандартное поле Bitrix24 → колонка leads_current. */
const STANDARD_MAP = [
    'ID' => 'bitrix_id',
    'TITLE' => 'title',
    'STATUS_ID' => 'status_id',
    'OPPORTUNITY' => 'opportunity',
    'CURRENCY_ID' => 'currency_id',
    'DATE_CREATE' => 'date_create',
    'DATE_MODIFY' => 'date_modify',
    'SOURCE_ID' => 'source_id',
    'SOURCE_DESCRIPTION' => 'source_description',
    'ASSIGNED_BY_ID' => 'assigned_by_id',
    'UTM_SOURCE' => 'utm_source',
    'UTM_MEDIUM' => 'utm_medium',
    'UTM_CAMPAIGN' => 'utm_campaign',
    'UTM_CONTENT' => 'utm_content',
    'UTM_TERM' => 'utm_term',
    'MOVED_TIME' => '(stage_entered_at)',
];

/** Тип Bitrix24 → рекомендуемый тип колонки, если поле понадобится вынести из JSON. */
function sqlTypeFor(string $type, bool $multiple): string
{
    if ($multiple) {
        return 'JSON';
    }
    return match ($type) {
        'integer', 'user', 'crm_status', 'enumeration', 'iblock_element', 'iblock_section', 'employee' => 'BIGINT',
        'double', 'money' => 'DECIMAL(18,2)',
        'date' => 'DATE',
        'datetime' => 'DATETIME',
        'boolean', 'char' => 'SMALLINT',
        'crm', 'crm_multifield', 'file' => 'JSON',
        default => 'VARCHAR(255)',
    };
}

$res = $b24->batch([
    'fields' => 'crm.lead.fields',
    'statuses' => 'crm.status.list?' . http_build_query(['order' => ['SORT' => 'ASC'], 'filter' => ['ENTITY_ID' => 'STATUS']]),
    'sources' => 'crm.status.list?' . http_build_query(['order' => ['SORT' => 'ASC'], 'filter' => ['ENTITY_ID' => 'SOURCE']]),
]);

$fields = $res['result']['fields'] ?? [];
$standard = [];
$custom = [];
$missing = [];

foreach (STANDARD_MAP as $code => $column) {
    if (!isset($fields[$code])) {
        $missing[] = $code;
    }
}

foreach ($fields as $code => $f) {
    $type = (string) ($f['type'] ?? 'string');
    $multiple = (bool) ($f['isMultiple'] ?? false);
    $entry = [
        'code' => $code,
        'title' => (string) ($f['formLabel'] ?? $f['listLabel'] ?? $f['title'] ?? $code),
        'type' => $type,
        'is_multiple' => $multiple,
        'is_required' => (bool) ($f['isRequired'] ?? false),
        'is_read_only' => (bool) ($f['isReadOnly'] ?? false),
    ];
    if (str_starts_with($code, 'UF_CRM_')) {
        $entry['storage'] = 'leads_current.custom_fields->' . $code;
        $entry['suggested_sql_type'] = sqlTypeFor($type, $multiple);
        if (!empty($f['items']) && is_array($f['items'])) {
            $entry['items'] = array_map(
                static fn ($i) => ['id' => (string) ($i['ID'] ?? ''), 'value' => (string) ($i['VALUE'] ?? '')],
                array_values($f['items'])
            );
        }
        $custom[] = $entry;
    } elseif (isset(STANDARD_MAP[$code])) {
        $entry['column'] = STANDARD_MAP[$code];
        $standard[] = $entry;
    }
}

$mapStatus = static fn (array $rows) => array_map(static fn ($s) => [
    'id' => (string) $s['STATUS_ID'],
    'name' => (string) ($s['NAME'] ?? ''),
    'sort' => (int) ($s['SORT'] ?? 0),
    'semantics' => (string) ($s['SEMANTICS'] ?? ($s['EXTRA']['SEMANTICS'] ?? '')),
    'color' => (string) ($s['COLOR'] ?? ($s['EXTRA']['COLOR'] ?? '')),
], $rows);

$schema = [
    'generated_at' => gmdate('c'),
    'portal' => parse_url($cfg['bitrix']['webhook_url'], PHP_URL_HOST),
    'standard_fields' => $standard,
    'missing_standard_fields' => $missing,
    'custom_fields' => $custom,
    'lead_statuses' => $mapStatus($res['result']['statuses'] ?? []),
    'lead_sources' => $mapStatus($res['result']['sources'] ?? []),
];
$json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (isset($opts['json'])) {
    echo $json, "\n";
    exit(0);
}

$out = $opts['out'] ?? dirname(__DIR__) . '/var/schema_map.json';
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0775, true);
}
file_put_contents($out, $json . "\n");

/** str_pad по символам, а не байтам: кириллица иначе ломает колонки. */
function pad(string $s, int $w): string
{
    return $s . str_repeat(' ', max(1, $w - mb_strlen($s)));
}
$line = static fn (string $a, string $b, string $c, string $d) => print('  ' . pad($a, 23) . pad($b, 35) . pad($c, 15) . $d . "\n");

echo "\nСтандартные поля → leads_current\n";
$line('КОД', 'НАЗВАНИЕ', 'ТИП', 'КОЛОНКА');
foreach ($standard as $f) {
    $line($f['code'], mb_strimwidth($f['title'], 0, 32, '…'), $f['type'], $f['column']);
}
if ($missing) {
    echo "\n  ! На портале нет полей: ", implode(', ', $missing), "\n";
}

echo "\nПользовательские поля (UF_CRM_*) → custom_fields (JSON): ", count($custom), "\n";
if ($custom) {
    $line('КОД', 'НАЗВАНИЕ', 'ТИП', 'SQL, если выносить в колонку');
    foreach ($custom as $f) {
        $line($f['code'], mb_strimwidth($f['title'], 0, 32, '…'), $f['type'] . ($f['is_multiple'] ? '[]' : ''), $f['suggested_sql_type']);
    }
}

echo "\nСтадии лида (crm.status.list STATUS):\n";
foreach ($schema['lead_statuses'] as $s) {
    echo '  ', pad((string) $s['sort'], 6), pad($s['id'], 23), pad($s['name'], 31), $s['semantics'] ?: 'process', "\n";
}
echo "\nИсточники (crm.status.list SOURCE):\n";
foreach ($schema['lead_sources'] as $s) {
    echo '  ', pad((string) $s['sort'], 6), pad($s['id'], 23), $s['name'], "\n";
}
echo "\nКарта схемы сохранена: {$out}\n";

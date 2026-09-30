<?php
declare(strict_types=1);

/**
 * Мок REST API Bitrix24 для локальной проверки ETL и дашборда.
 *
 *   php -S 127.0.0.1:8099 tests/mock-bitrix/router.php
 *   вебхук: http://127.0.0.1:8099/rest/1/testtoken/
 *
 * Поддержано: crm.lead.list (filter >=DATE_MODIFY, order ID, start), crm.lead.fields,
 * crm.status.list (ENTITY_ID), batch. MOCK_FAIL_RATE=0.2 отвечает 503 QUERY_LIMIT_EXCEEDED
 * на 20% запросов, чтобы проверить повторы.
 */

require __DIR__ . '/state.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!preg_match('~^/rest/1/testtoken/([a-z0-9_.]+)\.json$~i', (string) $path, $m)) {
    http_response_code(401);
    echo json_encode(['error' => 'INVALID_CREDENTIALS', 'error_description' => 'Invalid request credentials']);
    return true;
}
header('Content-Type: application/json');

$failRate = (float) (getenv('MOCK_FAIL_RATE') ?: 0);
if ($failRate > 0 && mt_rand() / mt_getrandmax() < $failRate) {
    http_response_code(503);
    echo json_encode(['error' => 'QUERY_LIMIT_EXCEEDED', 'error_description' => 'Too many requests']);
    return true;
}

$state = mock_load();
$params = $_POST ?: [];
$method = strtolower($m[1]);

if ($method === 'batch') {
    $out = ['result' => [], 'result_error' => [], 'result_total' => [], 'result_next' => [], 'result_time' => []];
    foreach ((array) ($params['cmd'] ?? []) as $key => $cmd) {
        [$sub, $query] = array_pad(explode('?', (string) $cmd, 2), 2, '');
        parse_str($query, $subParams);
        $r = mock_dispatch(strtolower($sub), $subParams, $state);
        if (isset($r['error'])) {
            $out['result_error'][$key] = $r;
            continue;
        }
        $out['result'][$key] = $r['result'];
        if (isset($r['total'])) {
            $out['result_total'][$key] = $r['total'];
        }
        if (isset($r['next'])) {
            $out['result_next'][$key] = $r['next'];
        }
    }
    echo json_encode(['result' => $out, 'time' => ['start' => microtime(true)]], JSON_UNESCAPED_UNICODE);
    return true;
}

$r = mock_dispatch($method, $params, $state);
if (isset($r['error'])) {
    http_response_code(400);
}
echo json_encode($r + ['time' => ['start' => microtime(true)]], JSON_UNESCAPED_UNICODE);
return true;

function mock_dispatch(string $method, array $p, array $state): array
{
    switch ($method) {
        case 'crm.lead.fields':
            return ['result' => mock_fields()];
        case 'crm.status.list':
            $entity = $p['filter']['ENTITY_ID'] ?? null;
            $rows = array_values(array_filter(mock_directories(), static fn ($s) => $entity === null || $s['ENTITY_ID'] === $entity));
            return ['result' => $rows, 'total' => count($rows)];
        case 'crm.lead.list':
            $leads = array_values(array_filter($state['leads'], static fn ($l) => empty($l['_deleted'])));
            $since = $p['filter']['>=DATE_MODIFY'] ?? null;
            if ($since !== null) {
                $ts = strtotime((string) $since);
                $leads = array_values(array_filter($leads, static fn ($l) => strtotime($l['DATE_MODIFY']) >= $ts));
            }
            $createdFrom = $p['filter']['>=DATE_CREATE'] ?? null;
            if ($createdFrom !== null) {
                $cts = strtotime((string) $createdFrom);
                $leads = array_values(array_filter($leads, static fn ($l) => strtotime($l['DATE_CREATE']) >= $cts));
            }
            usort($leads, static fn ($a, $b) => (int) $a['ID'] <=> (int) $b['ID']);
            $start = (int) ($p['start'] ?? 0);
            $page = array_slice($leads, $start, 50);
            // Честный портал отдаёт только запрошенные поля. MOCK_IGNORE_SELECT=1 имитирует худший случай:
            // портал прислал всё, включая имя и телефон, и защищаться должен наш маппер.
            $select = (array) ($p['select'] ?? []);
            if ($logFile = getenv('MOCK_SELECT_LOG')) {
                file_put_contents($logFile, implode(',', $select) . "\n", FILE_APPEND);
            }
            $page = array_map(static function ($l) use ($select) {
                unset($l['_deleted']);
                if ($select && !getenv('MOCK_IGNORE_SELECT')) {
                    $l = array_intersect_key($l, array_flip($select));
                }
                return $l;
            }, $page);
            $r = ['result' => $page, 'total' => count($leads)];
            if ($start + 50 < count($leads)) {
                $r['next'] = $start + 50;
            }
            return $r;
        case 'crm.deal.list':
            // Сделки из сконвертированных лидов: детерминированно по ID лида.
            $now = strtotime($state['now']);
            $deals = [];
            foreach ($state['leads'] as $l) {
                if (!empty($l['_deleted']) || ($l['STATUS_ID'] ?? '') !== 'CONVERTED') {
                    continue;
                }
                $h = crc32('deal' . $l['ID']);
                $created = strtotime($l['MOVED_TIME']);
                $moved = min($now, $created + ($h % 10) * 86400 + 3600);
                $sem = ($h % 100) < 60 ? 'S' : (($h % 100) < 75 ? 'F' : 'P');
                $deals[] = [
                    'ID' => (string) (100000 + (int) $l['ID']),
                    'LEAD_ID' => $l['ID'],
                    'STAGE_ID' => $sem === 'S' ? 'WON' : ($sem === 'F' ? 'LOSE' : 'EXECUTING'),
                    'STAGE_SEMANTIC_ID' => $sem,
                    'OPPORTUNITY' => (string) round((float) $l['OPPORTUNITY'] * (0.8 + ($h % 40) / 100)),
                    'CURRENCY_ID' => $l['CURRENCY_ID'],
                    'DATE_CREATE' => mock_iso($created),
                    'DATE_MODIFY' => mock_iso($sem === 'P' ? $created : $moved),
                    'MOVED_TIME' => mock_iso($sem === 'P' ? $created : $moved),
                    'CLOSEDATE' => mock_iso($moved),
                    'CLOSED' => $sem === 'P' ? 'N' : 'Y',
                    'TITLE' => 'Сделка с Иваном Петровым', // не должно попасть в базу
                ];
            }
            if (isset($p['filter']['>=DATE_MODIFY'])) {
                $ts = strtotime((string) $p['filter']['>=DATE_MODIFY']);
                $deals = array_values(array_filter($deals, static fn ($d) => strtotime($d['DATE_MODIFY']) >= $ts));
            }
            if (isset($p['filter']['>=DATE_CREATE'])) {
                $ts = strtotime((string) $p['filter']['>=DATE_CREATE']);
                $deals = array_values(array_filter($deals, static fn ($d) => strtotime($d['DATE_CREATE']) >= $ts));
            }
            $start = (int) ($p['start'] ?? 0);
            $r = ['result' => array_slice($deals, $start, 50), 'total' => count($deals)];
            if ($start + 50 < count($deals)) {
                $r['next'] = $start + 50;
            }
            return $r;
        default:
            return ['error' => 'ERROR_METHOD_NOT_FOUND', 'error_description' => 'Method not found: ' . $method];
    }
}

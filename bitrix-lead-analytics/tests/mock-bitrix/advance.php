<?php
declare(strict_types=1);

/**
 * Двигает мок-портал вперёд: новые лиды, переходы по стадиям, брак, удаления.
 *
 *   php tests/mock-bitrix/advance.php --seed=400 --start="-30 days" --end="-3 days"  # начальное наполнение
 *   php tests/mock-bitrix/advance.php --hours=2                       # один двухчасовой цикл
 *   php tests/mock-bitrix/advance.php --now                           # только напечатать время портала
 *
 * Печатает новое "текущее время" портала (UTC), его удобно передать в SYNC_NOW.
 */

require __DIR__ . '/state.php';

$o = getopt('', ['seed:', 'start:', 'end:', 'hours:', 'rand:', 'now']);
mt_srand((int) ($o['rand'] ?? crc32((string) microtime())));

$sources = [
    // SOURCE_ID => [вес, utm_source, utm_medium, кампании, вероятность конверсии]
    'ADVERTISING'    => [30, 'yandex',   'cpc',     ['brand_search', 'retarget_sept', 'lookalike_msk'], 0.18],
    'WEB'            => [22, 'google',   'organic', [null],                                              0.22],
    'UC_TELEGRAM'    => [18, 'telegram', 'social',  ['tg_channel_post', 'tg_bot_invite'],               0.30],
    'CALL'           => [10, null,       null,      [null],                                              0.35],
    'RECOMMENDATION' => [8,  null,       null,      [null],                                              0.45],
    'EMAIL'          => [7,  'sendpulse','email',   ['newsletter_sept', 'reactivation'],                 0.15],
    'OTHER'          => [5,  null,       null,      [null],                                              0.10],
];
$stageOrder = ['NEW', 'IN_PROCESS', 'UC_MEETING', 'PROCESSED', 'CONVERTED'];
$managers = [11, 12, 13, 14];
$cities = ['Москва', 'Санкт-Петербург', 'Казань', 'Екатеринбург', 'Новосибирск'];

$pickSource = static function () use ($sources): string {
    $total = array_sum(array_column($sources, 0));
    $r = mt_rand(1, $total);
    foreach ($sources as $id => $s) {
        if (($r -= $s[0]) <= 0) {
            return $id;
        }
    }
    return 'OTHER';
};

$newLead = static function (array &$state, int $ts) use ($pickSource, $sources, $managers, $cities): void {
    $src = $pickSource();
    [, $utmS, $utmM, $camps] = $sources[$src];
    $id = $state['next_id']++;
    $state['leads'][(string) $id] = [
        'ID' => (string) $id,
        'TITLE' => 'Заявка #' . $id,
        'STATUS_ID' => 'NEW',
        'OPPORTUNITY' => (string) (mt_rand(0, 3) === 0 ? 0 : mt_rand(15, 450) * 1000),
        'CURRENCY_ID' => mt_rand(0, 30) === 0 ? 'USD' : 'RUB',
        'DATE_CREATE' => mock_iso($ts),
        'DATE_MODIFY' => mock_iso($ts),
        'MOVED_TIME' => mock_iso($ts),
        'SOURCE_ID' => $src,
        'SOURCE_DESCRIPTION' => null,
        'ASSIGNED_BY_ID' => (string) $managers[array_rand($managers)],
        'UTM_SOURCE' => $utmS,
        'UTM_MEDIUM' => $utmM,
        'UTM_CAMPAIGN' => $camps[array_rand($camps)],
        'UTM_CONTENT' => null,
        'UTM_TERM' => null,
        'UF_CRM_CITY' => $cities[array_rand($cities)],
        'UF_CRM_BUDGET' => (string) mt_rand(101, 103),
        'UF_CRM_TAGS' => [],
    ];
};

/** Лид с шансом двигается на следующую стадию, в брак или стоит на месте. */
$step = static function (array &$lead, int $fromTs, int $toTs) use ($sources, $stageOrder): void {
    $st = $lead['STATUS_ID'];
    if ($st === 'CONVERTED' || $st === 'JUNK') {
        return;
    }
    $conv = $sources[$lead['SOURCE_ID']][4] ?? 0.2;
    $r = mt_rand() / mt_getrandmax();
    $moveAt = mt_rand($fromTs, $toTs);
    if ($r < 0.025 + (0.25 - $conv) * 0.08) {
        $lead['STATUS_ID'] = 'JUNK';
    } elseif ($r < 0.025 + 0.06 + $conv * 0.2) {
        $i = array_search($st, $stageOrder, true);
        $lead['STATUS_ID'] = $stageOrder[min($i + 1, count($stageOrder) - 1)];
    } elseif ($r < 0.30) {
        // правка суммы без смены стадии
        $lead['OPPORTUNITY'] = (string) ((int) $lead['OPPORTUNITY'] + 5000);
        $lead['DATE_MODIFY'] = mock_iso($moveAt);
        return;
    } else {
        return;
    }
    $lead['MOVED_TIME'] = mock_iso($moveAt);
    $lead['DATE_MODIFY'] = mock_iso($moveAt);
};

$state = mock_load();

if (isset($o['now'])) { // только напечатать текущее время портала
    echo gmdate('Y-m-d H:i:s', strtotime($state['now'])), "\n";
    exit(0);
}

if (isset($o['seed'])) {
    $start = strtotime((string) ($o['start'] ?? '-30 days'));
    $state = ['now' => gmdate('c', $start), 'next_id' => 1001, 'leads' => []];
    $count = (int) $o['seed'];
    $end = strtotime((string) ($o['end'] ?? 'now'));
    // Лиды равномерно по периоду, каждый "прожил" несколько шагов до текущего момента.
    for ($i = 0; $i < $count; $i++) {
        $ts = mt_rand($start, $end - 3600);
        $newLead($state, $ts);
        $id = (string) ($state['next_id'] - 1);
        $cursor = $ts;
        while ($cursor < $end - 7200) {
            $next = $cursor + mt_rand(4 * 3600, 3 * 86400);
            if ($next > $end - 7200) {
                break;
            }
            $step($state['leads'][$id], $cursor, $next);
            $cursor = $next;
        }
    }
    $state['now'] = gmdate('c', $end - 7200);
    mock_save($state);
    echo gmdate('Y-m-d H:i:s', strtotime($state['now'])), "\n";
    exit(0);
}

$hours = (float) ($o['hours'] ?? 2);
$from = strtotime($state['now']);
$to = $from + (int) ($hours * 3600);

$newCount = mt_rand(0, 4);
for ($i = 0; $i < $newCount; $i++) {
    $newLead($state, mt_rand($from, $to));
}
foreach ($state['leads'] as $id => &$lead) {
    if (!empty($lead['_deleted'])) {
        continue;
    }
    // За 2 часа двигается малая часть лидов, как в живой воронке.
    if (mt_rand(1, 100) <= 4) {
        $step($lead, $from, $to);
    }
    if (mt_rand(0, 4000) === 0) {
        $lead['_deleted'] = true;
    }
}
unset($lead);

$state['now'] = gmdate('c', $to);
mock_save($state);
echo gmdate('Y-m-d H:i:s', $to), "\n";

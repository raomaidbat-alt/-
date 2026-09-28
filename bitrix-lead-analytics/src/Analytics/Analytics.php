<?php
declare(strict_types=1);

namespace App\Analytics;

use App\Db;

/**
 * Агрегаты для дашборда.
 *
 * Две оси времени:
 *  - когорта: лиды, СОЗДАННЫЕ в периоде (KPI, источники, UTM, воронка, динамика по дням);
 *  - события: переходы стадий, ЗАФИКСИРОВАННЫЕ в периоде (скорость воронки, таймлайн).
 *
 * Тяжёлую арифметику (доли, пороги, склейку валют) делаем в PHP поверх компактных GROUP BY,
 * чтобы один и тот же SQL работал и в MySQL, и в PostgreSQL.
 */
final class Analytics
{
    /** Минимум лидов, чтобы источник участвовал в номинации "лучшая конверсия". */
    private const MIN_LEADS_FOR_TOP_CONVERSION = 5;
    private const MAX_TIMELINE_POINTS = 120;

    /**
     * @param array{consultation_status?:string} $funnelCfg
     */
    public function __construct(
        private readonly Db $db,
        private readonly ChannelMap $channels = new ChannelMap([]),
        private readonly array $funnelCfg = [],
    ) {
    }

    /**
     * @param array{from:string,to:string,sources:list<string>,utm_source:?string,channel:?string,
     *        compare:bool,tz:\DateTimeZone,offset_minutes:int} $f  from/to в UTC 'Y-m-d H:i:s'
     */
    public function build(array $f): array
    {
        $statuses = $this->statuses();
        $sourceNames = $this->sourceNames();
        [$where, $params] = $this->leadFilter($f, 'l');

        $rows = $this->cohortRows($where, $params);
        $kpi = $this->kpi($rows, $sourceNames);
        $sources = $this->sourcesBreakdown($rows, $sourceNames);
        $days = $this->periodDays($f);

        $previous = null;
        if ($f['compare'] ?? true) {
            $len = strtotime($f['to'] . ' UTC') - strtotime($f['from'] . ' UTC');
            $pf = $f;
            $pf['to'] = gmdate('Y-m-d H:i:s', strtotime($f['from'] . ' UTC') - 1);
            $pf['from'] = gmdate('Y-m-d H:i:s', strtotime($f['from'] . ' UTC') - 1 - $len);
            [$pw, $pp] = $this->leadFilter($pf, 'l');
            $previous = ['from' => $pf['from'], 'to' => $pf['to']]
                + $this->kpi($this->cohortRows($pw, $pp), $sourceNames);
            unset($previous['top_source_volume'], $previous['top_source_conversion']);
        }

        return [
            'kpi' => $kpi,
            'previous' => $previous,
            'channels' => $this->channelsBreakdown($rows, $days),
            'marketing_funnel' => $this->marketingFunnel($kpi, $statuses, $where, $params, $f, $days),
            'sources' => $sources,
            'utm_sources' => $this->utmBreakdown('utm_source', $where, $params, 15),
            'utm_campaigns' => $this->utmBreakdown('utm_campaign', $where, $params, 15),
            'funnel' => $this->funnel($statuses, $where, $params),
            'velocity' => $this->velocity($statuses, $f),
            'timeline' => $this->timeline($statuses, $f),
            'daily' => $this->daily($f, $where, $params),
            'statuses' => array_values(array_map(
                static fn ($id, $s) => ['id' => $id] + $s,
                array_keys($statuses),
                $statuses
            )),
        ];
    }

    /** Данные для фильтров дашборда: все источники и самые частые utm_source. */
    public function filterOptions(): array
    {
        $sources = $this->db->all(
            "SELECT d.source_id AS id, d.name, COUNT(l.bitrix_id) AS leads
               FROM sources_directory d
               LEFT JOIN leads_current l ON l.source_id = d.source_id AND l.is_deleted = 0
              GROUP BY d.source_id, d.name, d.sort
              ORDER BY d.sort"
        );
        $utm = $this->db->all(
            "SELECT utm_source AS id, COUNT(*) AS leads
               FROM leads_current
              WHERE is_deleted = 0 AND utm_source IS NOT NULL
              GROUP BY utm_source
              ORDER BY COUNT(*) DESC
              LIMIT 50"
        );
        return [
            'sources' => array_map(static fn ($r) => ['id' => $r['id'], 'name' => $r['name'], 'leads' => (int) $r['leads']], $sources),
            'utm_sources' => array_map(static fn ($r) => ['id' => $r['id'], 'leads' => (int) $r['leads']], $utm),
        ];
    }

    public function syncStatus(): array
    {
        $last = $this->db->one('SELECT * FROM sync_runs ORDER BY id DESC LIMIT 1');
        $lastOk = $this->db->one("SELECT * FROM sync_runs WHERE status = 'success' ORDER BY id DESC LIMIT 1");
        $fmt = static fn (?array $r) => $r === null ? null : [
            'id' => (int) $r['id'],
            'mode' => $r['mode'],
            'status' => $r['status'],
            'started_at' => self::iso($r['started_at']),
            'finished_at' => self::iso($r['finished_at']),
            'leads_fetched' => (int) $r['leads_fetched'],
            'snapshots_written' => (int) $r['snapshots_written'],
            'error' => $r['status'] === 'failed' ? mb_substr((string) $r['error_message'], 0, 300) : null,
        ];
        return ['last_run' => $fmt($last), 'last_success' => $fmt($lastOk)];
    }

    /** Компактная выборка когорты: одна строка на источник × utm_source × исход × валюту. */
    private function cohortRows(string $where, array $params): array
    {
        return $this->db->all(
            "SELECT l.source_id, l.utm_source, l.status_semantics, l.currency_id,
                    COUNT(*) AS cnt,
                    SUM(l.opportunity) AS value,
                    SUM(l.is_qualified) AS qualified,
                    SUM(CASE WHEN l.opportunity > 0 THEN 1 ELSE 0 END) AS with_value
               FROM leads_current l
              WHERE {$where}
              GROUP BY l.source_id, l.utm_source, l.status_semantics, l.currency_id",
            $params
        );
    }

    private function periodDays(array $f): float
    {
        return max(1 / 24, (strtotime($f['to'] . ' UTC') - strtotime($f['from'] . ' UTC')) / 86400);
    }

    // ---------------------------------------------------------------- KPI

    private function kpi(array $rows, array $sourceNames): array
    {
        $total = $active = $converted = $junk = $qualified = $withValue = $paidWithValue = 0;
        $pipeline = [];   // сумма по активным лидам, по валютам
        $wonValue = [];   // сумма по сконвертированным
        $allValue = [];   // сумма по всем, кроме брака (для среднего чека)
        $perSource = [];

        foreach ($rows as $r) {
            $cnt = (int) $r['cnt'];
            $val = (float) $r['value'];
            $cur = $r['currency_id'] ?: 'RUB';
            $sem = $r['status_semantics'];
            $src = $r['source_id'] ?? '';

            $total += $cnt;
            $qualified += (int) $r['qualified'];
            $perSource[$src]['total'] = ($perSource[$src]['total'] ?? 0) + $cnt;
            $perSource[$src]['converted'] = ($perSource[$src]['converted'] ?? 0) + ($sem === 'S' ? $cnt : 0);

            if ($sem === 'P') {
                $active += $cnt;
                $pipeline[$cur] = ($pipeline[$cur] ?? 0) + $val;
            } elseif ($sem === 'S') {
                $converted += $cnt;
                $wonValue[$cur] = ($wonValue[$cur] ?? 0) + $val;
                $paidWithValue += (int) $r['with_value'];
            } else {
                $junk += $cnt;
            }
            if ($sem !== 'F') {
                $allValue[$cur] = ($allValue[$cur] ?? 0) + $val;
                $withValue += (int) $r['with_value'];
            }
        }

        $topVolume = null;
        $topConversion = null;
        foreach ($perSource as $src => $s) {
            if ($topVolume === null || $s['total'] > $topVolume['leads']) {
                $topVolume = ['id' => $src, 'name' => $sourceNames[$src] ?? ($src ?: 'Не указан'), 'leads' => $s['total']];
            }
            if ($s['total'] >= self::MIN_LEADS_FOR_TOP_CONVERSION) {
                $rate = $s['converted'] / $s['total'];
                if ($topConversion === null || $rate > $topConversion['rate']) {
                    $topConversion = [
                        'id' => $src, 'name' => $sourceNames[$src] ?? ($src ?: 'Не указан'),
                        'rate' => round($rate, 4), 'leads' => $s['total'], 'converted' => $s['converted'],
                    ];
                }
            }
        }

        $primaryCurrency = $allValue ? array_keys($allValue, max($allValue))[0] : 'RUB';
        $closed = $converted + $junk;

        return [
            'total_leads' => $total,
            'qualified_leads' => $qualified,
            'active_leads' => $active,
            'converted_leads' => $converted,
            'junk_leads' => $junk,
            'conversion_rate' => $total ? round($converted / $total, 4) : 0.0,
            'win_rate_closed' => $closed ? round($converted / $closed, 4) : 0.0,
            'qualification_rate' => $total ? round($qualified / $total, 4) : 0.0,
            'currency' => $primaryCurrency,
            'pipeline_value' => self::money($pipeline),
            'converted_value' => self::money($wonValue),
            'revenue' => round((float) ($wonValue[$primaryCurrency] ?? 0), 2),
            'avg_check' => $paidWithValue ? round(($wonValue[$primaryCurrency] ?? 0) / $paidWithValue, 2) : 0.0,
            'avg_value' => $withValue ? round(($allValue[$primaryCurrency] ?? 0) / $withValue, 2) : 0.0,
            'top_source_volume' => $topVolume,
            'top_source_conversion' => $topConversion,
            'min_leads_for_top_conversion' => self::MIN_LEADS_FOR_TOP_CONVERSION,
        ];
    }

    /**
     * Лиды, квалификация, оплаты, выручка и ROMI по маркетинговым каналам.
     * ROMI = (выручка − расходы) / расходы, где расходы = spend_per_month × дней периода / 30.44.
     */
    private function channelsBreakdown(array $rows, float $days): array
    {
        $out = [];
        foreach ($this->channels->options() as $o) {
            $out[$o['id']] = $o;
        }
        $out['other'] = ['id' => 'other', 'name' => 'Другие источники'];
        foreach ($out as &$c) {
            $c += ['leads' => 0, 'qualified' => 0, 'paid' => 0, 'junk' => 0, 'revenue' => 0.0];
        }
        unset($c);

        $primary = null;
        $byCur = [];
        foreach ($rows as $r) {
            $byCur[$r['currency_id'] ?: 'RUB'] = ($byCur[$r['currency_id'] ?: 'RUB'] ?? 0) + (int) $r['cnt'];
        }
        if ($byCur) {
            arsort($byCur);
            $primary = array_key_first($byCur);
        }

        foreach ($rows as $r) {
            $k = $this->channels->channelOf($r['source_id'], $r['utm_source']);
            $cnt = (int) $r['cnt'];
            $out[$k]['leads'] += $cnt;
            $out[$k]['qualified'] += (int) $r['qualified'];
            if ($r['status_semantics'] === 'S') {
                $out[$k]['paid'] += $cnt;
                if (($r['currency_id'] ?: 'RUB') === $primary) {
                    $out[$k]['revenue'] += (float) $r['value'];
                }
            } elseif ($r['status_semantics'] === 'F') {
                $out[$k]['junk'] += $cnt;
            }
        }

        foreach ($out as $key => &$c) {
            $perDay = $this->channels->spendPerDay($key);
            $spend = $perDay !== null ? round($perDay * $days, 2) : null;
            $c['revenue'] = round($c['revenue'], 2);
            $c['spend'] = $spend;
            $c['romi'] = $spend ? round(($c['revenue'] - $spend) / $spend, 4) : null;
            $c['cr_qualified'] = $c['leads'] ? round($c['qualified'] / $c['leads'], 4) : 0.0;
            $c['cr_paid'] = $c['leads'] ? round($c['paid'] / $c['leads'], 4) : 0.0;
            $c['revenue_per_lead'] = $c['leads'] ? round($c['revenue'] / $c['leads'], 2) : 0.0;
        }
        unset($c);
        if ($out['other']['leads'] === 0) {
            unset($out['other']);
        }
        return array_values($out);
    }

    /**
     * Маркетинговая воронка из 5 шагов:
     * охват (из конфига, в Bitrix24 его нет) → лид → SQL (ушёл дальше первой стадии)
     * → консультация (дошёл до funnel.consultation_status) → оплата (успешная стадия).
     */
    private function marketingFunnel(array $kpi, array $statuses, string $where, array $params, array $f, float $days): array
    {
        $consultStatus = $this->funnelCfg['consultation_status'] ?? null;
        $consult = null;
        if ($consultStatus !== null && isset($statuses[$consultStatus])) {
            $row = $this->db->one(
                "SELECT COUNT(*) AS c FROM leads_current l WHERE {$where} AND l.max_stage_sort >= ?",
                array_merge($params, [$statuses[$consultStatus]['sort']])
            );
            $consult = (int) $row['c'];
        }

        $reach = 0;
        foreach ($this->channels->options() as $o) {
            if (($f['channel'] ?? null) !== null && $f['channel'] !== $o['id']) {
                continue;
            }
            $reach += (int) round(($this->channels->reachPerDay($o['id']) ?? 0) * $days);
        }

        $steps = [
            ['id' => 'reach', 'name' => 'Просмотры / Охват', 'value' => $reach > 0 ? $reach : null],
            ['id' => 'lead', 'name' => 'Вход в воронку (Лид)', 'value' => $kpi['total_leads']],
            ['id' => 'sql', 'name' => 'Квалификация (SQL)', 'value' => $kpi['qualified_leads']],
            ['id' => 'consult', 'name' => 'Консультация / Демо / КП', 'value' => $consult],
            ['id' => 'paid', 'name' => 'Продажа (Оплата)', 'value' => $kpi['converted_leads']],
        ];
        $entry = $kpi['total_leads'];
        $prev = null;
        foreach ($steps as &$st) {
            $v = $st['value'];
            $st['step_cr'] = ($v !== null && $prev) ? round($v / $prev, 4) : null;
            $st['total_cr'] = ($v !== null && $entry && $st['id'] !== 'reach') ? round($v / $entry, 4) : null;
            $st['dropped'] = ($v !== null && $prev !== null) ? max(0, $prev - $v) : null;
            if ($v !== null) {
                $prev = $v;
            }
        }
        unset($st);
        return $steps;
    }

    private function sourcesBreakdown(array $rows, array $sourceNames): array
    {
        $out = [];
        foreach ($rows as $r) {
            $id = $r['source_id'] ?? '';
            $out[$id] ??= [
                'id' => $id, 'name' => $sourceNames[$id] ?? ($id ?: 'Не указан'),
                'total' => 0, 'active' => 0, 'converted' => 0, 'junk' => 0, 'qualified' => 0, 'value' => 0.0,
            ];
            $cnt = (int) $r['cnt'];
            $out[$id]['total'] += $cnt;
            $out[$id]['qualified'] += (int) $r['qualified'];
            $out[$id][match ($r['status_semantics']) { 'S' => 'converted', 'F' => 'junk', default => 'active' }] += $cnt;
            if ($r['status_semantics'] !== 'F') {
                $out[$id]['value'] += (float) $r['value'];
            }
        }
        foreach ($out as &$s) {
            $s['conversion_rate'] = $s['total'] ? round($s['converted'] / $s['total'], 4) : 0.0;
            $s['qualification_rate'] = $s['total'] ? round($s['qualified'] / $s['total'], 4) : 0.0;
            $s['value'] = round($s['value'], 2);
        }
        unset($s);
        usort($out, static fn ($a, $b) => $b['total'] <=> $a['total']);
        return $out;
    }

    /** $column берётся только из белого списка: utm_source | utm_campaign. */
    private function utmBreakdown(string $column, string $where, array $params, int $limit): array
    {
        if (!in_array($column, ['utm_source', 'utm_campaign'], true)) {
            throw new \InvalidArgumentException('Bad UTM column');
        }
        $rows = $this->db->all(
            "SELECT l.{$column} AS k, l.status_semantics, COUNT(*) AS cnt
               FROM leads_current l
              WHERE {$where}
              GROUP BY l.{$column}, l.status_semantics",
            $params
        );
        $agg = [];
        foreach ($rows as $r) {
            $k = $r['k'] ?? '(не задан)';
            $agg[$k] ??= ['key' => $k, 'total' => 0, 'converted' => 0, 'junk' => 0];
            $agg[$k]['total'] += (int) $r['cnt'];
            if ($r['status_semantics'] === 'S') {
                $agg[$k]['converted'] += (int) $r['cnt'];
            } elseif ($r['status_semantics'] === 'F') {
                $agg[$k]['junk'] += (int) $r['cnt'];
            }
        }
        foreach ($agg as &$a) {
            $a['conversion_rate'] = $a['total'] ? round($a['converted'] / $a['total'], 4) : 0.0;
        }
        unset($a);
        usort($agg, static fn ($a, $b) => $b['total'] <=> $a['total']);
        return array_slice($agg, 0, $limit);
    }

    // ------------------------------------------------------------- Воронка

    /**
     * Сколько лидов когорты дошло до каждой стадии. "Дошёл" = max_stage_sort >= sort стадии,
     * т.е. перепрыгнутые стадии тоже считаются пройденными. Брак показывается отдельно.
     */
    private function funnel(array $statuses, string $where, array $params): array
    {
        $rows = $this->db->all(
            "SELECT l.max_stage_sort, l.status_semantics, COUNT(*) AS cnt
               FROM leads_current l
              WHERE {$where}
              GROUP BY l.max_stage_sort, l.status_semantics",
            $params
        );
        $total = array_sum(array_map(static fn ($r) => (int) $r['cnt'], $rows));

        $stages = array_filter($statuses, static fn ($s) => $s['semantics'] !== 'F');
        uasort($stages, static fn ($a, $b) => $a['sort'] <=> $b['sort']);

        $out = [];
        $prevReached = null;
        foreach ($stages as $id => $s) {
            $reached = 0;
            foreach ($rows as $r) {
                if ((int) $r['max_stage_sort'] >= $s['sort']) {
                    $reached += (int) $r['cnt'];
                }
            }
            $out[] = [
                'status_id' => $id,
                'name' => $s['name'],
                'color' => $s['color'],
                'semantics' => $s['semantics'],
                'reached' => $reached,
                'share_of_total' => $total ? round($reached / $total, 4) : 0.0,
                'step_conversion' => $prevReached ? round($reached / $prevReached, 4) : ($prevReached === null ? 1.0 : 0.0),
                'dropped' => $prevReached !== null ? max(0, $prevReached - $reached) : 0,
            ];
            $prevReached = $reached;
        }

        $junkByStatus = [];
        foreach ($statuses as $id => $s) {
            if ($s['semantics'] === 'F') {
                $junkByStatus[$id] = ['status_id' => $id, 'name' => $s['name'], 'color' => $s['color'], 'count' => 0];
            }
        }
        $junk = $this->db->all(
            "SELECT l.status_id, COUNT(*) AS cnt FROM leads_current l
              WHERE {$where} AND l.status_semantics = 'F' GROUP BY l.status_id",
            $params
        );
        foreach ($junk as $j) {
            if (isset($junkByStatus[$j['status_id']])) {
                $junkByStatus[$j['status_id']]['count'] = (int) $j['cnt'];
            }
        }
        return ['total' => $total, 'stages' => $out, 'lost' => array_values($junkByStatus)];
    }

    // ------------------------------------------------------ Скорость воронки

    private function velocity(array $statuses, array $f): array
    {
        [$where, $params] = $this->leadFilter($f, 'l', false);
        $params = array_merge([$f['from'], $f['to']], $params);

        $pairs = $this->db->all(
            "SELECT s.prev_status_id, s.status_id,
                    COUNT(*) AS transitions,
                    AVG(s.seconds_in_prev_stage) AS avg_sec,
                    MIN(s.seconds_in_prev_stage) AS min_sec,
                    MAX(s.seconds_in_prev_stage) AS max_sec
               FROM leads_snapshots s
               JOIN leads_current l ON l.bitrix_id = s.lead_id
              WHERE s.change_type = 'status'
                AND s.recorded_at BETWEEN ? AND ?
                AND s.seconds_in_prev_stage IS NOT NULL
                AND {$where}
              GROUP BY s.prev_status_id, s.status_id",
            $params
        );
        $name = static fn (?string $id) => $id === null ? '—' : ($statuses[$id]['name'] ?? $id);
        $sortOf = static fn (?string $id) => $id === null ? -1 : ($statuses[$id]['sort'] ?? 0);

        $transitions = array_map(static fn ($r) => [
            'from' => $r['prev_status_id'],
            'to' => $r['status_id'],
            'from_name' => $name($r['prev_status_id']),
            'to_name' => $name($r['status_id']),
            'transitions' => (int) $r['transitions'],
            'avg_hours' => round((float) $r['avg_sec'] / 3600, 2),
            'min_hours' => round((float) $r['min_sec'] / 3600, 2),
            'max_hours' => round((float) $r['max_sec'] / 3600, 2),
        ], $pairs);
        usort($transitions, static fn ($a, $b) => [$sortOf($a['from']), $sortOf($a['to'])] <=> [$sortOf($b['from']), $sortOf($b['to'])]);

        // Среднее время на стадии: взвешенное по числу выходов из неё.
        $inStage = [];
        foreach ($pairs as $r) {
            $id = $r['prev_status_id'];
            $inStage[$id] ??= ['status_id' => $id, 'name' => $name($id), 'color' => $statuses[$id]['color'] ?? null, 'exits' => 0, 'sum_sec' => 0.0];
            $inStage[$id]['exits'] += (int) $r['transitions'];
            $inStage[$id]['sum_sec'] += (float) $r['avg_sec'] * (int) $r['transitions'];
        }
        $inStage = array_map(static fn ($s) => [
            'status_id' => $s['status_id'], 'name' => $s['name'], 'color' => $s['color'], 'exits' => $s['exits'],
            'avg_hours' => $s['exits'] ? round($s['sum_sec'] / $s['exits'] / 3600, 2) : 0.0,
        ], array_values($inStage));
        usort($inStage, static fn ($a, $b) => $sortOf($a['status_id']) <=> $sortOf($b['status_id']));

        return ['transitions' => $transitions, 'time_in_stage' => $inStage];
    }

    // ------------------------------------------------------------ Таймлайн

    /**
     * Что происходило на каждом 2-часовом цикле: новые лиды и переходы по целевым стадиям.
     * Если циклов слишком много (период 30+ дней), склеиваем по дням.
     */
    private function timeline(array $statuses, array $f): array
    {
        $runs = $this->db->all(
            "SELECT id, started_at FROM sync_runs
              WHERE status = 'success' AND started_at BETWEEN ? AND ?
              ORDER BY started_at",
            [$f['from'], $f['to']]
        );
        [$where, $params] = $this->leadFilter($f, 'l', false);
        $rows = $this->db->all(
            "SELECT s.sync_run_id, s.change_type, s.status_id, COUNT(*) AS cnt
               FROM leads_snapshots s
               JOIN sync_runs r ON r.id = s.sync_run_id
               JOIN leads_current l ON l.bitrix_id = s.lead_id
              WHERE r.status = 'success' AND r.started_at BETWEEN ? AND ?
                AND s.change_type IN ('created', 'status')
                AND {$where}
              GROUP BY s.sync_run_id, s.change_type, s.status_id",
            array_merge([$f['from'], $f['to']], $params)
        );

        // Первичная загрузка "создаёт" весь портал разом: такие created не показываем.
        $initialRun = $this->db->one("SELECT MIN(id) AS id FROM sync_runs WHERE status = 'success'");
        $initialRunId = (int) ($initialRun['id'] ?? 0);

        $byDay = count($runs) > self::MAX_TIMELINE_POINTS;
        $tz = $f['tz'];
        $bucketOf = static function (string $utc) use ($byDay, $tz): string {
            $d = (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($tz);
            return $byDay ? $d->format('Y-m-d') : $d->format('Y-m-d\TH:i');
        };
        $runBucket = [];
        $points = [];
        foreach ($runs as $r) {
            $b = $bucketOf($r['started_at']);
            $runBucket[(int) $r['id']] = $b;
            $points[$b] ??= ['at' => $b, 'new' => 0, 'transitions' => 0, 'by_status' => []];
        }
        foreach ($rows as $r) {
            $b = $runBucket[(int) $r['sync_run_id']] ?? null;
            if ($b === null) {
                continue;
            }
            $cnt = (int) $r['cnt'];
            if ($r['change_type'] === 'created') {
                if ((int) $r['sync_run_id'] !== $initialRunId) {
                    $points[$b]['new'] += $cnt;
                }
            } else {
                $points[$b]['transitions'] += $cnt;
                $points[$b]['by_status'][$r['status_id']] = ($points[$b]['by_status'][$r['status_id']] ?? 0) + $cnt;
            }
        }
        $series = [];
        foreach ($statuses as $id => $s) {
            $series[] = ['status_id' => $id, 'name' => $s['name'], 'color' => $s['color'], 'semantics' => $s['semantics']];
        }
        return ['granularity' => $byDay ? 'day' : 'run', 'points' => array_values($points), 'series' => $series];
    }

    // ------------------------------------------------------------ По дням

    private function daily(array $f, string $where, array $params): array
    {
        $expr = $this->db->localDateExpr('l.date_create', $f['offset_minutes']);
        $rows = $this->db->all(
            "SELECT {$expr} AS d, l.status_semantics, COUNT(*) AS cnt, SUM(l.opportunity) AS value
               FROM leads_current l
              WHERE {$where}
              GROUP BY {$expr}, l.status_semantics
              ORDER BY 1",
            $params
        );
        $days = [];
        // Заполняем пропуски нулями, чтобы на графике не было "дыр".
        $start = (new \DateTimeImmutable($f['from'], new \DateTimeZone('UTC')))->setTimezone($f['tz'])->setTime(0, 0);
        // Для "всего времени" начинаем с первого дня, в котором есть лиды, а не с 2000 года.
        if ($rows) {
            $firstDay = new \DateTimeImmutable(substr((string) $rows[0]['d'], 0, 10), $f['tz']);
            if ($firstDay > $start) {
                $start = $firstDay;
            }
        }
        $end = (new \DateTimeImmutable($f['to'], new \DateTimeZone('UTC')))->setTimezone($f['tz'])->setTime(0, 0);
        for ($d = $start; $d <= $end && count($days) < 400; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $days[$k] = ['date' => $k, 'leads' => 0, 'converted' => 0, 'junk' => 0, 'revenue' => 0.0];
        }
        foreach ($rows as $r) {
            $k = substr((string) $r['d'], 0, 10);
            $days[$k] ??= ['date' => $k, 'leads' => 0, 'converted' => 0, 'junk' => 0, 'revenue' => 0.0];
            $days[$k]['leads'] += (int) $r['cnt'];
            if ($r['status_semantics'] === 'S') {
                $days[$k]['converted'] += (int) $r['cnt'];
                $days[$k]['revenue'] += (float) $r['value'];
            } elseif ($r['status_semantics'] === 'F') {
                $days[$k]['junk'] += (int) $r['cnt'];
            }
        }
        ksort($days);
        return array_values($days);
    }

    // ------------------------------------------------------------ Хелперы

    /**
     * WHERE для leads_current: не удалён, [создан в периоде], [источники], [utm_source].
     * @return array{0:string,1:list<mixed>}
     */
    private function leadFilter(array $f, string $alias, bool $byCreateDate = true): array
    {
        $w = ["{$alias}.is_deleted = 0"];
        $p = [];
        if ($byCreateDate) {
            $w[] = "{$alias}.date_create BETWEEN ? AND ?";
            $p[] = $f['from'];
            $p[] = $f['to'];
        }
        if ($f['sources']) {
            $hasEmpty = in_array('', $f['sources'], true);
            $real = array_values(array_filter($f['sources'], static fn ($s) => $s !== ''));
            $parts = [];
            if ($real) {
                [$in, $inParams] = Db::inList($real);
                $parts[] = "{$alias}.source_id IN {$in}";
                array_push($p, ...$inParams);
            }
            if ($hasEmpty) {
                $parts[] = "{$alias}.source_id IS NULL";
            }
            $w[] = '(' . implode(' OR ', $parts) . ')';
        }
        if (($f['channel'] ?? null) !== null) {
            [$cw, $cp] = $this->channels->where($f['channel'], $alias);
            $w[] = $cw;
            array_push($p, ...$cp);
        }
        if ($f['utm_source'] !== null) {
            if ($f['utm_source'] === '') {
                $w[] = "{$alias}.utm_source IS NULL";
            } else {
                $w[] = "{$alias}.utm_source = ?";
                $p[] = $f['utm_source'];
            }
        }
        return [implode(' AND ', $w), $p];
    }

    /** @return array<string, array{name:string, sort:int, color:?string, semantics:string}> */
    private function statuses(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT status_id, name, sort, color, semantics FROM statuses_directory ORDER BY sort') as $r) {
            $out[$r['status_id']] = [
                'name' => $r['name'], 'sort' => (int) $r['sort'], 'color' => $r['color'], 'semantics' => $r['semantics'],
            ];
        }
        return $out;
    }

    /** @return array<string,string> */
    private function sourceNames(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT source_id, name FROM sources_directory') as $r) {
            $out[$r['source_id']] = $r['name'];
        }
        return $out;
    }

    private static function money(array $byCurrency): array
    {
        arsort($byCurrency);
        return array_map(static fn ($v) => round((float) $v, 2), $byCurrency);
    }

    private static function iso(?string $utc): ?string
    {
        return $utc === null ? null : (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->format('c');
    }
}

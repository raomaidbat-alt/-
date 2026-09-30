<?php
declare(strict_types=1);

namespace App\Analytics;

use App\Db;

/**
 * Лента "сырых событий" для фронтенда: один лид = одна запись с каналом, достигнутым шагом
 * воронки и моментами прохождения шагов (из журнала снапшотов).
 * Фронтенд сам агрегирует её по периоду и каналу и считает дельты к прошлому периоду.
 *
 * Персональных данных здесь нет: ни названий лидов, ни контактов.
 */
final class EventFeed
{
    public function __construct(
        private readonly Db $db,
        private readonly ChannelMap $channels,
        private readonly array $funnelCfg = [],
        private readonly bool $useDeals = true,
        private readonly ?SpendRepository $spend = null,
    ) {
    }

    /**
     * @param string $fromUtc нижняя граница date_create, 'Y-m-d H:i:s' UTC
     */
    public function build(string $fromUtc, string $toUtc, \DateTimeZone $tz): array
    {
        $statuses = $this->db->all('SELECT status_id, name, sort, color, semantics FROM statuses_directory ORDER BY sort');
        $sourceNames = [];
        foreach ($this->db->all('SELECT source_id, name FROM sources_directory') as $r) {
            $sourceNames[$r['source_id']] = $r['name'];
        }
        $processSorts = array_map(
            static fn ($s) => (int) $s['sort'],
            array_filter($statuses, static fn ($s) => $s['semantics'] === 'P')
        );
        $initialSort = $processSorts ? min($processSorts) : 0;
        $consultSort = null;
        // Стадию консультации можно указать кодом (UC_XXXX) или названием, как в Bitrix24.
        $consultCfg = mb_strtolower(trim((string) ($this->funnelCfg['consultation_status'] ?? '')));
        foreach ($statuses as $s) {
            if ($consultCfg !== '' && ($consultCfg === mb_strtolower($s['status_id']) || $consultCfg === mb_strtolower(trim($s['name'])))) {
                $consultSort = (int) $s['sort'];
            }
        }

        $leads = $this->db->all(
            "SELECT l.bitrix_id, l.date_create, l.source_id, l.utm_source, l.status_id, l.status_semantics,
                    l.max_stage_sort, l.is_qualified, l.opportunity, l.currency_id,
                    l.stage_entered_at, l.loss_reason
               FROM leads_current l
              WHERE l.is_deleted = 0 AND l.date_create BETWEEN ? AND ?
              ORDER BY l.date_create",
            [$fromUtc, $toUtc]
        );

        // Первый момент, когда лид оказался на шаге SQL / консультация / оплата.
        $milestones = [];
        $rows = $this->db->all(
            "SELECT s.lead_id,
                    MIN(CASE WHEN s.status_semantics <> 'F' AND d.sort > ? THEN s.stage_entered_at END) AS sql_at,
                    MIN(CASE WHEN s.status_semantics <> 'F' AND d.sort >= ? THEN s.stage_entered_at END) AS consult_at,
                    MIN(CASE WHEN s.status_semantics = 'S' THEN s.stage_entered_at END) AS paid_at,
                    MIN(CASE WHEN s.status_semantics = 'F' THEN s.stage_entered_at END) AS lost_at
               FROM leads_snapshots s
               JOIN statuses_directory d ON d.status_id = s.status_id
               JOIN leads_current l ON l.bitrix_id = s.lead_id
              WHERE l.is_deleted = 0 AND l.date_create BETWEEN ? AND ?
              GROUP BY s.lead_id",
            [$initialSort, $consultSort ?? 2147483647, $fromUtc, $toUtc] // стадии нет: INT_MAX, чтобы PostgreSQL не вышел за integer
        );
        foreach ($rows as $r) {
            $milestones[(int) $r['lead_id']] = $r;
        }

        // Основная валюта: та, в которой больше всего лидов.
        $cur = [];
        foreach ($leads as $l) {
            $c = $l['currency_id'] ?: 'RUB';
            $cur[$c] = ($cur[$c] ?? 0) + 1;
        }
        arsort($cur);
        $currency = $cur ? (string) array_key_first($cur) : 'RUB';

        // Самая дальняя рабочая стадия по sort: "на каком этапе отвалился".
        $progressStages = array_values(array_filter($statuses, static fn ($s) => $s['semantics'] !== 'F'));
        $stageAtSort = static function (int $sort) use ($progressStages): ?string {
            $best = null;
            foreach ($progressStages as $s) {
                if ((int) $s['sort'] <= $sort) {
                    $best = $s['status_id'];
                }
            }
            return $best ?? ($progressStages[0]['status_id'] ?? null);
        };

        // Выручка по сделкам: если в базе есть сделки из лидов, "оплата" = выигранная сделка,
        // сумма = сумма выигранных сделок, дата = момент выигрыша. Иначе считаем по лиду, как раньше.
        $dealsByLead = [];
        $revenueSource = 'leads';
        if ($this->useDeals && $this->db->one('SELECT 1 AS x FROM deals LIMIT 1') !== null) {
            $revenueSource = 'deals';
            $rows = $this->db->all(
                "SELECT d.lead_id, d.semantics, d.opportunity, d.currency_id, d.won_at
                   FROM deals d
                   JOIN leads_current l ON l.bitrix_id = d.lead_id
                  WHERE l.is_deleted = 0 AND l.date_create BETWEEN ? AND ?",
                [$fromUtc, $toUtc]
            );
            foreach ($rows as $r) {
                $lid = (int) $r['lead_id'];
                $dl = $dealsByLead[$lid] ?? ['count' => 0, 'lost' => 0, 'won' => 0, 'sum' => 0.0, 'wonAt' => null];
                $dl['count']++;
                if ($r['semantics'] === 'F') {
                    $dl['lost']++;
                } elseif ($r['semantics'] === 'S') {
                    $dl['won']++;
                    if (($r['currency_id'] ?: 'RUB') === $currency) {
                        $dl['sum'] += (float) $r['opportunity'];
                    }
                    if ($r['won_at'] !== null && ($dl['wonAt'] === null || $r['won_at'] < $dl['wonAt'])) {
                        $dl['wonAt'] = $r['won_at'];
                    }
                }
                $dealsByLead[$lid] = $dl;
            }
        }

        $iso = static fn (?string $utc) => $utc === null ? null
            : (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d\TH:i:sP');

        $events = [];
        $present = [];
        foreach ($leads as $l) {
            $id = (int) $l['bitrix_id'];
            $sem = $l['status_semantics'];
            $maxSort = (int) $l['max_stage_sort'];
            $stage = match (true) {
                $sem === 'S' => 'paid',
                $consultSort !== null && $maxSort >= $consultSort => 'consult',
                (int) $l['is_qualified'] === 1 => 'sql',
                default => 'lead',
            };
            $lost = $sem === 'F';
            $revenue = $sem === 'S' && ($l['currency_id'] ?: 'RUB') === $currency ? (float) $l['opportunity'] : 0.0;
            $paidAtUtc = null;
            $lossReason = $l['loss_reason'];
            if ($revenueSource === 'deals') {
                $dl = $dealsByLead[$id] ?? null;
                if ($dl !== null && $dl['won'] > 0) {
                    $stage = 'paid';
                    $revenue = $dl['sum'];
                    $paidAtUtc = $dl['wonAt'];
                } else {
                    $revenue = 0.0;
                    if ($stage === 'paid') {
                        // Лид сконвертирован, но сделка ещё не выиграна: он дошёл до консультации, не до оплаты.
                        $stage = $consultSort !== null ? 'consult' : 'sql';
                    }
                    if ($dl !== null && $dl['count'] > 0 && $dl['lost'] === $dl['count']) {
                        $lost = true;
                        $lossReason = $lossReason ?: 'Сделка проиграна';
                    }
                }
            }
            $channel = $this->channels->channelOf($l['source_id'], $l['utm_source']);
            $present[$channel] = true;
            $m = $milestones[$id] ?? [];
            $events[] = [
                'id' => (string) $id,
                'createdAt' => $iso($l['date_create']),
                'channel' => $channel,
                'stage' => $stage,
                'lost' => $lost,
                'revenue' => $revenue,
                'sqlAt' => $stage !== 'lead' ? $iso($m['sql_at'] ?? null) : null,
                'consultAt' => in_array($stage, ['consult', 'paid'], true) ? $iso($m['consult_at'] ?? $m['paid_at'] ?? null) : null,
                'paidAt' => $stage === 'paid' ? $iso($paidAtUtc ?? $m['paid_at'] ?? null) : null,
                'status' => $l['status_id'],
                'source' => $l['source_id'] !== null ? ($sourceNames[$l['source_id']] ?? $l['source_id']) : null,
                'utmSource' => $l['utm_source'],
                'amount' => (float) $l['opportunity'],
                'stageEnteredAt' => $iso($l['stage_entered_at']),
                'lostAt' => $lost ? $iso($m['lost_at'] ?? $l['stage_entered_at']) : null,
                'lostFrom' => $lost ? ($sem === 'F' ? $stageAtSort($maxSort) : $l['status_id']) : null,
                'lossReason' => $lost ? $lossReason : null,
            ];
        }

        // Охват и расходы по дням. Охват из конфига (месяц / 30.44). Расход: если для канала
        // внесены записи в дашборде, берём их (сумма делится на дни периода), иначе spend_per_month.
        $channelDaily = [];
        $firstDay = $leads ? (new \DateTimeImmutable($leads[0]['date_create'], new \DateTimeZone('UTC')))->setTimezone($tz) : null;
        $from = (new \DateTimeImmutable($fromUtc, new \DateTimeZone('UTC')))->setTimezone($tz);
        if ($firstDay !== null && $firstDay > $from) {
            $from = $firstDay; // для "всего времени" не рисуем охват с 2000 года
        }
        $to = (new \DateTimeImmutable($toUtc, new \DateTimeZone('UTC')))->setTimezone($tz);
        $manual = $this->spend?->perDay($from->format('Y-m-d'), $to->format('Y-m-d')) ?? [];
        $manualChannels = array_flip($this->spend?->channelsWithEntries() ?? []);
        for ($d = $from->setTime(0, 0); $d <= $to && count($channelDaily) < 20000; $d = $d->modify('+1 day')) {
            foreach ($this->channels->options() as $o) {
                $day = $d->format('Y-m-d');
                $spend = isset($manualChannels[$o['id']])
                    ? ($manual[$o['id']][$day] ?? 0.0)
                    : ($this->channels->spendPerDay($o['id']) ?? 0.0);
                $channelDaily[] = [
                    'date' => $day,
                    'channel' => $o['id'],
                    'reach' => (int) round($this->channels->reachPerDay($o['id']) ?? 0),
                    'spend' => round($spend, 2),
                ];
            }
        }

        $channels = $this->channels->options();
        if (isset($present[ChannelMap::OTHER])) {
            $channels[] = ['id' => ChannelMap::OTHER, 'name' => 'Другие источники'];
        }

        return [
            'currency' => $currency,
            'revenueSource' => $revenueSource,
            'spendEntries' => $this->spend?->list($from->format('Y-m-d'), $to->format('Y-m-d')) ?? [],
            'channels' => array_map(
                static fn ($c) => ['id' => $c['id'], 'label' => $c['name']] + array_intersect_key($c, ['icon' => 1, 'color' => 1]),
                $channels
            ),
            'rawEvents' => $events,
            'channelDaily' => $channelDaily,
            'statuses' => array_map(static fn ($s) => [
                'id' => $s['status_id'],
                'name' => $s['name'],
                'color' => $s['color'],
                'semantics' => $s['semantics'],
                'sort' => (int) $s['sort'],
            ], $statuses),
        ];
    }
}

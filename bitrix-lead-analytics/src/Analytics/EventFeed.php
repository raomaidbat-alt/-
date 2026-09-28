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
    ) {
    }

    /**
     * @param string $fromUtc нижняя граница date_create, 'Y-m-d H:i:s' UTC
     */
    public function build(string $fromUtc, string $toUtc, \DateTimeZone $tz): array
    {
        $statuses = $this->db->all('SELECT status_id, sort, semantics FROM statuses_directory');
        $processSorts = array_map(
            static fn ($s) => (int) $s['sort'],
            array_filter($statuses, static fn ($s) => $s['semantics'] === 'P')
        );
        $initialSort = $processSorts ? min($processSorts) : 0;
        $consultSort = null;
        foreach ($statuses as $s) {
            if ($s['status_id'] === ($this->funnelCfg['consultation_status'] ?? null)) {
                $consultSort = (int) $s['sort'];
            }
        }

        $leads = $this->db->all(
            "SELECT l.bitrix_id, l.date_create, l.source_id, l.utm_source, l.status_semantics,
                    l.max_stage_sort, l.is_qualified, l.opportunity, l.currency_id
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
                    MIN(CASE WHEN s.status_semantics = 'S' THEN s.stage_entered_at END) AS paid_at
               FROM leads_snapshots s
               JOIN statuses_directory d ON d.status_id = s.status_id
               JOIN leads_current l ON l.bitrix_id = s.lead_id
              WHERE l.is_deleted = 0 AND l.date_create BETWEEN ? AND ?
              GROUP BY s.lead_id",
            [$initialSort, $consultSort ?? PHP_INT_MAX, $fromUtc, $toUtc]
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
            $channel = $this->channels->channelOf($l['source_id'], $l['utm_source']);
            $present[$channel] = true;
            $m = $milestones[$id] ?? [];
            $sameCurrency = ($l['currency_id'] ?: 'RUB') === $currency;
            $events[] = [
                'id' => (string) $id,
                'createdAt' => $iso($l['date_create']),
                'channel' => $channel,
                'stage' => $stage,
                'lost' => $sem === 'F',
                'revenue' => $sem === 'S' && $sameCurrency ? (float) $l['opportunity'] : 0.0,
                'sqlAt' => $stage !== 'lead' ? $iso($m['sql_at'] ?? null) : null,
                'consultAt' => in_array($stage, ['consult', 'paid'], true) ? $iso($m['consult_at'] ?? null) : null,
                'paidAt' => $stage === 'paid' ? $iso($m['paid_at'] ?? null) : null,
            ];
        }

        // Охват и расходы по дням: в Bitrix24 их нет, берём из конфига (месяц / 30.44).
        $channelDaily = [];
        $firstDay = $leads ? (new \DateTimeImmutable($leads[0]['date_create'], new \DateTimeZone('UTC')))->setTimezone($tz) : null;
        $from = (new \DateTimeImmutable($fromUtc, new \DateTimeZone('UTC')))->setTimezone($tz);
        if ($firstDay !== null && $firstDay > $from) {
            $from = $firstDay; // для "всего времени" не рисуем охват с 2000 года
        }
        $to = (new \DateTimeImmutable($toUtc, new \DateTimeZone('UTC')))->setTimezone($tz);
        for ($d = $from->setTime(0, 0); $d <= $to && count($channelDaily) < 20000; $d = $d->modify('+1 day')) {
            foreach ($this->channels->options() as $o) {
                $channelDaily[] = [
                    'date' => $d->format('Y-m-d'),
                    'channel' => $o['id'],
                    'reach' => (int) round($this->channels->reachPerDay($o['id']) ?? 0),
                    'spend' => round($this->channels->spendPerDay($o['id']) ?? 0, 2),
                ];
            }
        }

        $channels = $this->channels->options();
        if (isset($present[ChannelMap::OTHER])) {
            $channels[] = ['id' => ChannelMap::OTHER, 'name' => 'Другие источники'];
        }

        return [
            'currency' => $currency,
            'channels' => array_map(static fn ($c) => ['id' => $c['id'], 'label' => $c['name']], $channels),
            'rawEvents' => $events,
            'channelDaily' => $channelDaily,
        ];
    }
}

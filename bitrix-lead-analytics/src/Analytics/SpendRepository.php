<?php
declare(strict_types=1);

namespace App\Analytics;

use App\Db;

/**
 * Расходы на каналы, внесённые в дашборде. Запись = сумма за период date_from..date_to
 * (день, месяц или любой отрезок); при расчётах сумма равномерно делится на дни периода.
 */
final class SpendRepository
{
    public const MAX_DAYS = 366;

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array{id:string,channel:string,dateFrom:string,dateTo:string,amount:float,comment:?string,createdAt:string}> */
    public function list(?string $fromDate = null, ?string $toDate = null, int $limit = 500): array
    {
        $where = '1 = 1';
        $params = [];
        if ($fromDate !== null && $toDate !== null) {
            $where = 'date_to >= ? AND date_from <= ?';
            $params = [$fromDate, $toDate];
        }
        $limit = max(1, min(2000, $limit));
        $rows = $this->db->all(
            "SELECT id, channel_key, date_from, date_to, amount, comment, created_at
               FROM channel_spend WHERE {$where}
              ORDER BY date_from DESC, id DESC LIMIT {$limit}",
            $params
        );
        return array_map(static fn ($r) => [
            'id' => (string) $r['id'],
            'channel' => $r['channel_key'],
            'dateFrom' => substr((string) $r['date_from'], 0, 10),
            'dateTo' => substr((string) $r['date_to'], 0, 10),
            'amount' => round((float) $r['amount'], 2),
            'comment' => $r['comment'],
            'createdAt' => (string) $r['created_at'],
        ], $rows);
    }

    public function add(string $channel, string $dateFrom, string $dateTo, float $amount, ?string $comment, string $nowUtc): int
    {
        return $this->db->insertGetId(
            'INSERT INTO channel_spend (channel_key, date_from, date_to, amount, comment, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$channel, $dateFrom, $dateTo, number_format($amount, 2, '.', ''), $comment, $nowUtc]
        );
    }

    public function delete(int $id): bool
    {
        return $this->db->exec('DELETE FROM channel_spend WHERE id = ?', [$id]) > 0;
    }

    /** Каналы, для которых есть хоть одна запись расходов (для них конфиг spend_per_month не используется). */
    public function channelsWithEntries(): array
    {
        return array_column($this->db->all('SELECT DISTINCT channel_key FROM channel_spend'), 'channel_key');
    }

    /**
     * Расход по дням: [channel][Y-m-d] => сумма.
     * @return array<string, array<string, float>>
     */
    public function perDay(string $fromDate, string $toDate): array
    {
        $out = [];
        foreach ($this->list($fromDate, $toDate, 2000) as $e) {
            $start = new \DateTimeImmutable($e['dateFrom']);
            $end = new \DateTimeImmutable($e['dateTo']);
            $days = (int) $start->diff($end)->days + 1;
            $perDay = $e['amount'] / max(1, $days);
            for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
                $k = $d->format('Y-m-d');
                if ($k < $fromDate || $k > $toDate) {
                    continue;
                }
                $out[$e['channel']][$k] = ($out[$e['channel']][$k] ?? 0) + $perDay;
            }
        }
        return $out;
    }
}

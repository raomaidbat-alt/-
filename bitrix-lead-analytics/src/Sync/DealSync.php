<?php
declare(strict_types=1);

namespace App\Sync;

use App\Bitrix\Client;
use App\Db;
use App\Logger;

/**
 * Сделки, созданные из лидов (LEAD_ID > 0). Нужны, чтобы считать выручку канала по выигранным
 * сделкам, а не по полю "Сумма" лида. Берутся только ID, связь с лидом, стадия, сумма и даты:
 * ни названий, ни контактов, ни ответственных.
 */
final class DealSync
{
    private const SELECT = [
        'ID', 'LEAD_ID', 'STAGE_ID', 'STAGE_SEMANTIC_ID', 'OPPORTUNITY', 'CURRENCY_ID',
        'DATE_CREATE', 'DATE_MODIFY', 'MOVED_TIME', 'CLOSEDATE', 'CLOSED',
    ];

    public function __construct(
        private readonly Db $db,
        private readonly Client $b24,
        private readonly Logger $log,
    ) {
    }

    /**
     * Забирает сделки из Bitrix24. Сетевые ошибки не валят синхронизацию лидов:
     * возвращается null, сделки обновятся в следующий раз.
     *
     * @return list<array<string,mixed>>|null
     */
    public function fetch(?string $modifiedFromUtc, ?string $createdFromUtc): ?array
    {
        $filter = ['>LEAD_ID' => 0];
        if ($modifiedFromUtc !== null) {
            $filter['>=DATE_MODIFY'] = gmdate('Y-m-d\TH:i:s+00:00', strtotime($modifiedFromUtc . ' UTC'));
        }
        if ($createdFromUtc !== null) {
            $filter['>=DATE_CREATE'] = gmdate('Y-m-d\TH:i:s+00:00', strtotime($createdFromUtc . ' UTC'));
        }
        $rows = [];
        try {
            foreach ($this->b24->listAll('crm.deal.list', ['order' => ['ID' => 'ASC'], 'select' => self::SELECT, 'filter' => $filter]) as $page) {
                foreach ($page as $raw) {
                    $id = (int) ($raw['ID'] ?? 0);
                    $leadId = (int) ($raw['LEAD_ID'] ?? 0);
                    if ($id > 0 && $leadId > 0) {
                        $rows[$id] = $this->map($raw);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->log->warning('Deals sync skipped', ['error' => $e->getMessage()]);
            return null;
        }
        return array_values($rows);
    }

    /**
     * @param list<array<string,mixed>> $deals
     */
    public function save(array $deals, int $runId, string $nowUtc): int
    {
        if (!$deals) {
            return 0;
        }
        foreach ($deals as &$d) {
            $d['last_seen_run_id'] = $runId;
            $d['synced_at'] = $nowUtc;
        }
        unset($d);
        return $this->db->upsert('deals', $deals, ['deal_id']);
    }

    /** После полной выгрузки: сделки из окна истории, которых не было в ответе, удалены в CRM. */
    public function deleteMissing(int $runId, ?string $createdFromUtc): int
    {
        return $this->db->exec(
            'DELETE FROM deals WHERE (last_seen_run_id IS NULL OR last_seen_run_id <> ?) AND (date_create IS NULL OR date_create >= ?)',
            [$runId, $createdFromUtc ?? '1970-01-01 00:00:00']
        );
    }

    private function map(array $raw): array
    {
        $sem = strtoupper((string) ($raw['STAGE_SEMANTIC_ID'] ?? 'P'));
        if (!in_array($sem, ['P', 'S', 'F'], true)) {
            $sem = 'P';
        }
        // Момент выигрыша: время перехода на стадию, иначе дата закрытия, иначе последнее изменение.
        $wonAt = null;
        if ($sem === 'S') {
            $wonAt = LeadMapper::utc($raw['MOVED_TIME'] ?? null)
                ?? LeadMapper::utc($raw['CLOSEDATE'] ?? null)
                ?? LeadMapper::utc($raw['DATE_MODIFY'] ?? null);
        }
        return [
            'deal_id' => (int) $raw['ID'],
            'lead_id' => (int) $raw['LEAD_ID'],
            'stage_id' => isset($raw['STAGE_ID']) ? mb_substr((string) $raw['STAGE_ID'], 0, 50) : null,
            'semantics' => $sem,
            'opportunity' => number_format((float) ($raw['OPPORTUNITY'] ?? 0), 2, '.', ''),
            'currency_id' => isset($raw['CURRENCY_ID']) && $raw['CURRENCY_ID'] !== '' ? mb_substr((string) $raw['CURRENCY_ID'], 0, 8) : null,
            'date_create' => LeadMapper::utc($raw['DATE_CREATE'] ?? null),
            'won_at' => $wonAt,
        ];
    }
}

<?php
declare(strict_types=1);

namespace App\Sync;

use App\Db;

/**
 * Запись порции лидов: upsert в leads_current и дописывание в leads_snapshots.
 *
 * Снапшот пишется, только если изменился хеш значимых полей (стадия, сумма, валюта,
 * ответственный, источник, UTM), лид новый или восстановлен после удаления.
 * Так журнал не раздувается строками "ничего не поменялось".
 */
final class LeadRepository
{
    private const CURRENT_COLUMNS = [
        'bitrix_id', 'status_id', 'status_semantics', 'max_stage_sort', 'is_qualified',
        'stage_entered_at', 'opportunity', 'currency_id', 'source_id',
        'utm_source', 'utm_medium', 'utm_campaign',
        'date_create', 'date_modify', 'custom_fields_enc', 'loss_reason', 'row_hash', 'is_deleted',
        'first_seen_at', 'last_synced_at', 'last_seen_run_id',
    ];

    public function __construct(
        private readonly Db $db,
        private readonly Directory $dir,
    ) {
    }

    /**
     * @param list<array> $leads результат LeadMapper::map(), без дублей по bitrix_id
     * @return array{upserted:int, snapshots:int}
     */
    public function saveChunk(array $leads, int $runId, string $nowUtc): array
    {
        if (!$leads) {
            return ['upserted' => 0, 'snapshots' => 0];
        }
        $existing = $this->loadExisting(array_column($leads, 'bitrix_id'));
        $nowTs = strtotime($nowUtc . ' UTC');

        $currentRows = [];
        $snapshots = [];
        foreach ($leads as $lead) {
            $id = $lead['bitrix_id'];
            $prev = $existing[$id] ?? null;
            $status = $lead['status_id'];
            $semantics = $this->dir->semanticsOf($status);
            $moved = $lead['_moved_time'];
            $movedTs = $moved !== null ? strtotime($moved . ' UTC') : null;

            $changeType = null;
            $prevStatus = null;
            $secondsInPrev = null;

            if ($prev === null) {
                $changeType = 'created';
                if ($movedTs !== null && $movedTs <= $nowTs) {
                    $enteredAt = $moved;
                } elseif ($this->dir->sortOf($status) === $this->dir->initialSort()) {
                    $enteredAt = $lead['date_create'];
                } else {
                    $enteredAt = $nowUtc; // стадия сменилась до первой синхронизации, точное время неизвестно
                }
                $maxSort = $this->dir->progressSort($status);
                $firstSeen = $nowUtc;
            } else {
                $maxSort = max((int) $prev['max_stage_sort'], $this->dir->progressSort($status));
                $firstSeen = $prev['first_seen_at'];
                $enteredAt = $prev['stage_entered_at'];

                if ($prev['status_id'] !== $status) {
                    $changeType = 'status';
                    $prevStatus = $prev['status_id'];
                    $prevEnteredTs = $prev['stage_entered_at'] !== null ? strtotime($prev['stage_entered_at'] . ' UTC') : null;
                    // MOVED_TIME точнее, чем "момент нашей синхронизации" (шаг 2 часа).
                    if ($movedTs !== null && $movedTs <= $nowTs && ($prevEnteredTs === null || $movedTs >= $prevEnteredTs)) {
                        $enteredAt = $moved;
                    } else {
                        $enteredAt = $nowUtc;
                    }
                    if ($prevEnteredTs !== null) {
                        $secondsInPrev = max(0, strtotime($enteredAt . ' UTC') - $prevEnteredTs);
                    }
                } elseif ($prev['row_hash'] !== $lead['row_hash'] || (int) $prev['is_deleted'] === 1) {
                    $changeType = 'update';
                }
            }

            $row = [
                'bitrix_id' => $id,
                'status_id' => $status,
                'status_semantics' => $semantics,
                'max_stage_sort' => $maxSort,
                'is_qualified' => $maxSort > $this->dir->initialSort() ? 1 : 0,
                'stage_entered_at' => $enteredAt,
                'opportunity' => $lead['opportunity'],
                'currency_id' => $lead['currency_id'],
                'source_id' => $lead['source_id'],
                'utm_source' => $lead['utm_source'],
                'utm_medium' => $lead['utm_medium'],
                'utm_campaign' => $lead['utm_campaign'],
                'date_create' => $lead['date_create'],
                'date_modify' => $lead['date_modify'],
                'custom_fields_enc' => $lead['custom_fields_enc'],
                'loss_reason' => $lead['loss_reason'] ?? null,
                'row_hash' => $lead['row_hash'],
                'is_deleted' => 0,
                'first_seen_at' => $firstSeen,
                'last_synced_at' => $nowUtc,
                'last_seen_run_id' => $runId,
            ];
            $currentRows[] = $row;

            if ($changeType !== null) {
                $snapshots[] = [
                    'lead_id' => $id,
                    'sync_run_id' => $runId,
                    'recorded_at' => $nowUtc,
                    'change_type' => $changeType,
                    'status_id' => $status,
                    'prev_status_id' => $prevStatus,
                    'status_semantics' => $semantics,
                    'stage_entered_at' => $enteredAt,
                    'seconds_in_prev_stage' => $secondsInPrev,
                    'opportunity' => $lead['opportunity'],
                    'currency_id' => $lead['currency_id'],
                    'source_id' => $lead['source_id'],
                    'is_deleted' => 0,
                    'row_hash' => $lead['row_hash'],
                ];
            }
        }

        return $this->db->transaction(function () use ($currentRows, $snapshots): array {
            $this->db->upsert('leads_current', $currentRows, ['bitrix_id'], array_values(array_diff(
                self::CURRENT_COLUMNS, ['bitrix_id', 'first_seen_at']
            )));
            $this->db->insertMany('leads_snapshots', $snapshots);
            return ['upserted' => count($currentRows), 'snapshots' => count($snapshots)];
        });
    }

    /**
     * После полной выгрузки: лиды, которых не было в ответе, помечаются удалёнными,
     * а в журнал уходит снапшот change_type = 'deleted'.
     */
    public function markMissingAsDeleted(int $runId, string $nowUtc, ?string $createdFrom = null): int
    {
        return $this->db->transaction(function () use ($runId, $nowUtc, $createdFrom): int {
            // При ограниченной глубине истории сверяем только лиды из этого окна:
            // более старые просто не выгружались, это не удаление.
            $gone = $this->db->all(
                'SELECT bitrix_id, status_id, status_semantics, stage_entered_at, opportunity, currency_id,
                        source_id, row_hash
                   FROM leads_current
                  WHERE is_deleted = 0 AND (last_seen_run_id IS NULL OR last_seen_run_id <> ?)
                    AND date_create >= ?',
                [$runId, $createdFrom ?? '1970-01-01 00:00:00']
            );
            if (!$gone) {
                return 0;
            }
            $snapshots = [];
            foreach ($gone as $g) {
                $snapshots[] = [
                    'lead_id' => (int) $g['bitrix_id'],
                    'sync_run_id' => $runId,
                    'recorded_at' => $nowUtc,
                    'change_type' => 'deleted',
                    'status_id' => $g['status_id'],
                    'prev_status_id' => null,
                    'status_semantics' => $g['status_semantics'],
                    'stage_entered_at' => $g['stage_entered_at'],
                    'seconds_in_prev_stage' => null,
                    'opportunity' => $g['opportunity'],
                    'currency_id' => $g['currency_id'],
                    'source_id' => $g['source_id'],
                    'is_deleted' => 1,
                    'row_hash' => $g['row_hash'],
                ];
            }
            $this->db->insertMany('leads_snapshots', $snapshots);
            foreach (array_chunk(array_column($gone, 'bitrix_id'), 1000) as $ids) {
                [$in, $params] = Db::inList($ids);
                $this->db->exec(
                    "UPDATE leads_current SET is_deleted = 1, last_synced_at = ? WHERE bitrix_id IN {$in}",
                    array_merge([$nowUtc], $params)
                );
            }
            return count($gone);
        });
    }

    /** @return array<int, array> */
    private function loadExisting(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            [$in, $params] = Db::inList($chunk);
            $rows = $this->db->all(
                "SELECT bitrix_id, status_id, stage_entered_at, max_stage_sort, row_hash, is_deleted, first_seen_at
                   FROM leads_current WHERE bitrix_id IN {$in}",
                $params
            );
            foreach ($rows as $r) {
                $out[(int) $r['bitrix_id']] = $r;
            }
        }
        return $out;
    }
}

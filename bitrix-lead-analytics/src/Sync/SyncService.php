<?php
declare(strict_types=1);

namespace App\Sync;

use App\Bitrix\Client;
use App\Db;
use App\Logger;

/**
 * Один запуск синхронизации: справочники → выгрузка лидов → запись порциями.
 *
 * Режимы:
 *  - full: все лиды портала; после выгрузки пропавшие помечаются удалёнными.
 *    Включается сам, если успешных запусков ещё не было.
 *  - incremental: лиды с DATE_MODIFY >= (старт прошлого успешного запуска − overlap).
 */
final class SyncService
{
    public function __construct(
        private readonly Db $db,
        private readonly Client $b24,
        private readonly Logger $log,
        private readonly array $cfg,
    ) {
    }

    /**
     * @return array<string,int|string> итоговая статистика запуска
     */
    public function run(bool $forceFull = false, bool $dryRun = false): array
    {
        $now = self::nowUtc();
        $lastSuccess = $this->db->one(
            "SELECT id, started_at FROM sync_runs WHERE status = 'success' ORDER BY started_at DESC LIMIT 1"
        );
        $mode = ($forceFull || $lastSuccess === null) ? 'full' : 'incremental';
        $watermark = null;
        if ($mode === 'incremental') {
            $overlap = max(0, (int) ($this->cfg['sync']['overlap_minutes'] ?? 15));
            $watermark = gmdate('Y-m-d H:i:s', strtotime($lastSuccess['started_at'] . ' UTC') - $overlap * 60);
        }

        $runId = $this->db->insertGetId(
            'INSERT INTO sync_runs (mode, status, started_at, watermark_from) VALUES (?, ?, ?, ?)',
            [$mode, 'running', $now, $watermark]
        );
        $this->log->info('Sync started', ['run_id' => $runId, 'mode' => $mode, 'watermark_from' => $watermark, 'dry_run' => $dryRun]);

        $stats = ['fetched' => 0, 'upserted' => 0, 'snapshots' => 0, 'deleted' => 0];
        try {
            $dir = new Directory($this->db);
            $dir->refreshFromBitrix($this->b24, $now);
            $repo = new LeadRepository($this->db, $dir);

            // Весь запуск в одной транзакции: упавший синк не оставляет в базе
            // половину лидов и снапшотов, следующий запуск начнёт с того же водяного знака.
            $this->db->pdo->beginTransaction();

            $requested = array_map('strtoupper', $this->cfg['sync']['custom_fields'] ?? []);
            $customFields = array_values(array_diff($requested, LeadMapper::FORBIDDEN));
            if ($blocked = array_intersect($requested, LeadMapper::FORBIDDEN)) {
                $this->log->warning('Personal data fields removed from sync.custom_fields', ['fields' => array_values($blocked)]);
            }
            $select = LeadMapper::select($customFields);

            // Причина отказа: одно поле лида (обычно список "Причина отказа"). Хранится открытым текстом,
            // поэтому годится только поле с вариантами ответа, без персональных данных.
            $lossField = strtoupper(trim((string) ($this->cfg['sync']['loss_reason_field'] ?? '')));
            $lossItems = [];
            if ($lossField !== '') {
                if (!preg_match('/^UF_CRM_[A-Z0-9_]+$/', $lossField)) {
                    throw new \InvalidArgumentException('sync.loss_reason_field must be a UF_CRM_* code');
                }
                $select[] = $lossField;
                $fields = $this->b24->call('crm.lead.fields')['result'] ?? [];
                foreach ($fields[$lossField]['items'] ?? [] as $item) {
                    $lossItems[(string) ($item['ID'] ?? '')] = (string) ($item['VALUE'] ?? '');
                }
            }
            $crypto = null;
            if ($customFields) {
                $key = (string) ($this->cfg['security']['encryption_key'] ?? '');
                if ($key === '') {
                    throw new \RuntimeException('sync.custom_fields is set, but security.encryption_key is empty (run bin/generate_key.php)');
                }
                $crypto = new \App\Crypto($key);
            }
            $params = [
                'order' => ['ID' => 'ASC'],
                'select' => $select,
                'filter' => [],
            ];
            if ($watermark !== null) {
                // Bitrix24 понимает ISO 8601 с поясом в фильтрах по датам.
                $params['filter']['>=DATE_MODIFY'] = gmdate('Y-m-d\TH:i:s+00:00', strtotime($watermark . ' UTC'));
            }
            // Глубина истории: берём только лиды, созданные за последние N дней (0 = все).
            $createdFrom = null;
            $historyDays = (int) ($this->cfg['sync']['history_days'] ?? 0);
            if ($historyDays > 0) {
                $createdFrom = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - $historyDays * 86400);
                $params['filter']['>=DATE_CREATE'] = gmdate('Y-m-d\TH:i:s+00:00', strtotime($createdFrom . ' UTC'));
            }

            // Сделки из лидов (для выручки) забираем до записи, сбой здесь не мешает лидам.
            $dealSync = null;
            $deals = null;
            if ($this->cfg['sync']['deals'] ?? true) {
                $dealSync = new DealSync($this->db, $this->b24, $this->log);
                $deals = $dealSync->fetch($watermark, $createdFrom);
            }

            $seen = [];
            foreach ($this->b24->listAll('crm.lead.list', $params) as $page) {
                $stats['fetched'] += count($page);
                $leads = [];
                foreach ($page as $raw) {
                    $id = (int) ($raw['ID'] ?? 0);
                    // Смещения start могут "поплыть", если лиды меняются во время выгрузки.
                    if ($id <= 0 || isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $lead = LeadMapper::map($raw, $customFields, $crypto);
                    $lead['loss_reason'] = $lossField !== '' ? self::lossReason($raw[$lossField] ?? null, $lossItems) : null;
                    $leads[] = $lead;
                }
                if ($dryRun) {
                    continue;
                }
                $r = $repo->saveChunk($leads, $runId, $now);
                $stats['upserted'] += $r['upserted'];
                $stats['snapshots'] += $r['snapshots'];
                $this->log->debug('Chunk saved', ['run_id' => $runId, 'leads' => count($leads)] + $r);
            }

            if ($mode === 'full' && !$dryRun) {
                $stats['deleted'] = $repo->markMissingAsDeleted($runId, $now, $createdFrom);
            }
            if ($dealSync !== null && $deals !== null && !$dryRun) {
                $stats['deals'] = count($deals);
                $dealSync->save($deals, $runId, $now);
                if ($mode === 'full') {
                    $dealSync->deleteMissing($runId, $createdFrom);
                }
            }
            $this->db->pdo->commit();

            $this->finish($runId, $dryRun ? 'dry_run' : 'success', $stats, null);
            $this->log->info('Sync finished', ['run_id' => $runId, 'mode' => $mode, 'api_calls' => $this->b24->apiCalls()] + $stats);
            return ['run_id' => $runId, 'mode' => $mode, 'api_calls' => $this->b24->apiCalls()] + $stats;
        } catch (\Throwable $e) {
            if ($this->db->pdo->inTransaction()) {
                $this->db->pdo->rollBack();
            }
            $stats['upserted'] = $stats['snapshots'] = $stats['deleted'] = 0; // откатились
            $this->finish($runId, 'failed', $stats, $e->getMessage());
            $this->log->error('Sync failed', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
                'type' => $e::class,
                'at' => $e->getFile() . ':' . $e->getLine(),
            ] + $stats);
            throw $e;
        }
    }

    /** Значение поля причины: вариант списка превращается в текст, пустое в null. */
    private static function lossReason(mixed $value, array $items): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        $text = $items[(string) $value] ?? (string) $value;
        return mb_substr(trim($text), 0, 255) ?: null;
    }

    private function finish(int $runId, string $status, array $stats, ?string $error): void
    {
        $this->db->exec(
            'UPDATE sync_runs SET status = ?, finished_at = ?, leads_fetched = ?, leads_upserted = ?,
                    snapshots_written = ?, leads_deleted = ?, api_calls = ?, error_message = ?
              WHERE id = ?',
            [
                $status, self::nowUtc(), $stats['fetched'], $stats['upserted'],
                $stats['snapshots'], $stats['deleted'], $this->b24->apiCalls(),
                $error !== null ? mb_substr($error, 0, 4000) : null, $runId,
            ]
        );
    }

    /**
     * Текущее время в UTC. Переменная SYNC_NOW нужна только тестам с мок-порталом,
     * чтобы прогнать "двухчасовые" циклы за секунды.
     */
    public static function nowUtc(): string
    {
        $fake = getenv('SYNC_NOW');
        if ($fake !== false && $fake !== '') {
            return gmdate('Y-m-d H:i:s', strtotime($fake));
        }
        return gmdate('Y-m-d H:i:s');
    }
}

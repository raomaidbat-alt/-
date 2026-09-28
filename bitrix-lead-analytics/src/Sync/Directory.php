<?php
declare(strict_types=1);

namespace App\Sync;

use App\Bitrix\Client;
use App\Db;

/**
 * Справочники стадий лида (crm.status.list, ENTITY_ID=STATUS) и источников (ENTITY_ID=SOURCE).
 * Хранит их в statuses_directory / sources_directory и отвечает на вопросы
 * "какой sort у стадии" и "это успех, провал или работа".
 */
final class Directory
{
    /** @var array<string, array{name:string, sort:int, color:?string, semantics:string}> */
    private array $statuses = [];
    private int $initialSort = 0;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Тянет оба справочника одним batch-запросом и сохраняет в БД.
     */
    public function refreshFromBitrix(Client $b24, string $nowUtc): void
    {
        $res = $b24->batch([
            'statuses' => 'crm.status.list?' . http_build_query([
                'order' => ['SORT' => 'ASC'], 'filter' => ['ENTITY_ID' => 'STATUS'],
            ]),
            'sources' => 'crm.status.list?' . http_build_query([
                'order' => ['SORT' => 'ASC'], 'filter' => ['ENTITY_ID' => 'SOURCE'],
            ]),
        ]);

        $statusRows = [];
        foreach ($res['result']['statuses'] ?? [] as $s) {
            $statusRows[] = [
                'status_id' => (string) $s['STATUS_ID'],
                'name' => mb_substr((string) ($s['NAME'] ?? $s['STATUS_ID']), 0, 255),
                'sort' => (int) ($s['SORT'] ?? 0),
                'color' => self::color($s),
                'semantics' => self::semantics($s),
                'updated_at' => $nowUtc,
            ];
        }
        $sourceRows = [];
        foreach ($res['result']['sources'] ?? [] as $s) {
            $sourceRows[] = [
                'source_id' => (string) $s['STATUS_ID'],
                'name' => mb_substr((string) ($s['NAME'] ?? $s['STATUS_ID']), 0, 255),
                'sort' => (int) ($s['SORT'] ?? 0),
                'color' => self::color($s),
                'updated_at' => $nowUtc,
            ];
        }
        if (!$statusRows) {
            throw new \RuntimeException('crm.status.list returned no lead stages (check webhook CRM permissions)');
        }

        $this->db->transaction(function () use ($statusRows, $sourceRows): void {
            $this->db->upsert('statuses_directory', $statusRows, ['status_id']);
            $this->db->upsert('sources_directory', $sourceRows, ['source_id']);
        });
        $this->load();
    }

    /** Загружает стадии из БД (для API и повторных запусков). */
    public function load(): void
    {
        $this->statuses = [];
        foreach ($this->db->all('SELECT status_id, name, sort, color, semantics FROM statuses_directory ORDER BY sort') as $r) {
            $this->statuses[$r['status_id']] = [
                'name' => $r['name'],
                'sort' => (int) $r['sort'],
                'color' => $r['color'],
                'semantics' => $r['semantics'],
            ];
        }
        $processSorts = array_column(
            array_filter($this->statuses, static fn ($s) => $s['semantics'] === 'P'),
            'sort'
        );
        $this->initialSort = $processSorts ? min($processSorts) : 0;
    }

    public function semanticsOf(string $statusId): string
    {
        return $this->statuses[$statusId]['semantics'] ?? 'P';
    }

    public function sortOf(string $statusId): int
    {
        return $this->statuses[$statusId]['sort'] ?? $this->initialSort;
    }

    /** sort первой рабочей стадии (обычно NEW). */
    public function initialSort(): int
    {
        return $this->initialSort;
    }

    /**
     * Насколько далеко по воронке продвинулся лид: для рабочих стадий и успеха
     * это sort стадии, для провала (брак) позиция не растёт.
     */
    public function progressSort(string $statusId): int
    {
        return $this->semanticsOf($statusId) === 'F' ? $this->initialSort : $this->sortOf($statusId);
    }

    /** @return array<string, array{name:string, sort:int, color:?string, semantics:string}> */
    public function statuses(): array
    {
        return $this->statuses;
    }

    private static function semantics(array $s): string
    {
        $top = strtoupper((string) ($s['SEMANTICS'] ?? ''));
        if ($top === 'S' || $top === 'F') {
            return $top;
        }
        $extra = strtolower((string) ($s['EXTRA']['SEMANTICS'] ?? ''));
        return match ($extra) {
            'success' => 'S',
            'failure', 'apology' => 'F',
            default => 'P',
        };
    }

    private static function color(array $s): ?string
    {
        $c = (string) ($s['COLOR'] ?? ($s['EXTRA']['COLOR'] ?? ''));
        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) ? strtoupper($c) : null;
    }
}

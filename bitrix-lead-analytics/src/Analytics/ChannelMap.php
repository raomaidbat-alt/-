<?php
declare(strict_types=1);

namespace App\Analytics;

use App\Db;

/**
 * Маркетинговые каналы из config.php (секция channels).
 * Лид относится к каналу, если его SOURCE_ID есть в sources канала; иначе, если
 * utm_source (без учёта регистра) есть в utm_sources. Всё остальное: канал "other".
 */
final class ChannelMap
{
    public const OTHER = 'other';

    /** @var array<string,string> SOURCE_ID => канал */
    private array $bySource = [];
    /** @var array<string,string> utm_source (lower) => канал */
    private array $byUtm = [];

    /**
     * Каналы из конфига с привязкой источников по названию: source_names (как в Bitrix24)
     * превращаются в SOURCE_ID по справочнику sources_directory. Регистр и пробелы не важны.
     */
    public static function fromConfig(array $channels, Db $db): self
    {
        $byName = [];
        foreach ($db->all('SELECT source_id, name FROM sources_directory') as $r) {
            $byName[self::norm((string) $r['name'])][] = (string) $r['source_id'];
        }
        foreach ($channels as &$ch) {
            foreach ($ch['source_names'] ?? [] as $name) {
                foreach ($byName[self::norm((string) $name)] ?? [] as $id) {
                    $ch['sources'][] = $id;
                }
            }
        }
        unset($ch);
        return new self($channels);
    }

    private static function norm(string $s): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)) ?? '');
    }

    /**
     * @param array<string, array{label?:string, sources?:list<string>, source_names?:list<string>,
     *        utm_sources?:list<string>, spend_per_month?:float|int, reach_per_month?:int,
     *        icon?:string, color?:string}> $channels
     */
    public function __construct(private readonly array $channels)
    {
        foreach ($channels as $key => $ch) {
            foreach ($ch['sources'] ?? [] as $s) {
                $this->bySource[(string) $s] ??= $key;
            }
            foreach ($ch['utm_sources'] ?? [] as $u) {
                $this->byUtm[mb_strtolower((string) $u)] ??= $key;
            }
        }
    }

    public function channelOf(?string $sourceId, ?string $utmSource): string
    {
        if ($sourceId !== null && isset($this->bySource[$sourceId])) {
            return $this->bySource[$sourceId];
        }
        if ($utmSource !== null && isset($this->byUtm[mb_strtolower($utmSource)])) {
            return $this->byUtm[mb_strtolower($utmSource)];
        }
        return self::OTHER;
    }

    public function has(string $key): bool
    {
        return $key === self::OTHER || isset($this->channels[$key]);
    }

    /** @return list<array{id:string,name:string}> */
    public function options(): array
    {
        $out = [];
        foreach ($this->channels as $key => $ch) {
            $out[] = array_filter([
                'id' => $key,
                'name' => $ch['label'] ?? $key,
                'icon' => $ch['icon'] ?? null,
                'color' => isset($ch['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $ch['color']) ? $ch['color'] : null,
            ], static fn ($v) => $v !== null);
        }
        return $out;
    }

    /** @return array<string, array> */
    public function all(): array
    {
        return $this->channels;
    }

    public function spendPerDay(string $key): ?float
    {
        $v = $this->channels[$key]['spend_per_month'] ?? null;
        return $v === null ? null : (float) $v / 30.44;
    }

    public function reachPerDay(string $key): ?float
    {
        $v = $this->channels[$key]['reach_per_month'] ?? null;
        return $v === null ? null : (float) $v / 30.44;
    }

    /**
     * SQL-условие "лид относится к каналу" с той же логикой, что channelOf().
     * @return array{0:string,1:list<mixed>}
     */
    public function where(string $channel, string $a): array
    {
        $allSources = array_keys($this->bySource);
        $allUtm = array_keys($this->byUtm);
        $freeSource = static function () use ($allSources, $a): array {
            if (!$allSources) {
                return ['1 = 1', []];
            }
            [$in, $p] = Db::inList($allSources);
            return ["({$a}.source_id IS NULL OR {$a}.source_id NOT IN {$in})", $p];
        };

        if ($channel === self::OTHER) {
            [$fs, $fp] = $freeSource();
            if (!$allUtm) {
                return [$fs, $fp];
            }
            [$in, $up] = Db::inList($allUtm);
            return ["({$fs} AND ({$a}.utm_source IS NULL OR LOWER({$a}.utm_source) NOT IN {$in}))", array_merge($fp, $up)];
        }

        $mySources = array_keys(array_filter($this->bySource, static fn ($k) => $k === $channel));
        $myUtm = array_keys(array_filter($this->byUtm, static fn ($k) => $k === $channel));
        $parts = [];
        $params = [];
        if ($mySources) {
            [$in, $p] = Db::inList($mySources);
            $parts[] = "{$a}.source_id IN {$in}";
            array_push($params, ...$p);
        }
        if ($myUtm) {
            [$fs, $fp] = $freeSource();
            [$in, $up] = Db::inList($myUtm);
            $parts[] = "(LOWER({$a}.utm_source) IN {$in} AND {$fs})";
            array_push($params, ...$up, ...$fp);
        }
        return $parts ? ['(' . implode(' OR ', $parts) . ')', $params] : ['1 = 0', []];
    }
}

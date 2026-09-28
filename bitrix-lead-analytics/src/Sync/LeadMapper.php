<?php
declare(strict_types=1);

namespace App\Sync;

/**
 * Приводит сырую запись crm.lead.list к колонкам leads_current.
 * Даты Bitrix24 приходят в ISO 8601 с поясом портала, в БД кладём UTC.
 */
final class LeadMapper
{
    /** Что запрашиваем у crm.lead.list. Телефоны и e-mail намеренно не тянем: аналитике они не нужны. */
    public const SELECT = [
        'ID', 'TITLE', 'STATUS_ID', 'OPPORTUNITY', 'CURRENCY_ID',
        'DATE_CREATE', 'DATE_MODIFY', 'MOVED_TIME',
        'SOURCE_ID', 'SOURCE_DESCRIPTION', 'ASSIGNED_BY_ID',
        'UTM_SOURCE', 'UTM_MEDIUM', 'UTM_CAMPAIGN', 'UTM_CONTENT', 'UTM_TERM',
        'UF_*',
    ];

    /** Поля, изменение которых порождает новый снапшот. */
    private const HASHED = [
        'status_id', 'opportunity', 'currency_id', 'assigned_by_id', 'source_id',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
    ];

    /**
     * @return array<string,mixed> нормализованная запись + служебное поле _moved_time
     */
    public static function map(array $raw): array
    {
        $custom = [];
        foreach ($raw as $k => $v) {
            if (str_starts_with((string) $k, 'UF_CRM_')) {
                $custom[$k] = $v;
            }
        }
        ksort($custom);

        $lead = [
            'bitrix_id' => (int) $raw['ID'],
            'title' => self::str($raw['TITLE'] ?? null, 500),
            'status_id' => (string) ($raw['STATUS_ID'] ?? 'NEW'),
            'opportunity' => number_format((float) ($raw['OPPORTUNITY'] ?? 0), 2, '.', ''),
            'currency_id' => self::str($raw['CURRENCY_ID'] ?? null, 8),
            'source_id' => self::str($raw['SOURCE_ID'] ?? null, 50),
            'source_description' => self::str($raw['SOURCE_DESCRIPTION'] ?? null, 65000),
            'assigned_by_id' => isset($raw['ASSIGNED_BY_ID']) && $raw['ASSIGNED_BY_ID'] !== '' ? (int) $raw['ASSIGNED_BY_ID'] : null,
            'utm_source' => self::str($raw['UTM_SOURCE'] ?? null, 255),
            'utm_medium' => self::str($raw['UTM_MEDIUM'] ?? null, 255),
            'utm_campaign' => self::str($raw['UTM_CAMPAIGN'] ?? null, 255),
            'utm_content' => self::str($raw['UTM_CONTENT'] ?? null, 255),
            'utm_term' => self::str($raw['UTM_TERM'] ?? null, 255),
            'date_create' => self::utc($raw['DATE_CREATE'] ?? null) ?? gmdate('Y-m-d H:i:s'),
            'date_modify' => self::utc($raw['DATE_MODIFY'] ?? null),
            'custom_fields' => $custom ? json_encode($custom, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            '_moved_time' => self::utc($raw['MOVED_TIME'] ?? null),
        ];
        $lead['row_hash'] = self::hash($lead);
        return $lead;
    }

    public static function hash(array $lead): string
    {
        $parts = [];
        foreach (self::HASHED as $f) {
            $parts[] = (string) ($lead[$f] ?? '');
        }
        return hash('sha256', implode("\x1F", $parts));
    }

    /** ISO 8601 (любой пояс) → 'Y-m-d H:i:s' в UTC. */
    public static function utc(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    private static function str(mixed $v, int $max): ?string
    {
        if ($v === null || is_array($v)) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}

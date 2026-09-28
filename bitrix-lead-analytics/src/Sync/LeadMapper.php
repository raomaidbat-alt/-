<?php
declare(strict_types=1);

namespace App\Sync;

/**
 * Приводит сырую запись crm.lead.list к колонкам leads_current.
 * Даты Bitrix24 приходят в ISO 8601 с поясом портала, в БД кладём UTC.
 *
 * Персональные данные лида не запрашиваются вовсе: ни имя, ни фамилия, ни название лида
 * (там часто стоит имя клиента), ни телефоны, e-mail, мессенджеры, адрес, компания,
 * ни свободный текст "Дополнительно об источнике", ни ID сотрудников (ответственный, автор),
 * ни UTM content/term, куда иногда подставляют данные клиента. Пользовательские поля UF_CRM_*
 * выгружаются только из явного списка в config.php и хранятся зашифрованными.
 */
final class LeadMapper
{
    /** Белый список полей crm.lead.list. Ничего сверх него портал не отдаёт. */
    public const SELECT = [
        'ID', 'STATUS_ID', 'OPPORTUNITY', 'CURRENCY_ID',
        'DATE_CREATE', 'DATE_MODIFY', 'MOVED_TIME',
        'SOURCE_ID',
        'UTM_SOURCE', 'UTM_MEDIUM', 'UTM_CAMPAIGN',
    ];

    /**
     * Поля с персональными данными: запрещены даже в списке custom_fields конфига.
     * Страховка от ошибки в настройке.
     */
    public const FORBIDDEN = [
        'TITLE', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'HONORIFIC', 'BIRTHDATE', 'POST',
        'PHONE', 'EMAIL', 'WEB', 'IM', 'LINK', 'ADDRESS', 'COMPANY_TITLE', 'COMMENTS',
        'SOURCE_DESCRIPTION', 'STATUS_DESCRIPTION', 'CONTACT_ID', 'CONTACT_IDS', 'COMPANY_ID',
        'ASSIGNED_BY_ID', 'CREATED_BY_ID', 'MODIFY_BY_ID', 'UTM_CONTENT', 'UTM_TERM',
    ];

    /**
     * Итоговый select: базовые поля + разрешённые пользовательские поля.
     * @param list<string> $customFields коды UF_CRM_* из config.php (sync.custom_fields)
     * @return list<string>
     */
    public static function select(array $customFields): array
    {
        $extra = [];
        foreach ($customFields as $code) {
            $code = strtoupper(trim((string) $code));
            if (!preg_match('/^UF_CRM_[A-Z0-9_]+$/', $code)) {
                throw new \InvalidArgumentException("sync.custom_fields: only UF_CRM_* codes are allowed, got {$code}");
            }
            $extra[] = $code;
        }
        return array_values(array_unique(array_merge(self::SELECT, $extra)));
    }

    /** Поля, изменение которых порождает новый снапшот. */
    private const HASHED = [
        'status_id', 'opportunity', 'currency_id', 'source_id',
        'utm_source', 'utm_medium', 'utm_campaign',
    ];

    /**
     * @param list<string> $customFields разрешённые UF_CRM_* (только они попадут в БД)
     * @return array<string,mixed> нормализованная запись + служебное поле _moved_time
     */
    public static function map(array $raw, array $customFields = [], ?\App\Crypto $crypto = null): array
    {
        // Берём только разрешённые поля, даже если портал прислал больше.
        $custom = [];
        foreach ($customFields as $code) {
            if (array_key_exists($code, $raw)) {
                $custom[$code] = $raw[$code];
            }
        }
        ksort($custom);
        $customEnc = null;
        if ($custom) {
            if ($crypto === null) {
                throw new \RuntimeException('Custom fields require security.encryption_key');
            }
            $customEnc = $crypto->encrypt(json_encode($custom, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $lead = [
            'bitrix_id' => (int) $raw['ID'],
            'status_id' => (string) ($raw['STATUS_ID'] ?? 'NEW'),
            'opportunity' => number_format((float) ($raw['OPPORTUNITY'] ?? 0), 2, '.', ''),
            'currency_id' => self::str($raw['CURRENCY_ID'] ?? null, 8),
            'source_id' => self::str($raw['SOURCE_ID'] ?? null, 50),
            'utm_source' => self::str($raw['UTM_SOURCE'] ?? null, 255),
            'utm_medium' => self::str($raw['UTM_MEDIUM'] ?? null, 255),
            'utm_campaign' => self::str($raw['UTM_CAMPAIGN'] ?? null, 255),
            'date_create' => self::utc($raw['DATE_CREATE'] ?? null) ?? gmdate('Y-m-d H:i:s'),
            'date_modify' => self::utc($raw['DATE_MODIFY'] ?? null),
            'custom_fields_enc' => $customEnc,
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

<?php
declare(strict_types=1);

namespace App\Bitrix;

use App\Logger;

/**
 * Клиент REST API Bitrix24 через входящий вебхук.
 *
 * - троттлинг: не чаще одного запроса в min_interval_ms (лимит портала ~2 rps);
 * - повторы с экспоненциальной задержкой и джиттером на 429/5xx, QUERY_LIMIT_EXCEEDED,
 *   OPERATION_TIME_LIMIT и сетевые ошибки cURL; уважает заголовок Retry-After;
 * - listAll(): постраничная выгрузка списков пачками через batch
 *   (до 50 команд × 50 записей = 2500 записей за один HTTP-запрос).
 */
final class Client
{
    /** Коды ошибок Bitrix24, после которых имеет смысл повторить запрос. */
    private const RETRYABLE_CODES = [
        'QUERY_LIMIT_EXCEEDED',
        'OPERATION_TIME_LIMIT',
        'INTERNAL_SERVER_ERROR',
        'ERROR_BATCH_LENGTH_EXCEEDED',
        'PORTAL_DELETED_TEMPORARILY',
    ];
    private const RETRYABLE_HTTP = [408, 429, 500, 502, 503, 504];
    public const PAGE_SIZE = 50;

    private string $baseUrl;
    private float $lastCallAt = 0.0;
    private int $apiCalls = 0;
    /** @var \CurlHandle|null */
    private $curl = null;

    public function __construct(
        string $webhookUrl,
        private readonly Logger $log,
        private readonly array $opt = [],
    ) {
        if (!preg_match('~^https?://.+/rest/\d+/[^/]+/?$~', $webhookUrl)) {
            throw new \InvalidArgumentException(
                'Webhook URL must look like https://portal.bitrix24.ru/rest/<user_id>/<token>/'
            );
        }
        // Токен вебхука и данные CRM идут только по HTTPS с проверкой сертификата.
        if (!str_starts_with($webhookUrl, 'https://') && empty($opt['allow_insecure_http'])) {
            throw new \InvalidArgumentException('Webhook URL must use https:// (plain http is allowed only for local tests)');
        }
        $this->baseUrl = rtrim($webhookUrl, '/') . '/';
    }

    public function __destruct()
    {
        if ($this->curl !== null) {
            curl_close($this->curl);
        }
    }

    public function apiCalls(): int
    {
        return $this->apiCalls;
    }

    /**
     * Один вызов метода REST. Возвращает весь ответ: result, total, next, time.
     */
    public function call(string $method, array $params = []): array
    {
        $maxRetries = (int) ($this->opt['max_retries'] ?? 6);
        $attempt = 0;
        while (true) {
            try {
                return $this->request($method, $params);
            } catch (BitrixApiException $e) {
                if (!$e->retryable || $attempt >= $maxRetries) {
                    throw $e;
                }
                $delayMs = $this->backoffMs($attempt, $e->retryAfterSec);
                $this->log->warning('Bitrix24 call failed, retrying', [
                    'method' => $method,
                    'attempt' => $attempt + 1,
                    'code' => $e->errorCode,
                    'http' => $e->httpStatus,
                    'delay_ms' => $delayMs,
                    'error' => $e->getMessage(),
                ]);
                usleep($delayMs * 1000);
                $attempt++;
            }
        }
    }

    /**
     * batch: до 50 команд в одном HTTP-запросе.
     *
     * @param array<string,string> $commands ключ => "method?query"
     * @return array{result: array, result_error: array, result_total: array, result_next: array}
     */
    public function batch(array $commands, bool $halt = false): array
    {
        if (count($commands) > 50) {
            throw new \InvalidArgumentException('Bitrix24 batch accepts at most 50 commands');
        }
        $maxRetries = (int) ($this->opt['max_retries'] ?? 6);
        $attempt = 0;
        while (true) {
            $resp = $this->call('batch', ['halt' => $halt ? 1 : 0, 'cmd' => $commands]);
            $r = $resp['result'] ?? [];
            $errors = self::asArray($r['result_error'] ?? []);

            // Лимит мог сработать на отдельных командах внутри batch: повторяем весь пакет.
            $retryable = null;
            foreach ($errors as $key => $err) {
                $code = is_array($err) ? (string) ($err['error'] ?? '') : '';
                if (in_array($code, self::RETRYABLE_CODES, true)) {
                    $retryable = $code;
                    break;
                }
            }
            if ($retryable !== null && $attempt < $maxRetries) {
                $delayMs = $this->backoffMs($attempt, null);
                $this->log->warning('Bitrix24 batch sub-command limited, retrying batch', [
                    'code' => $retryable, 'attempt' => $attempt + 1, 'delay_ms' => $delayMs,
                ]);
                usleep($delayMs * 1000);
                $attempt++;
                continue;
            }
            if ($errors) {
                $first = reset($errors);
                throw new BitrixApiException(
                    'Batch command failed: ' . json_encode($errors, JSON_UNESCAPED_UNICODE),
                    is_array($first) ? (string) ($first['error'] ?? 'BATCH_ERROR') : 'BATCH_ERROR',
                );
            }
            return [
                'result' => self::asArray($r['result'] ?? []),
                'result_error' => $errors,
                'result_total' => self::asArray($r['result_total'] ?? []),
                'result_next' => self::asArray($r['result_next'] ?? []),
            ];
        }
    }

    /**
     * Полная выгрузка списочного метода (crm.lead.list и т.п.).
     * Первый запрос узнаёт total, остальные страницы идут пачками через batch.
     * Отдаёт записи порциями: одна порция = один HTTP-запрос.
     *
     * Сортировка обязательно стабильная (по ID), иначе смещения start "поплывут".
     *
     * @return \Generator<int, array<int,array>>
     */
    public function listAll(string $method, array $params): \Generator
    {
        $params['order'] = $params['order'] ?? ['ID' => 'ASC'];
        unset($params['start']);

        $first = $this->call($method, $params + ['start' => 0]);
        $rows = self::asArray($first['result'] ?? []);
        $total = (int) ($first['total'] ?? count($rows));
        $this->log->info('List started', ['method' => $method, 'total' => $total]);
        if ($rows) {
            yield $rows;
        }
        if ($total <= self::PAGE_SIZE) {
            return;
        }

        $perBatch = max(1, min(50, (int) ($this->opt['batch_commands'] ?? 50)));
        $offsets = [];
        for ($offset = self::PAGE_SIZE; $offset < $total; $offset += self::PAGE_SIZE) {
            $offsets[] = $offset;
        }
        foreach (array_chunk($offsets, $perBatch) as $chunk) {
            $commands = [];
            foreach ($chunk as $offset) {
                $commands['p' . $offset] = $method . '?' . http_build_query($params + ['start' => $offset]);
            }
            $res = $this->batch($commands);
            $merged = [];
            foreach (array_keys($commands) as $key) {
                foreach (self::asArray($res['result'][$key] ?? []) as $row) {
                    $merged[] = $row;
                }
            }
            if ($merged) {
                yield $merged;
            }
        }
    }

    // ---------------------------------------------------------------------

    private function request(string $method, array $params): array
    {
        $this->throttle();
        $this->apiCalls++;

        if ($this->curl === null) {
            $this->curl = curl_init();
        } else {
            curl_reset($this->curl);
        }
        $retryAfter = null;
        curl_setopt_array($this->curl, [
            CURLOPT_URL => $this->baseUrl . $method . '.json',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => (int) ($this->opt['connect_timeout'] ?? 10),
            CURLOPT_TIMEOUT => (int) ($this->opt['timeout'] ?? 60),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'b24-lead-analytics/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => empty($this->opt['allow_insecure_http']) ? CURLPROTO_HTTPS : (CURLPROTO_HTTPS | CURLPROTO_HTTP),
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$retryAfter): int {
                if (stripos($header, 'Retry-After:') === 0) {
                    $v = trim(substr($header, 12));
                    $retryAfter = ctype_digit($v) ? (int) $v : null;
                }
                return strlen($header);
            },
        ]);

        $body = curl_exec($this->curl);
        $errno = curl_errno($this->curl);
        $http = (int) curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE);

        if ($errno !== 0 || $body === false) {
            throw new BitrixApiException(
                'cURL error ' . $errno . ': ' . curl_error($this->curl),
                'CURL_' . $errno, 0, true,
            );
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new BitrixApiException(
                'Non-JSON response (HTTP ' . $http . '): ' . mb_substr((string) $body, 0, 200),
                'BAD_RESPONSE', $http, in_array($http, self::RETRYABLE_HTTP, true) || $http === 0, $retryAfter,
            );
        }
        if (isset($data['error'])) {
            $code = (string) $data['error'];
            $retryable = in_array($code, self::RETRYABLE_CODES, true) || in_array($http, self::RETRYABLE_HTTP, true);
            throw new BitrixApiException(
                $code . ': ' . (string) ($data['error_description'] ?? ''),
                $code, $http, $retryable, $retryAfter,
            );
        }
        if ($http >= 400) {
            throw new BitrixApiException(
                'HTTP ' . $http, 'HTTP_' . $http, $http, in_array($http, self::RETRYABLE_HTTP, true), $retryAfter,
            );
        }
        return $data;
    }

    private function throttle(): void
    {
        $minInterval = ((int) ($this->opt['min_interval_ms'] ?? 550)) / 1000;
        $wait = $this->lastCallAt + $minInterval - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastCallAt = microtime(true);
    }

    private function backoffMs(int $attempt, ?int $retryAfterSec): int
    {
        $base = (int) ($this->opt['base_delay_ms'] ?? 1000);
        $max = (int) ($this->opt['max_delay_ms'] ?? 60000);
        $delay = min($max, $base * (2 ** $attempt));
        $delay += random_int(0, (int) ($delay * 0.25)); // джиттер, чтобы не биться в лимит синхронно
        if ($retryAfterSec !== null) {
            $delay = max($delay, $retryAfterSec * 1000);
        }
        return (int) min($delay, $max);
    }

    /** Bitrix24 отдаёт пустые коллекции то как [], то как {}. */
    private static function asArray(mixed $v): array
    {
        return is_array($v) ? $v : [];
    }
}

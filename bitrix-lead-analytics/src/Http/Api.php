<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Общая обвязка JSON-эндпоинтов: заголовки, CORS, метод, токен, ответ.
 */
final class Api
{
    /**
     * Заголовки, CORS и проверка метода/токена. Возвращает конфиг.
     * @param list<string> $methods разрешённые методы (по умолчанию только чтение)
     */
    public static function boot(array $methods = ['GET']): array
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        try {
            $cfg = app_config();
        } catch (\Throwable) {
            self::respond(500, ['error' => 'config_missing']);
        }

        // CORS: только если явно разрешён другой Origin.
        $allowedOrigin = $cfg['api']['allowed_origin'] ?? null;
        if ($allowedOrigin && ($_SERVER['HTTP_ORIGIN'] ?? '') === $allowedOrigin) {
            header('Access-Control-Allow-Origin: ' . $allowedOrigin);
            header('Vary: Origin');
            header('Access-Control-Allow-Headers: Authorization, X-Api-Token, Content-Type');
            header('Access-Control-Allow-Methods: ' . implode(', ', $methods) . ', OPTIONS');
        }
        // Токен и данные ходят только по HTTPS. Исключение: запросы с этой же машины (разработка).
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        if (!$https && !$local && ($cfg['api']['require_https'] ?? true)) {
            self::respond(403, ['error' => 'https_required']);
        }
        if ($https) {
            header('Strict-Transport-Security: max-age=31536000');
        }
        header('Referrer-Policy: no-referrer');

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        if (!in_array($method, $methods, true)) {
            self::respond(405, ['error' => 'method_not_allowed']);
        }

        $expected = (string) ($cfg['api']['token'] ?? '');
        if ($expected !== '') {
            $given = '';
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
            if (stripos($auth, 'Bearer ') === 0) {
                $given = trim(substr($auth, 7));
            } elseif (!empty($_SERVER['HTTP_X_API_TOKEN'])) {
                $given = trim((string) $_SERVER['HTTP_X_API_TOKEN']);
            }
            if ($given === '' || !hash_equals($expected, $given)) {
                usleep(random_int(100_000, 300_000)); // притормаживаем перебор
                self::respond(401, ['error' => 'unauthorized']);
            }
        }
        return $cfg;
    }

    public static function respond(int $status, array $body): never
    {
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

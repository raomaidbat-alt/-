<?php
declare(strict_types=1);

/**
 * Общая точка входа: автозагрузка классов App\* из src/ и чтение конфига.
 * Composer не нужен: только стандартные расширения PHP (pdo, curl, json).
 */

if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, "PHP 8.1+ required\n");
    exit(1);
}

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/**
 * Конфиг ищется так: переменная окружения APP_CONFIG → config.php в корне проекта.
 */
function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $path = getenv('APP_CONFIG') ?: dirname(__DIR__) . '/config.php';
    if (!is_file($path)) {
        throw new RuntimeException(
            "Config not found: {$path}. Copy config.example.php to config.php and fill it in."
        );
    }
    $config = require $path;
    date_default_timezone_set('UTC');
    return $config;
}

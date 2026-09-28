<?php
declare(strict_types=1);

/**
 * Конфиг для Docker: секреты и адреса берутся из .env (через config.example.php),
 * каналы, стадии и прочие настройки аналитики из config.local.php (его создаёт deploy/init.sh).
 */
$config = require dirname(__DIR__) . '/config.example.php';

$local = dirname(__DIR__) . '/config.local.php';
if (is_file($local)) {
    $override = require $local;
    // Каналы заменяются целиком, чтобы примеры из config.example.php не смешивались с вашими.
    if (isset($override['channels'])) {
        $config['channels'] = $override['channels'];
        unset($override['channels']);
    }
    $config = array_replace_recursive($config, $override);
}

// Логи и lock-файл в общем томе /app/var.
$config['app']['log_dir'] = '/app/var/log';
$config['sync']['lock_file'] = '/app/var/sync.lock';

return $config;

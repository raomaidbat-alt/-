<?php
declare(strict_types=1);

/**
 * Этап 2. Создаёт таблицы в БД из config.php (MySQL или PostgreSQL по DSN).
 * Повторный запуск безопасен: всё через IF NOT EXISTS / DROP IF EXISTS для триггеров.
 *
 *   php bin/install.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Db;

$cfg = app_config();
$db = Db::fromConfig($cfg['db']);
$file = dirname(__DIR__) . "/sql/{$db->driver}/001_schema.sql";

$statements = Db::splitSql((string) file_get_contents($file));
$skipped = 0;
foreach ($statements as $sql) {
    try {
        $db->pdo->exec($sql);
    } catch (PDOException $e) {
        // На виртуальном хостинге (reg.ru, Timeweb, Beget) у пользователя MySQL нет SUPER,
        // и при включённом binlog триггеры не создаются (ошибка 1419). Таблицы от этого
        // не страдают, журнал снапшотов по-прежнему только дописывается кодом синхронизации.
        $isTrigger = (bool) preg_match('/^\s*(CREATE|DROP)\s+TRIGGER/i', $sql);
        if ($isTrigger && in_array((int) ($e->errorInfo[1] ?? 0), [1419, 1227, 1142], true)) {
            $skipped++;
            continue;
        }
        throw $e;
    }
}
echo "Schema applied ({$db->driver}): " . (count($statements) - $skipped) . " statements from {$file}\n";
if ($skipped) {
    echo "Warning: {$skipped} trigger statements skipped (no SUPER privilege). "
        . "Append-only protection of leads_snapshots is enforced by the application only.\n";
}

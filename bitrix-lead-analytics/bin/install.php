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
// Колонки, добавленные после первой версии: на уже работающей базе дописываем их сами.
$addColumns = [
    ['leads_current', 'loss_reason', 'VARCHAR(255) NULL'],
];
// Колонки, которые больше не храним (ID сотрудников, UTM content/term): удаляем вместе с данными.
$dropColumns = [
    ['leads_current', 'assigned_by_id'],
    ['leads_current', 'utm_content'],
    ['leads_current', 'utm_term'],
    ['leads_snapshots', 'assigned_by_id'],
];
foreach ($dropColumns as [$table, $column]) {
    $exists = $db->one(
        $db->driver === 'mysql'
            ? 'SELECT 1 AS x FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            : 'SELECT 1 AS x FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
        [$table, $column]
    );
    if ($exists !== null) {
        $db->pdo->exec("ALTER TABLE {$table} DROP COLUMN {$column}");
        echo "Column dropped: {$table}.{$column}\n";
    }
}

foreach ($addColumns as [$table, $column, $type]) {
    $exists = $db->one(
        $db->driver === 'mysql'
            ? 'SELECT 1 AS x FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            : 'SELECT 1 AS x FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
        [$table, $column]
    );
    if ($exists === null) {
        $db->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$type}");
        echo "Column added: {$table}.{$column}\n";
    }
}

echo "Schema applied ({$db->driver}): " . (count($statements) - $skipped) . " statements from {$file}\n";
if ($skipped) {
    echo "Warning: {$skipped} trigger statements skipped (no SUPER privilege). "
        . "Append-only protection of leads_snapshots is enforced by the application only.\n";
}

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
foreach ($statements as $sql) {
    $db->pdo->exec($sql);
}
echo "Schema applied ({$db->driver}): " . count($statements) . " statements from {$file}\n";

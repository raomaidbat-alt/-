<?php
declare(strict_types=1);

/**
 * Этап 3. ETL: Bitrix24 → leads_current + leads_snapshots.
 *
 *   php bin/sync_leads.php            # инкремент (первый запуск сам станет полным)
 *   php bin/sync_leads.php --full     # полная выгрузка + пометка удалённых лидов
 *   php bin/sync_leads.php --dry-run  # только выгрузить и посчитать, ничего не писать
 *
 * cron:
 *   0 *\/2 * * *  php /path/bin/sync_leads.php >> /path/var/log/cron.log 2>&1
 *   30 3 * * 0   php /path/bin/sync_leads.php --full >> /path/var/log/cron.log 2>&1
 *
 * Коды выхода: 0 успех, 1 ошибка синхронизации, 2 уже идёт другой запуск.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Bitrix\Client;
use App\Db;
use App\Logger;
use App\Sync\SyncService;

$opts = getopt('', ['full', 'dry-run', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php bin/sync_leads.php [--full] [--dry-run]\n";
    exit(0);
}

$cfg = app_config();
$log = new Logger('sync', $cfg['app']['log_dir'] ?? null, $cfg['app']['log_level'] ?? 'info');

// Не даём двум cron-запускам работать одновременно.
$lockPath = $cfg['sync']['lock_file'] ?? dirname(__DIR__) . '/var/sync.lock';
if (!is_dir(dirname($lockPath))) {
    mkdir(dirname($lockPath), 0775, true);
}
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $log->warning('Another sync is running, exiting', ['lock' => $lockPath]);
    exit(2);
}

set_time_limit(0);

try {
    $db = Db::fromConfig($cfg['db']);
    $b24 = new Client($cfg['bitrix']['webhook_url'], $log, $cfg['bitrix']);
    $service = new SyncService($db, $b24, $log, $cfg);
    $result = $service->run(isset($opts['full']), isset($opts['dry-run']));
    echo json_encode($result, JSON_UNESCAPED_UNICODE), "\n";
    $code = 0;
} catch (Throwable $e) {
    $log->error('Fatal', ['error' => $e->getMessage(), 'type' => $e::class]);
    $code = 1;
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
exit($code);

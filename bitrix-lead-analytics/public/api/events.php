<?php
declare(strict_types=1);

/**
 * Сырые события для React-дашборда (DashboardData в MarketingFunnelDashboard.tsx).
 *
 * GET /api/events.php?period=7d|30d|quarter|all
 *   Отдаёт лиды за ДВА периода подряд (текущий + предыдущий), чтобы фронтенд
 *   посчитал дельты "к прошлому периоду". Для all отдаёт всё.
 *
 * Авторизация как у analytics.php: "Authorization: Bearer <token>" или "X-Api-Token".
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Analytics\Analytics;
use App\Analytics\ChannelMap;
use App\Analytics\EventFeed;
use App\Analytics\SpendRepository;
use App\Db;
use App\Http\Api;
use App\Logger;

$cfg = Api::boot();

$tz = new DateTimeZone($cfg['app']['timezone'] ?? 'Europe/Moscow');
$now = new DateTimeImmutable('now', $tz);
$period = (string) ($_GET['period'] ?? '30d');
$days = match ($period) {
    '7d' => 7,
    '30d' => 30,
    'quarter', '90d' => 90,
    'all' => null,
    default => Api::respond(400, ['error' => 'bad_period']),
};

// Текущий период считается с начала дня (days − 1) дней назад, плюс такой же период перед ним.
$from = $days === null
    ? new DateTimeImmutable('2000-01-01 00:00:00', $tz)
    : $now->setTime(0, 0)->modify('-' . (2 * $days - 1) . ' days');

$utc = new DateTimeZone('UTC');
try {
    $db = Db::fromConfig($cfg['db']);
    $channels = ChannelMap::fromConfig($cfg['channels'] ?? [], $db);
    $feed = (new EventFeed($db, $channels, $cfg['funnel'] ?? [], (bool) ($cfg['sync']['deals'] ?? true), new SpendRepository($db)))->build(
        $from->setTimezone($utc)->format('Y-m-d H:i:s'),
        $now->setTimezone($utc)->format('Y-m-d H:i:s'),
        $tz
    );
    $sync = (new Analytics($db))->syncStatus();

    Api::respond(200, [
        'meta' => [
            'source' => 'api',
            'period' => $period,
            'generatedAt' => $now->format('c'),
            'lastSyncAt' => $sync['last_success']['finished_at'] ?? null,
            'lastSyncStatus' => $sync['last_run']['status'] ?? null,
            'currency' => $feed['currency'],
            // Откуда выручка: "deals" (выигранные сделки из лидов) или "leads" (сумма лида в успешной стадии).
            'revenueSource' => $feed['revenueSource'],
            'timezone' => $tz->getName(),
            // Как часто идёт синхронизация: дашборд по нему решает, "Live" данные или устарели.
            'syncIntervalMinutes' => max(1, (int) round(((int) (getenv('SYNC_INTERVAL_SECONDS') ?: 900)) / 60)),
            // Для ссылок "открыть лид в Bitrix24": только адрес портала, без токена вебхука.
            'portalUrl' => ($host = parse_url((string) ($cfg['bitrix']['webhook_url'] ?? ''), PHP_URL_HOST)) ? 'https://' . $host : null,
        ],
        'channels' => $feed['channels'],
        'rawEvents' => $feed['rawEvents'],
        'channelDaily' => $feed['channelDaily'],
        'statuses' => $feed['statuses'],
        'spendEntries' => $feed['spendEntries'],
    ]);
} catch (Throwable $e) {
    (new Logger('api', $cfg['app']['log_dir'] ?? null, 'error', false))
        ->error('Events API failed', ['error' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
    Api::respond(500, ['error' => 'internal_error']);
}

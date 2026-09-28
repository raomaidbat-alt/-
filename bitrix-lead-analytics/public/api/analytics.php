<?php
declare(strict_types=1);

/**
 * Этап 4. JSON API для дашборда.
 *
 * GET /api/analytics.php
 *   period      24h | 7d | 30d | 90d | quarter | all | custom   (по умолчанию 30d)
 *   from, to    YYYY-MM-DD в поясе app.timezone        (для period=custom)
 *   sources     SOURCE_ID через запятую; "_none" = без источника
 *   utm_source  значение utm_source; "_none" = без метки
 *   channel     ключ канала из config.php (channels) или "other"
 *
 * Авторизация: заголовок "Authorization: Bearer <token>" или "X-Api-Token: <token>".
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Analytics\Analytics;
use App\Analytics\ChannelMap;
use App\Db;
use App\Http\Api;
use App\Logger;

$cfg = Api::boot();

// Период
$tz = new DateTimeZone($cfg['app']['timezone'] ?? 'Europe/Moscow');
$now = new DateTimeImmutable('now', $tz);
$period = (string) ($_GET['period'] ?? '30d');
$dateRe = '/^\d{4}-\d{2}-\d{2}$/';

switch ($period) {
    case '24h':
        $from = $now->modify('-24 hours');
        $to = $now;
        break;
    case '7d':
    case '30d':
    case '90d':
    case 'quarter':
        $days = $period === 'quarter' ? 90 : (int) $period;
        $from = $now->setTime(0, 0)->modify('-' . ($days - 1) . ' days');
        $to = $now;
        break;
    case 'all':
        $from = new DateTimeImmutable('2000-01-01 00:00:00', $tz);
        $to = $now;
        break;
    case 'custom':
        $f = (string) ($_GET['from'] ?? '');
        $t = (string) ($_GET['to'] ?? '');
        if (!preg_match($dateRe, $f) || !preg_match($dateRe, $t)) {
            Api::respond(400, ['error' => 'bad_range', 'message' => 'from/to must be YYYY-MM-DD']);
        }
        $from = new DateTimeImmutable($f . ' 00:00:00', $tz);
        $to = new DateTimeImmutable($t . ' 23:59:59', $tz);
        if ($from > $to) {
            [$from, $to] = [$to->setTime(0, 0), $from->setTime(23, 59, 59)];
        }
        if ($from->diff($to)->days > 400) {
            Api::respond(400, ['error' => 'range_too_long', 'message' => 'Max 400 days']);
        }
        break;
    default:
        Api::respond(400, ['error' => 'bad_period']);
}

// Фильтры
$sources = [];
if (!empty($_GET['sources'])) {
    foreach (explode(',', (string) $_GET['sources']) as $s) {
        $s = trim($s);
        if ($s === '_none') {
            $sources[] = '';
        } elseif ($s !== '' && preg_match('/^[A-Za-z0-9_|\-:.]{1,50}$/', $s)) {
            $sources[] = $s;
        }
    }
    $sources = array_values(array_unique(array_slice($sources, 0, 100)));
}
$utm = null;
if (isset($_GET['utm_source']) && $_GET['utm_source'] !== '') {
    $utm = $_GET['utm_source'] === '_none' ? '' : mb_substr((string) $_GET['utm_source'], 0, 255);
}

$channels = new ChannelMap($cfg['channels'] ?? []);
$channel = null;
if (!empty($_GET['channel'])) {
    $channel = (string) $_GET['channel'];
    if (!$channels->has($channel)) {
        Api::respond(400, ['error' => 'bad_channel']);
    }
}

$utc = new DateTimeZone('UTC');
$filter = [
    'from' => $from->setTimezone($utc)->format('Y-m-d H:i:s'),
    'to' => $to->setTimezone($utc)->format('Y-m-d H:i:s'),
    'sources' => $sources,
    'utm_source' => $utm,
    'channel' => $channel,
    'compare' => $period !== 'all',
    'tz' => $tz,
    'offset_minutes' => intdiv($to->getOffset(), 60),
];

try {
    $db = Db::fromConfig($cfg['db']);
    $channels = ChannelMap::fromConfig($cfg['channels'] ?? [], $db);
    $an = new Analytics($db, $channels, $cfg['funnel'] ?? []);
    $t0 = microtime(true);
    $data = $an->build($filter);
    Api::respond(200, [
        'meta' => [
            'period' => $period,
            'from' => $from->format('c'),
            'to' => $to->format('c'),
            'timezone' => $tz->getName(),
            'filters' => ['sources' => $sources, 'utm_source' => $utm, 'channel' => $channel],
            'generated_at' => (new DateTimeImmutable('now', $tz))->format('c'),
            'query_ms' => (int) round((microtime(true) - $t0) * 1000),
            'sync' => $an->syncStatus(),
            'options' => $an->filterOptions() + ['channels' => $channels->options()],
        ],
    ] + $data);
} catch (Throwable $e) {
    (new Logger('api', $cfg['app']['log_dir'] ?? null, 'error', false))
        ->error('Analytics API failed', ['error' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
    Api::respond(500, ['error' => 'internal_error']);
}

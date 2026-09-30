<?php
declare(strict_types=1);

/**
 * Расходы на каналы.
 *
 *   GET    /api/spend.php                 последние записи
 *   POST   /api/spend.php                 {"channel":"lead_harvester","dateFrom":"2026-09-01","dateTo":"2026-09-30","amount":30000,"comment":"..."}
 *   DELETE /api/spend.php?id=123
 *
 * Авторизация как у остальных эндпоинтов: "Authorization: Bearer <token>".
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Analytics\SpendRepository;
use App\Db;
use App\Http\Api;
use App\Logger;

$cfg = Api::boot(['GET', 'POST', 'DELETE']);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $repo = new SpendRepository(Db::fromConfig($cfg['db']));

    if ($method === 'GET') {
        Api::respond(200, ['entries' => $repo->list()]);
    }

    if ($method === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            Api::respond(400, ['error' => 'bad_id']);
        }
        Api::respond($repo->delete($id) ? 200 : 404, ['deleted' => $id]);
    }

    // POST
    $raw = file_get_contents('php://input', false, null, 0, 10_000);
    $in = json_decode((string) $raw, true);
    if (!is_array($in)) {
        Api::respond(400, ['error' => 'bad_json']);
    }
    $channel = (string) ($in['channel'] ?? '');
    if (!isset($cfg['channels'][$channel])) {
        Api::respond(400, ['error' => 'bad_channel', 'message' => 'Канал не найден в настройках']);
    }
    $re = '/^\d{4}-\d{2}-\d{2}$/';
    $from = (string) ($in['dateFrom'] ?? '');
    $to = (string) ($in['dateTo'] ?? $from);
    if (!preg_match($re, $from) || !preg_match($re, $to) || !strtotime($from) || !strtotime($to)) {
        Api::respond(400, ['error' => 'bad_date', 'message' => 'Даты в формате ГГГГ-ММ-ДД']);
    }
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
    if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days >= SpendRepository::MAX_DAYS) {
        Api::respond(400, ['error' => 'range_too_long', 'message' => 'Период не больше года']);
    }
    $amount = is_numeric($in['amount'] ?? null) ? (float) $in['amount'] : -1;
    if ($amount <= 0 || $amount > 1_000_000_000) {
        Api::respond(400, ['error' => 'bad_amount', 'message' => 'Сумма больше нуля']);
    }
    $comment = trim(strip_tags((string) ($in['comment'] ?? '')));
    $comment = $comment === '' ? null : mb_substr($comment, 0, 255);

    $id = $repo->add($channel, $from, $to, $amount, $comment, gmdate('Y-m-d H:i:s'));
    Api::respond(201, ['id' => (string) $id]);
} catch (Throwable $e) {
    (new Logger('api', $cfg['app']['log_dir'] ?? null, 'error', false))
        ->error('Spend API failed', ['error' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
    Api::respond(500, ['error' => 'internal_error']);
}

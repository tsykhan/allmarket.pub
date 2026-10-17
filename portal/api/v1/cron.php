<?php
/**
 * Delivers order webhooks to sellers' shops (new and changed orders).
 *   * * * * *  php /path/to/httpdocs/api/v1/cron.php
 * or over HTTP when config cron_token is set: /api/v1/cron.php?token=…
 */
require __DIR__ . '/bootstrap.php';
$cfg = amapi_config();
if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    $token = isset($_GET['token']) ? (string) $_GET['token'] : '';
    if ($cfg['cron_token'] === '' || !hash_equals((string) $cfg['cron_token'], $token)) {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'error' => 'forbidden'));
        exit;
    }
}
$lock = fopen(sys_get_temp_dir() . '/amapi_cron_' . md5(__DIR__) . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo json_encode(array('ok' => true, 'skipped' => 'already running')), "\n";
    exit;
}
$stats = AmWebhook::dispatch(amapi_db(), $cfg);
echo json_encode(array('ok' => true, 'sellers' => $stats), JSON_UNESCAPED_UNICODE), "\n";

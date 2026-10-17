<?php
/***************************************************************************
 * allmarket.biz Sync — storefront endpoints.                              *
 *                                                                         *
 *   index.php?dispatch=allmarket_sync.webhook                             *
 *       POST JSON from allmarket.biz, header                              *
 *       X-Allmarket-Signature: sha256=<HMAC-SHA256 of the body>           *
 *       events: ping, order.created, order.updated, order.status          *
 *   index.php?dispatch=allmarket_sync.feed&token=…&type=full|offers       *
 *   index.php?dispatch=allmarket_sync.cron&token=…&task=all|catalog|…     *
 ***************************************************************************/

use Tygh\Addons\AllmarketSync\Config;
use Tygh\Addons\AllmarketSync\Exchange;
use Tygh\Addons\AllmarketSync\Feed;
use Tygh\Addons\AllmarketSync\Log;
use Tygh\Addons\AllmarketSync\OrderImporter;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

if (!function_exists('fn_allmarket_sync_reply')) {
    function fn_allmarket_sync_reply($code, array $data)
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($mode === 'webhook') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fn_allmarket_sync_reply(405, ['ok' => false, 'error' => 'POST required']);
    }

    $raw = (string) file_get_contents('php://input');
    $secret = (string) Config::state('webhook_secret');
    $signature = isset($_SERVER['HTTP_X_ALLMARKET_SIGNATURE']) ? (string) $_SERVER['HTTP_X_ALLMARKET_SIGNATURE'] : '';
    $signature = strtolower(preg_replace('/^sha256=/i', '', trim($signature)));
    if ($secret === '' || $signature === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $signature)) {
        fn_allmarket_sync_reply(401, ['ok' => false, 'error' => 'Invalid signature']);
    }

    $event = json_decode($raw, true);
    if (!is_array($event) || empty($event['event'])) {
        fn_allmarket_sync_reply(400, ['ok' => false, 'error' => 'Invalid JSON']);
    }

    $sync = new Exchange();
    try {
        switch ($event['event']) {
            case 'ping':
                fn_allmarket_sync_reply(200, ['ok' => true, 'module' => Config::VERSION, 'platform' => 'CS-Cart']);
                break;

            case 'order.created':
            case 'order.updated':
                if (!Config::isOn('orders_enabled')) {
                    fn_allmarket_sync_reply(409, ['ok' => false, 'error' => 'Order import is disabled']);
                }
                if (empty($event['order']) || !is_array($event['order'])) {
                    fn_allmarket_sync_reply(400, ['ok' => false, 'error' => 'order is required']);
                }
                $importer = new OrderImporter($sync);
                $result = $importer->process($event['order'], true);
                $row = $importer->getRow(isset($event['order']['id']) ? (string) $event['order']['id'] : '');
                if ($result === 'errors') {
                    fn_allmarket_sync_reply(422, [
                        'ok' => false,
                        'error' => $row && $row['error'] ? $row['error'] : 'Import failed',
                    ]);
                }
                $orderId = $row && $row['order_id'] ? (string) (int) $row['order_id'] : null;
                fn_allmarket_sync_reply(200, [
                    'ok' => true,
                    'result' => $result,
                    'external_order_id' => $orderId,
                    'reference' => $orderId,
                ]);
                break;

            case 'order.status':
                $amId = isset($event['order_id']) ? (string) $event['order_id'] : '';
                $status = isset($event['status']) ? (string) $event['status'] : '';
                $row = $amId !== '' ? (new OrderImporter($sync))->getRow($amId) : null;
                if (!$row || !(int) $row['order_id']) {
                    fn_allmarket_sync_reply(404, ['ok' => false, 'error' => 'Order not found']);
                }
                $changed = $sync->applyRemoteStatus((int) $row['order_id'], $status);
                fn_allmarket_sync_reply(200, ['ok' => true, 'changed' => $changed]);
                break;

            default:
                fn_allmarket_sync_reply(400, ['ok' => false, 'error' => 'Unknown event']);
        }
    } catch (\Exception $e) {
        Log::error('webhook', $e->getMessage());
        fn_allmarket_sync_reply(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($mode === 'feed') {
    $token = isset($_REQUEST['token']) ? (string) $_REQUEST['token'] : '';
    if ($token === '' || !hash_equals((string) Config::state('feed_token'), $token)) {
        http_response_code(403);
        exit('Forbidden');
    }
    // Regenerate on request if the cached file is older than this (seconds).
    $maxAge = ['full' => 86400, 'offers' => 3600];
    $type = isset($_REQUEST['type']) && $_REQUEST['type'] === 'offers' ? 'offers' : 'full';
    $path = Feed::path($type);

    if (!is_file($path) || filemtime($path) < time() - $maxAge[$type]) {
        try {
            (new Exchange())->generateFeeds([$type]);
            clearstatcache(true, $path);
        } catch (\Exception $e) {
            Log::error('feed', $e->getMessage());
            if (!is_file($path)) {
                http_response_code(503);
                exit('Feed is not available');
            }
        }
    }

    $mtime = filemtime($path);
    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mtime) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: application/xml; charset=utf-8');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('Content-Length: ' . filesize($path));
    header('X-Robots-Tag: noindex');
    readfile($path);
    exit;
}

if ($mode === 'cron') {
    $token = isset($_REQUEST['token']) ? (string) $_REQUEST['token'] : '';
    if ($token === '' || !hash_equals((string) Config::state('cron_token'), $token)) {
        fn_allmarket_sync_reply(403, ['ok' => false, 'error' => 'Forbidden']);
    }
    $task = isset($_REQUEST['task']) ? (string) $_REQUEST['task'] : Exchange::TASK_ALL;
    try {
        $stats = (new Exchange())->run($task, !empty($_REQUEST['force']));
        fn_allmarket_sync_reply(200, ['ok' => true, 'task' => $task, 'stats' => $stats]);
    } catch (\Exception $e) {
        fn_allmarket_sync_reply(500, ['ok' => false, 'task' => $task, 'error' => $e->getMessage()]);
    }
}

return [CONTROLLER_STATUS_NO_PAGE];

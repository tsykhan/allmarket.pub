<?php
/**
 * Mock of the allmarket.biz seller API (docs/API.md) for testing the module.
 * Doubles as a reference of what the portal side has to implement.
 *
 *   MOCK_API_KEY=secret php -S 0.0.0.0:8099 tools/mock_allmarket.php
 *
 * Test helpers:
 *   POST /_mock/orders   body = order JSON  → queues an order for GET /orders
 *   GET  /_mock/state                        → current state (products, orders, requests)
 *   POST /_mock/reset                        → clears the state
 */
$stateFile = getenv('MOCK_STATE') ?: sys_get_temp_dir() . '/allmarket_mock_state.json';
$apiKey = getenv('MOCK_API_KEY') ?: 'test-key';
$prefix = '/api/v1';

$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : null;
if (!is_array($state)) {
    $state = ['categories' => [], 'products' => [], 'orders' => [], 'statuses' => [], 'requests' => []];
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = file_get_contents('php://input');
$body = $raw !== '' ? json_decode($raw, true) : null;

function out($code, $data)
{
    global $state, $stateFile;
    file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (strpos($path, '/_mock/') === 0) {
    if ($path === '/_mock/reset') {
        $state = ['categories' => [], 'products' => [], 'orders' => [], 'statuses' => [], 'requests' => []];
        out(200, ['ok' => true]);
    }
    if ($path === '/_mock/orders' && $method === 'POST') {
        $body['acked'] = false;
        $body['updated_at'] = gmdate('c');
        $state['orders'][$body['id']] = $body;
        out(200, ['ok' => true]);
    }
    if ($path === '/_mock/state') {
        out(200, $state);
    }
    out(404, ['ok' => false, 'error' => 'unknown mock route']);
}

if (strpos($path, $prefix) !== 0) {
    out(404, ['ok' => false, 'error' => 'not found']);
}
$route = substr($path, strlen($prefix));

$state['requests'][] = ['t' => gmdate('c'), 'm' => $method, 'p' => $route, 'n' => is_array($body) ? array_map(function ($v) {
    return is_array($v) ? count($v) : $v;
}, $body) : null];
$state['requests'] = array_slice($state['requests'], -200);

$auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
if ($auth !== 'Bearer ' . $apiKey) {
    out(401, ['ok' => false, 'error' => ['code' => 'unauthorized', 'message' => 'Invalid API key']]);
}

if ($method === 'GET' && $route === '/ping') {
    out(200, ['ok' => true, 'shop' => ['id' => isset($_SERVER['HTTP_X_ALLMARKET_SHOP']) ? $_SERVER['HTTP_X_ALLMARKET_SHOP'] : '', 'name' => 'Mock seller']]);
}

if ($method === 'POST' && $route === '/categories/batch') {
    foreach ($body['categories'] as $c) {
        $state['categories'][$c['id']] = $c;
    }
    out(200, ['ok' => true, 'results' => array_map(function ($c) {
        return ['id' => $c['id'], 'status' => 'ok'];
    }, $body['categories'])]);
}

if ($method === 'POST' && $route === '/products/batch') {
    $results = [];
    foreach ($body['products'] as $p) {
        if (empty($p['external_id']) || !isset($p['name'], $p['price'])) {
            $results[] = ['external_id' => isset($p['external_id']) ? $p['external_id'] : null, 'status' => 'error', 'error' => 'external_id, name and price are required'];
            continue;
        }
        $isNew = !isset($state['products'][$p['external_id']]);
        $state['products'][$p['external_id']] = $p;
        $results[] = ['external_id' => $p['external_id'], 'status' => $isNew ? 'created' : 'updated'];
    }
    out(200, ['ok' => true, 'results' => $results]);
}

if ($method === 'POST' && $route === '/offers/batch') {
    $results = [];
    foreach ($body['offers'] as $o) {
        if (!isset($state['products'][$o['external_id']])) {
            $results[] = ['external_id' => $o['external_id'], 'status' => 'error', 'error' => 'unknown external_id, send it via /products/batch first'];
            continue;
        }
        $state['products'][$o['external_id']] = array_merge($state['products'][$o['external_id']], $o);
        $results[] = ['external_id' => $o['external_id'], 'status' => 'updated'];
    }
    out(200, ['ok' => true, 'results' => $results]);
}

if ($method === 'POST' && $route === '/products/delete') {
    $results = [];
    foreach ($body['external_ids'] as $id) {
        unset($state['products'][$id]);
        $results[] = ['external_id' => $id, 'status' => 'deleted'];
    }
    out(200, ['ok' => true, 'results' => $results]);
}

if ($method === 'GET' && $route === '/orders') {
    $since = isset($_GET['updated_since']) ? strtotime($_GET['updated_since']) : 0;
    $orders = [];
    foreach ($state['orders'] as $o) {
        if (!$o['acked'] || strtotime($o['updated_at']) >= $since) {
            $copy = $o;
            unset($copy['acked']);
            $orders[] = $copy;
        }
    }
    out(200, ['ok' => true, 'orders' => $orders, 'has_more' => false, 'server_time' => gmdate('c')]);
}

if (preg_match('#^/orders/([^/]+)/(ack|status)$#', $route, $m) && $method === 'POST') {
    $id = rawurldecode($m[1]);
    if (!isset($state['orders'][$id])) {
        out(404, ['ok' => false, 'error' => 'order not found']);
    }
    if ($m[2] === 'ack') {
        $state['orders'][$id]['acked'] = true;
        $state['orders'][$id]['external_order_id'] = $body['external_order_id'];
    } else {
        $state['orders'][$id]['status'] = $body['status'];
        $state['statuses'][] = ['order' => $id] + $body;
    }
    out(200, ['ok' => true]);
}

out(404, ['ok' => false, 'error' => 'unknown route ' . $method . ' ' . $route]);

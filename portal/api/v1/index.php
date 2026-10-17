<?php
/**
 * allmarket.biz Seller API v1 — router.
 * Contract: allmarketsync/docs/API.md (PrestaShop module allmarketsync).
 *
 *   GET  /api/v1/ping
 *   GET  /api/v1/portal-categories  (portal category tree for the module's category picker)
 *   POST /api/v1/categories/batch | /products/batch | /offers/batch | /products/delete
 *   GET  /api/v1/orders?updated_since=&page=&limit=
 *   POST /api/v1/orders/{id}/ack | /orders/{id}/status
 *
 * Auth: Authorization: Bearer <seller API key>, X-Allmarket-Shop: <seller id>.
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);
require __DIR__ . '/bootstrap.php';

$started = microtime(true);
$GLOBALS['amapi_log'] = array('userid' => 0, 'items' => 0, 'errors' => 0, 'message' => '');

function amapi_out($code, array $data)
{
    global $started;
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    amapi_log_request($code, (int) round((microtime(true) - $started) * 1000), $data);
    exit;
}

function amapi_error($code, $errCode, $message)
{
    $GLOBALS['amapi_log']['message'] = $message;
    amapi_out($code, array('ok' => false, 'error' => array('code' => $errCode, 'message' => $message)));
}

function amapi_log_request($code, $ms, array $data)
{
    static $done = false;
    $l = $GLOBALS['amapi_log'];
    if ($done || empty($GLOBALS['amapi_route']) || !$l['userid']) {
        return;
    }
    $done = true;
    try {
        $db = amapi_db();
        if (isset($data['results']) && is_array($data['results'])) {
            $errs = array();
            foreach ($data['results'] as $r) {
                if (isset($r['status']) && $r['status'] === 'error') {
                    $errs[] = (isset($r['external_id']) ? $r['external_id'] : (isset($r['id']) ? $r['id'] : '?')) . ': ' . $r['error'];
                }
            }
            $l['errors'] = count($errs);
            if ($errs) {
                $l['message'] = implode('; ', array_slice($errs, 0, 5));
            }
        }
        $db->insertRow('amapi_log', array(
            'userid' => $l['userid'], 't' => time(), 'method' => $_SERVER['REQUEST_METHOD'],
            'route' => substr($GLOBALS['amapi_route'], 0, 100), 'code' => $code, 'items' => $l['items'],
            'errors' => $l['errors'], 'ms' => $ms, 'message' => mb_substr($l['message'], 0, 255, 'UTF-8'),
        ));
        if (mt_rand(1, 200) === 1) {
            $db->exec('DELETE FROM #__amapi_log WHERE t<?', array(time() - 86400 * (int) amapi_config()['log_days']));
        }
    } catch (Exception $e) {
        // logging must never break a response
    }
}

function amapi_header($name)
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    foreach (array($key, 'REDIRECT_' . $key) as $k) {
        if (!empty($_SERVER[$k])) {
            return $_SERVER[$k];
        }
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
    }
    return '';
}

set_exception_handler(function ($e) {
    error_log('allmarket api: ' . $e);
    amapi_error(500, 'internal_error', 'Internal error');
});

// Route: path after /api/v1 (rewrite) or ?route= (servers without mod_rewrite).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (isset($_GET['route'])) {
    $route = '/' . ltrim((string) $_GET['route'], '/');
} elseif (preg_match('#/api/v1(/.*)?$#', $path, $m)) {
    $route = isset($m[1]) ? rtrim($m[1], '/') : '';
    if ($route === '/index.php') {
        $route = '';
    }
} else {
    $route = '';
}
$GLOBALS['amapi_route'] = $route;
$method = $_SERVER['REQUEST_METHOD'];
$cfg = amapi_config();

if ($route === '' && $method === 'GET') {
    amapi_out(200, array('ok' => true, 'service' => 'allmarket.biz Seller API', 'version' => 1));
}

$body = array();
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw !== '') {
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            amapi_error(400, 'bad_json', 'Request body must be a JSON object');
        }
    }
}

$auth = amapi_header('Authorization');
$key = preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m) ? $m[1] : '';
$db = amapi_db();
$seller = AmAuth::seller($db, $cfg, $key, amapi_header('X-Allmarket-Shop'));
if (!$seller) {
    amapi_error(401, 'unauthorized', 'Invalid API key or seller id');
}
$GLOBALS['amapi_log']['userid'] = (int) $seller['userid'];
if ($seller['blocked']) {
    amapi_error(403, 'forbidden', 'API access is disabled for this seller');
}
if (time() - (int) $seller['last_used'] > 60) {
    $db->exec('UPDATE #__amapi_seller SET last_used=? WHERE userid=?', array(time(), (int) $seller['userid']));
}

function amapi_list(array $body, $key, $max)
{
    if (!isset($body[$key]) || !is_array($body[$key])) {
        amapi_error(400, 'bad_request', '"' . $key . '" must be an array');
    }
    if (count($body[$key]) > $max) {
        amapi_error(413, 'too_large', 'At most ' . $max . ' positions per request');
    }
    $GLOBALS['amapi_log']['items'] = count($body[$key]);
    return array_values($body[$key]);
}

$max = (int) $cfg['max_batch'];
if ($method === 'GET' && $route === '/ping') {
    $m = $seller['member'];
    $name = isset($m['c_company']) && $m['c_company'] !== '' ? $m['c_company'] : ($m['company'] !== '' ? $m['company'] : $m['username']);
    amapi_out(200, array('ok' => true, 'shop' => array('id' => (string) $seller['userid'], 'name' => AmText::unescape($name), 'username' => $m['username'],
        'currency' => AmCurrency::base($db, $cfg, $m))));
}
if ($method === 'GET' && $route === '/portal-categories') {
    $c = new AmCatalog($db, $cfg, $seller);
    $tree = $c->portalTree();
    $GLOBALS['amapi_log']['items'] = count($tree);
    amapi_out(200, array('ok' => true, 'categories' => $tree));
}
if ($method === 'POST' && $route === '/categories/batch') {
    $c = new AmCatalog($db, $cfg, $seller);
    amapi_out(200, array('ok' => true, 'results' => $c->categories(amapi_list($body, 'categories', $max))));
}
if ($method === 'POST' && $route === '/products/batch') {
    $c = new AmCatalog($db, $cfg, $seller);
    amapi_out(200, array('ok' => true, 'results' => $c->products(amapi_list($body, 'products', $max))));
}
if ($method === 'POST' && $route === '/offers/batch') {
    $c = new AmCatalog($db, $cfg, $seller);
    amapi_out(200, array('ok' => true, 'results' => $c->offers(amapi_list($body, 'offers', $max * 2))));
}
if ($method === 'POST' && $route === '/products/delete') {
    $c = new AmCatalog($db, $cfg, $seller);
    amapi_out(200, array('ok' => true, 'results' => $c->delete(amapi_list($body, 'external_ids', $max * 2))));
}
if ($method === 'GET' && $route === '/orders') {
    $o = new AmOrders($db, $cfg, $seller);
    $res = $o->listOrders(isset($_GET['updated_since']) ? (string) $_GET['updated_since'] : '',
        isset($_GET['page']) ? (int) $_GET['page'] : 1, isset($_GET['limit']) ? (int) $_GET['limit'] : 50);
    $GLOBALS['amapi_log']['items'] = count($res['orders']);
    amapi_out(200, $res);
}
if ($method === 'POST' && preg_match('#^/orders/([^/]+)/(ack|status)$#', $route, $m)) {
    $o = new AmOrders($db, $cfg, $seller);
    $id = rawurldecode($m[1]);
    $GLOBALS['amapi_log']['items'] = 1;
    $res = $m[2] === 'ack' ? $o->ack($id, $body) : $o->status($id, $body);
    if ($res === null) {
        amapi_error(404, 'not_found', 'Order ' . $id . ' not found');
    }
    if (isset($res['message'])) {
        $GLOBALS['amapi_log']['message'] = $id . ': ' . $res['message'];
    }
    amapi_out(200, $res);
}
amapi_error(404, 'not_found', 'Unknown route ' . $method . ' ' . $route);

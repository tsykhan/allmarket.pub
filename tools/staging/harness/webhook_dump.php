<?php
// Staging helper (CLI): send one order to the seller's webhook and print the raw reply.
//   docker exec amportal-web php /var/www/html/staging/webhook_dump.php <orderid> [created|updated]
if (PHP_SAPI !== 'cli') {
    exit;
}
require '/var/www/html/api/v1/bootstrap.php';
$db = amapi_db();
$cfg = amapi_config();
$o = $db->one('SELECT * FROM #__multitrade_order WHERE itemid=?', array((int) $argv[1]));
$s = $db->one('SELECT * FROM #__amapi_seller WHERE username=?', array($o['seller']));
$s['member'] = AmAuth::member($db, $s['userid']);
$orders = new AmOrders($db, $cfg, $s);
$body = json_encode(array('event' => 'order.' . (isset($argv[2]) ? $argv[2] : 'created'), 'order' => $orders->format($o)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$ch = curl_init($s['webhook_url']);
curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'X-Allmarket-Signature: sha256=' . hash_hmac('sha256', $body, $s['webhook_secret']))));
$r = curl_exec($ch);
echo curl_getinfo($ch, CURLINFO_HTTP_CODE), "\n", substr(strip_tags($r), 0, 1500), "\n";

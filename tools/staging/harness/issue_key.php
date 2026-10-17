<?php
// Staging helper (CLI): issue an API key for a seller, as the cabinet button does.
//   docker exec amportal-web php /var/www/html/staging/issue_key.php lateralus [default_catid] [orders_from]
if (PHP_SAPI !== 'cli') {
    exit;
}
require '/var/www/html/api/v1/bootstrap.php';
$db = amapi_db();
$m = $db->one('SELECT userid, username FROM #__member WHERE username=?', array($argv[1]));
if (!$m) {
    fwrite(STDERR, "no such member\n");
    exit(1);
}
$key = AmAuth::issue($db, $m['userid'], $m['username']);
if (isset($argv[2])) {
    $db->exec('UPDATE #__amapi_seller SET default_catid=? WHERE userid=?', array((int) $argv[2], (int) $m['userid']));
}
if (isset($argv[3])) {
    $db->exec('UPDATE #__amapi_seller SET orders_from=? WHERE userid=?', array((int) $argv[3], (int) $m['userid']));
}
echo json_encode(array('shop' => (string) $m['userid'], 'key' => $key)), "\n";

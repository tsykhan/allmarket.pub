<?php
// Staging-only stand-in for member/allmarket_api.php: the VuCMS core (ionCube) is not available
// here, so the "logged-in member" comes from ?user=<username>. Never deploy this file.
define('VCM_ROOT', '/var/www/html');
require VCM_ROOT . '/api/v1/cabinet.inc.php';
$db = amapi_db();
$m = $db->one('SELECT userid, username FROM #__member WHERE username=?', array(isset($_GET['user']) ? $_GET['user'] : 'lateralus'));
if (!$m) {
    exit('no such member');
}
$AM = amapi_cabinet($m['userid'], $m['username'], 'staging-secret');
?><!doctype html>
<html lang="uk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>API для інтернет-магазину</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.4.1/css/bootstrap.min.css">
<style>/* stand-ins for the b2b-b2c «Інтеграції» styles */
.int-card{background:#fff;border:1px solid #e3e6ea;border-radius:14px;margin:0 0 16px}.int-sec{padding:16px 18px;border-top:1px solid #eef0f2}.int-sec:first-child{border-top:0}
.int-sec__t{font-weight:600;font-size:15px;margin:0 0 10px}.section{margin:0 0 14px}.field-label{display:block;margin:0 0 4px}.not-b{font-weight:400}
.am-ordhead{display:flex;justify-content:space-between;align-items:center;margin:0 0 14px}.am-ordhead__t{font-size:22px;margin:0}</style>
</head><body style="background:#f2f4f7"><div class="container" style="padding-top:20px;max-width:980px">
<div class="am-ordhead"><h1 class="am-ordhead__t"><?php echo amapi_h($AM['title']); ?></h1><a class="btn btn-default" href="#">До інтеграцій (staging: <?php echo amapi_h($m['username']); ?>)</a></div>
<?php include VCM_ROOT . '/api/v1/cabinet.view.php'; ?>
</div></body></html>

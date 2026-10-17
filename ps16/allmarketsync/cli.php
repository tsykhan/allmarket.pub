<?php
/**
 * Command line runner (preferred for large catalogs — no HTTP timeouts):
 *
 *   php modules/allmarketsync/cli.php <task> [--force] [--shop=ID] [-v]
 *
 * task: catalog | offers | orders | statuses | feed | all
 *
 * @author    allmarket.biz
 * @copyright 2026 allmarket.biz
 * @license   Commercial
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$args = array_slice($argv, 1);
$task = 'all';
$force = false;
$idShop = 0;
foreach ($args as $arg) {
    if ($arg === '--force') {
        $force = true;
    } elseif ($arg === '-v') {
        define('ALLMARKET_CLI_VERBOSE', true);
    } elseif (strpos($arg, '--shop=') === 0) {
        $idShop = (int) substr($arg, 7);
    } elseif ($arg !== '' && $arg[0] !== '-') {
        $task = $arg;
    }
}

if ($idShop) {
    // Shop::initialize() honours this in CLI.
    $_GET['id_shop'] = $idShop;
}
require_once dirname(__FILE__) . '/../../config/config.inc.php';

$context = Context::getContext();
if ($idShop) {
    $context->shop = new Shop($idShop);
    Shop::setContext(Shop::CONTEXT_SHOP, $idShop);
}
if (!$context->link) {
    $context->link = new Link();
}
if (!isset($context->controller)) {
    // Some hooks expect a controller; cron URLs run under a front controller too.
    $context->controller = new FrontController();
}

/** @var Allmarketsync $module */
$module = Module::getInstanceByName('allmarketsync');
if (!$module || !Module::isEnabled('allmarketsync')) {
    fwrite(STDERR, "allmarketsync module is not installed or disabled\n");
    exit(1);
}

try {
    $stats = (new AllmarketExchange($module, (int) $context->shop->id))->run($task, $force);
    echo json_encode(['ok' => true, 'task' => $task, 'stats' => $stats], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

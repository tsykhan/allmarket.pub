<?php
/**
 * Cron entry point:
 * index.php?fc=module&module=allmarketsync&controller=cron&token=...&task=catalog|offers|orders|statuses|feed|all
 *
 * @author    allmarket.biz
 * @copyright 2026 allmarket.biz
 * @license   Commercial
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AllmarketsyncCronModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $guestAllowed = true;
    public $ajax = true;

    public function postProcess()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $token = (string) Tools::getValue('token');
        if ($token === '' || !hash_equals((string) Configuration::get('ALLMARKET_CRON_TOKEN'), $token)) {
            header('HTTP/1.1 403 Forbidden');
            exit(json_encode(['ok' => false, 'error' => 'Invalid token']));
        }

        $task = (string) Tools::getValue('task', AllmarketExchange::TASK_ALL);
        try {
            $stats = (new AllmarketExchange($this->module))->run($task, (bool) Tools::getValue('force'));
            exit(json_encode(['ok' => true, 'task' => $task, 'stats' => $stats], JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {
            header('HTTP/1.1 500 Internal Server Error');
            exit(json_encode(['ok' => false, 'task' => $task, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
        }
    }
}

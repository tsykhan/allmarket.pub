<?php
/**
 * Receives events from allmarket.biz (new orders, status changes).
 *
 * POST JSON, header X-Allmarket-Signature: sha256=<hex HMAC-SHA256 of the raw body with the webhook secret>
 * Events: order.created, order.updated, order.status — see docs/API.md.
 *
 * @author    allmarket.biz
 * @copyright 2026 allmarket.biz
 * @license   Commercial
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AllmarketsyncWebhookModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $guestAllowed = true;
    public $ajax = true;

    public function postProcess()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->reply(405, ['ok' => false, 'error' => 'POST required']);
        }

        $raw = (string) Tools::file_get_contents('php://input');
        $secret = (string) Configuration::get('ALLMARKET_WEBHOOK_SECRET');
        $signature = isset($_SERVER['HTTP_X_ALLMARKET_SIGNATURE']) ? (string) $_SERVER['HTTP_X_ALLMARKET_SIGNATURE'] : '';
        $signature = preg_replace('/^sha256=/i', '', trim($signature));
        if ($secret === '' || $signature === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), Tools::strtolower($signature))) {
            $this->reply(401, ['ok' => false, 'error' => 'Invalid signature']);
        }

        $event = json_decode($raw, true);
        if (!is_array($event) || empty($event['event'])) {
            $this->reply(400, ['ok' => false, 'error' => 'Invalid JSON']);
        }

        $sync = new AllmarketExchange($this->module);
        try {
            switch ($event['event']) {
                case 'ping':
                    $this->reply(200, ['ok' => true, 'module' => $this->module->version]);
                    break;

                case 'order.created':
                case 'order.updated':
                    if (!Configuration::get('ALLMARKET_ORDERS_ENABLED')) {
                        $this->reply(409, ['ok' => false, 'error' => 'Order import is disabled']);
                    }
                    if (empty($event['order']) || !is_array($event['order'])) {
                        $this->reply(400, ['ok' => false, 'error' => 'order is required']);
                    }
                    $importer = new AllmarketOrderImporter($this->module, $sync, (int) $this->context->shop->id);
                    $result = $importer->process($event['order'], true);
                    $row = $importer->getRow((string) $event['order']['id']);
                    if ($result === 'errors') {
                        $this->reply(422, [
                            'ok' => false,
                            'error' => $row && $row['error'] ? $row['error'] : 'Import failed',
                        ]);
                    }
                    $order = $row && $row['id_order'] ? new Order((int) $row['id_order']) : null;
                    $this->reply(200, [
                        'ok' => true,
                        'result' => $result,
                        'external_order_id' => $order ? (string) (int) $order->id : null,
                        'reference' => $order ? $order->reference : null,
                    ]);
                    break;

                case 'order.status':
                    $amId = isset($event['order_id']) ? (string) $event['order_id'] : '';
                    $status = isset($event['status']) ? (string) $event['status'] : '';
                    $importer = new AllmarketOrderImporter($this->module, $sync, (int) $this->context->shop->id);
                    $row = $amId !== '' ? $importer->getRow($amId) : null;
                    if (!$row || !(int) $row['id_order']) {
                        $this->reply(404, ['ok' => false, 'error' => 'Order not found']);
                    }
                    $changed = $sync->applyRemoteStatus((int) $row['id_order'], $status);
                    $this->reply(200, ['ok' => true, 'changed' => $changed]);
                    break;

                default:
                    $this->reply(400, ['ok' => false, 'error' => 'Unknown event']);
            }
        } catch (Exception $e) {
            AllmarketLog::error('webhook', $e->getMessage());
            $this->reply(500, ['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    protected function reply($code, array $data)
    {
        http_response_code($code);
        exit(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}

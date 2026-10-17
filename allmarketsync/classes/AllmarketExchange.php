<?php
/**
 * Exchange engine: runs the catalog, price/stock, order and status tasks.
 *
 * Change detection is hash based: for each offer the module stores a hash of
 * its content and a hash of its price/stock, and only sends what changed.
 * This also catches changes made by imports or direct SQL, which bypass hooks.
 *
 * @author    allmarket.biz
 * @copyright 2026 allmarket.biz
 * @license   Commercial
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AllmarketExchange
{
    const TASK_CATALOG = 'catalog';
    const TASK_OFFERS = 'offers';
    const TASK_ORDERS = 'orders';
    const TASK_STATUSES = 'statuses';
    const TASK_FEED = 'feed';
    const TASK_ALL = 'all';

    const TASKS = ['catalog', 'offers', 'orders', 'statuses', 'feed'];

    /** A lock older than this is considered stale (crashed run). */
    const LOCK_TTL = 1800;

    /** Refuse to delete more than this share of known items without force. */
    const DELETE_SAFETY_RATIO = 0.5;
    const DELETE_SAFETY_MIN = 20;

    /** @var Module */
    protected $module;
    protected $idShop;
    /** @var AllmarketClient|null */
    protected $client;
    /** @var AllmarketCatalog|null */
    protected $catalog;

    public function __construct(Module $module, $idShop = null)
    {
        $this->module = $module;
        $this->idShop = $idShop ? (int) $idShop : (int) Context::getContext()->shop->id;
    }

    public function client($timeout = 60)
    {
        if (!$this->client) {
            $this->client = AllmarketClient::fromConfig($timeout);
        }

        return $this->client;
    }

    public function catalog()
    {
        if (!$this->catalog) {
            $this->catalog = new AllmarketCatalog($this->idShop, AllmarketConfig::getCatalogOptions());
        }

        return $this->catalog;
    }

    /**
     * @param string $task one of TASKS or "all" (offers + orders + statuses)
     *
     * @return array stats
     */
    public function run($task, $force = false)
    {
        if ($task === self::TASK_ALL) {
            $stats = [];
            foreach ([self::TASK_OFFERS, self::TASK_ORDERS, self::TASK_STATUSES] as $sub) {
                try {
                    $stats[$sub] = $this->run($sub, $force);
                } catch (Exception $e) {
                    $stats[$sub] = ['error' => $e->getMessage()];
                }
            }

            return $stats;
        }
        if (!in_array($task, self::TASKS, true)) {
            throw new InvalidArgumentException('Unknown task: ' . $task);
        }

        $lockKey = 'ALLMARKET_LOCK_' . strtoupper($task);
        $lockedAt = (int) Configuration::get($lockKey);
        if ($lockedAt && time() - $lockedAt < self::LOCK_TTL && !$force) {
            throw new RuntimeException(sprintf('Task "%s" is already running since %s', $task, date('Y-m-d H:i:s', $lockedAt)));
        }
        $lockValue = time();
        Configuration::updateValue($lockKey, $lockValue);
        // PrestaShop core may die() mid-run; do not leave the task locked for LOCK_TTL.
        register_shutdown_function(function () use ($lockKey, $lockValue) {
            if ((int) Configuration::get($lockKey) === $lockValue) {
                Configuration::updateValue($lockKey, 0);
            }
        });

        @set_time_limit(0);
        @ignore_user_abort(true);
        $started = microtime(true);
        $error = null;
        $stats = [];
        try {
            $stats = $this->runTask($task, $force);
        } catch (Exception $e) {
            $error = $e->getMessage();
            AllmarketLog::error($task, $error);
        }
        Configuration::updateValue($lockKey, 0);

        $stats['seconds'] = round(microtime(true) - $started, 1);
        Configuration::updateValue('ALLMARKET_LAST_' . strtoupper($task), json_encode([
            'date' => date('Y-m-d H:i:s'),
            'stats' => $stats,
            'error' => $error,
        ]));
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        AllmarketLog::info($task, 'Done: ' . self::formatStats($stats));

        return $stats;
    }

    protected function runTask($task, $force)
    {
        switch ($task) {
            case self::TASK_CATALOG:
                $stats = [];
                if (AllmarketConfig::usesApi()) {
                    $stats = $this->syncCatalog(true, $force);
                }
                if (AllmarketConfig::usesFeed()) {
                    $stats += $this->generateFeeds(AllmarketFeed::TYPES);
                }

                return $stats;
            case self::TASK_OFFERS:
                $stats = [];
                if (AllmarketConfig::usesApi()) {
                    $stats = $this->syncCatalog(false, false);
                }
                if (AllmarketConfig::usesFeed()) {
                    $stats += $this->generateFeeds(['offers']);
                }

                return $stats;
            case self::TASK_FEED:
                return $this->generateFeeds(AllmarketFeed::TYPES);
            case self::TASK_ORDERS:
                return $this->importOrders();
            case self::TASK_STATUSES:
                return $this->retryStatuses();
        }

        return [];
    }

    public static function formatStats(array $stats)
    {
        $parts = [];
        foreach ($stats as $key => $value) {
            if (is_array($value)) {
                $value = '{' . self::formatStats($value) . '}';
            } elseif (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            } elseif (is_float($value)) {
                $value = round($value, 2);
            }
            $parts[] = $key . '=' . $value;
        }

        return implode(', ', $parts);
    }

    /* ------------------------------------------------------------------ */
    /* Feeds                                                               */
    /* ------------------------------------------------------------------ */

    public function generateFeeds(array $types)
    {
        $stats = [];
        $feed = new AllmarketFeed($this->catalog(), $this->idShop);
        foreach ($types as $type) {
            $stats['feed_' . $type] = $feed->generate($type);
        }

        return $stats;
    }

    /* ------------------------------------------------------------------ */
    /* Catalog / offers                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * @param bool $withContent true = full catalog check, false = price/stock only
     *                          (new items still get full content)
     * @param bool $force resend everything and skip the delete safety check
     */
    public function syncCatalog($withContent, $force)
    {
        $task = $withContent ? self::TASK_CATALOG : self::TASK_OFFERS;
        $stats = ['products' => 0, 'offers' => 0, 'unchanged' => 0, 'deleted' => 0, 'errors' => 0];
        $catalog = $this->catalog();
        $client = $this->client();
        $batchSize = AllmarketConfig::getBatchSize();

        if ($withContent) {
            $stats['categories'] = $this->syncCategories($force);
        }

        list($known, $knownByProduct) = $this->loadKnown();
        $seen = [];
        $productBuf = [];
        $offerBuf = [];

        foreach ($catalog->productIdBatches() as $ids) {
            foreach ($ids as $idProduct) {
                try {
                    $items = $catalog->buildItems($idProduct, $withContent);
                    if (!$withContent) {
                        foreach ($items as $key => $item) {
                            if (!isset($known[$key]) || $known[$key]['status'] !== 'active') {
                                $items = $catalog->buildItems($idProduct, true);
                                break;
                            }
                        }
                    }
                } catch (Exception $e) {
                    ++$stats['errors'];
                    AllmarketLog::error($task, 'Product #' . $idProduct . ': ' . $e->getMessage());
                    // Do not delete what we failed to read.
                    foreach (isset($knownByProduct[$idProduct]) ? $knownByProduct[$idProduct] : [] as $key) {
                        $seen[$key] = true;
                    }
                    continue;
                }

                foreach ($items as $key => $item) {
                    $seen[$key] = true;
                    $row = isset($known[$key]) ? $known[$key] : null;
                    $isNew = !$row || $row['status'] !== 'active';
                    $offerHash = AllmarketCatalog::offerHash($item);

                    if (isset($item['name'])) {
                        $contentHash = AllmarketCatalog::contentHash($item);
                        if ($force || $isNew || $row['content_hash'] !== $contentHash) {
                            $productBuf[$key] = [
                                'item' => AllmarketCatalog::cleanItem($item),
                                'content_hash' => $contentHash,
                                'offer_hash' => $offerHash,
                            ];
                            continue;
                        }
                    }
                    if ($row['offer_hash'] !== $offerHash) {
                        $offerBuf[$key] = [
                            'item' => AllmarketCatalog::offerPart($item),
                            'content_hash' => $row['content_hash'],
                            'offer_hash' => $offerHash,
                        ];
                    } else {
                        ++$stats['unchanged'];
                    }
                }

                if (count($productBuf) >= $batchSize) {
                    $this->flush($task, 'products', $productBuf, $stats);
                }
                if (count($offerBuf) >= $batchSize) {
                    $this->flush($task, 'offers', $offerBuf, $stats);
                }
            }
        }
        $this->flush($task, 'products', $productBuf, $stats);
        $this->flush($task, 'offers', $offerBuf, $stats);

        $toDelete = [];
        $activeKnown = 0;
        foreach ($known as $key => $row) {
            if ($row['status'] === 'active') {
                ++$activeKnown;
                if (!isset($seen[$key])) {
                    $toDelete[] = $key;
                }
            }
        }
        if ($toDelete) {
            $tooMany = count($toDelete) > self::DELETE_SAFETY_MIN
                && count($toDelete) > $activeKnown * self::DELETE_SAFETY_RATIO;
            if ($tooMany && !$force) {
                AllmarketLog::warning($task, sprintf(
                    '%d of %d items disappeared from the export; deletion skipped as a safety measure. '
                    . 'Check the export filters or run the full catalog sync with force.',
                    count($toDelete),
                    $activeKnown
                ));
                $stats['delete_skipped'] = count($toDelete);
            } else {
                $stats['deleted'] = $this->deleteItems($task, $toDelete);
            }
        }

        return $stats;
    }

    protected function syncCategories($force)
    {
        $categories = $this->catalog()->getCategories();
        $hash = md5(json_encode($categories));
        if (!$force && Configuration::get('ALLMARKET_CATEGORIES_HASH') === $hash) {
            return 0;
        }
        foreach (array_chunk($categories, 500) as $chunk) {
            $this->client()->pushCategories($chunk);
        }
        Configuration::updateValue('ALLMARKET_CATEGORIES_HASH', $hash);

        return count($categories);
    }

    /**
     * @return array [key => row, id_product => key[]]
     */
    protected function loadKnown()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_product`, `id_product_attribute`, `content_hash`, `offer_hash`, `status`
             FROM `' . _DB_PREFIX_ . 'allmarket_offer` WHERE `id_shop` = ' . $this->idShop
        ) ?: [];
        $known = [];
        $byProduct = [];
        foreach ($rows as $row) {
            $key = AllmarketCatalog::externalId($row['id_product'], $row['id_product_attribute']);
            $known[$key] = $row;
            $byProduct[(int) $row['id_product']][] = $key;
        }

        return [$known, $byProduct];
    }

    /**
     * Sends buffered items and records the hashes of accepted ones.
     *
     * @param string $kind products|offers
     */
    protected function flush($task, $kind, array &$buffer, array &$stats)
    {
        if (!$buffer) {
            return;
        }
        $batchSize = AllmarketConfig::getBatchSize();
        foreach (array_chunk($buffer, $batchSize, true) as $chunk) {
            $payload = array_values(array_map(function ($entry) {
                return $entry['item'];
            }, $chunk));
            try {
                $response = $kind === 'products'
                    ? $this->client()->pushProducts($payload)
                    : $this->client()->pushOffers($payload);
            } catch (AllmarketApiException $e) {
                if ($e->isTemporary() || in_array($e->httpStatus, [401, 403], true)) {
                    $buffer = [];
                    throw $e;
                }
                $stats['errors'] += count($chunk);
                AllmarketLog::error($task, ucfirst($kind) . ' batch rejected: ' . $e->getMessage());
                continue;
            }

            $itemErrors = AllmarketClient::itemErrors($response);
            $accepted = [];
            $resend = [];
            foreach ($chunk as $key => $entry) {
                if (isset($itemErrors[$key])) {
                    ++$stats['errors'];
                    AllmarketLog::error($task, 'Item ' . $key . ': ' . $itemErrors[$key]);
                    if ($kind === 'offers') {
                        $resend[] = $key;
                    }
                    continue;
                }
                $accepted[$key] = $entry;
            }
            // A rejected offer (e.g. unknown to the portal) gets its full card on the next run.
            $this->markStatus($resend, 'resend');
            $this->saveRows($accepted, $kind === 'products');
            $stats[$kind] += count($accepted);
        }
        $buffer = [];
    }

    protected function saveRows(array $entries, $contentSent)
    {
        if (!$entries) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $values = [];
        foreach ($entries as $key => $entry) {
            list($idProduct, $idPa) = AllmarketCatalog::parseExternalId($key);
            $values[] = '(' . $this->idShop . ', ' . $idProduct . ', ' . $idPa . ", '"
                . pSQL($entry['content_hash']) . "', '" . pSQL($entry['offer_hash']) . "', 'active', "
                . ($contentSent ? "'{$now}'" : 'NULL') . ", '{$now}')";
        }
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'allmarket_offer`
                (`id_shop`, `id_product`, `id_product_attribute`, `content_hash`, `offer_hash`, `status`, `date_content`, `date_offer`)
             VALUES ' . implode(', ', $values) . '
             ON DUPLICATE KEY UPDATE
                `content_hash` = VALUES(`content_hash`),
                `offer_hash` = VALUES(`offer_hash`),
                `status` = VALUES(`status`),
                `date_content` = IFNULL(VALUES(`date_content`), `date_content`),
                `date_offer` = VALUES(`date_offer`)'
        );
    }

    protected function deleteItems($task, array $keys)
    {
        $deleted = 0;
        foreach (array_chunk($keys, AllmarketConfig::getBatchSize()) as $chunk) {
            $response = $this->client()->deleteProducts($chunk);
            $itemErrors = AllmarketClient::itemErrors($response);
            $done = [];
            foreach ($chunk as $key) {
                if (isset($itemErrors[$key])) {
                    AllmarketLog::error($task, 'Delete ' . $key . ': ' . $itemErrors[$key]);
                    continue;
                }
                $done[] = $key;
            }
            $this->markStatus($done, 'deleted');
            $deleted += count($done);
        }

        return $deleted;
    }

    /** @param string[] $keys external ids */
    protected function markStatus(array $keys, $status)
    {
        if (!$keys) {
            return;
        }
        $where = [];
        foreach ($keys as $key) {
            list($idProduct, $idPa) = AllmarketCatalog::parseExternalId($key);
            $where[] = '(`id_product` = ' . $idProduct . ' AND `id_product_attribute` = ' . $idPa . ')';
        }
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . "allmarket_offer`
             SET `status` = '" . pSQL($status) . "', `content_hash` = '', `offer_hash` = '', `date_offer` = '" . date('Y-m-d H:i:s') . "'
             WHERE `id_shop` = " . $this->idShop . ' AND (' . implode(' OR ', $where) . ')'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Orders                                                              */
    /* ------------------------------------------------------------------ */

    public function importOrders()
    {
        $stats = ['received' => 0, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        if (!Configuration::get('ALLMARKET_ORDERS_ENABLED')) {
            $stats['disabled'] = true;

            return $stats;
        }

        $importer = new AllmarketOrderImporter($this->module, $this, $this->idShop);
        $since = (string) Configuration::get('ALLMARKET_ORDERS_SINCE');
        $nextSince = null;
        $page = 1;
        do {
            $response = $this->client()->getOrders($since, $page);
            if ($nextSince === null) {
                $nextSince = !empty($response['server_time'])
                    ? (string) $response['server_time']
                    : gmdate('Y-m-d\TH:i:s\Z', time() - 300);
            }
            $orders = isset($response['orders']) && is_array($response['orders']) ? $response['orders'] : [];
            foreach ($orders as $amOrder) {
                ++$stats['received'];
                $result = $importer->process($amOrder);
                ++$stats[$result];
            }
            ++$page;
        } while (!empty($response['has_more']) && $orders && $page <= 100);

        Configuration::updateValue('ALLMARKET_ORDERS_SINCE', $nextSince);

        return $stats;
    }

    /**
     * Sends a PrestaShop status to allmarket.biz if the order came from there.
     *
     * @param bool $resend send even if this status was already sent (tracking update)
     */
    public function pushOrderState($idOrder, $idState, $resend = false)
    {
        $row = $this->getOrderLink($idOrder);
        if (!$row) {
            return false;
        }
        $map = AllmarketConfig::getStatusMap();
        if (!isset($map[$idState])) {
            return false;
        }
        $status = $map[$idState];
        if (!$resend && $row['pushed_status'] === $status) {
            return true;
        }

        return $this->sendStatus($row, $status);
    }

    protected function sendStatus(array $row, $status)
    {
        $order = new Order((int) $row['id_order']);
        $tracking = '';
        $carrierName = '';
        if (Validate::isLoadedObject($order)) {
            $orderCarrier = new OrderCarrier((int) $order->getIdOrderCarrier());
            if (Validate::isLoadedObject($orderCarrier)) {
                $tracking = (string) $orderCarrier->tracking_number;
            }
            $carrier = new Carrier((int) $order->id_carrier);
            $carrierName = Validate::isLoadedObject($carrier) ? (string) $carrier->name : '';
        }

        // Called from the order status hook: fail fast, the statuses task retries.
        $client = AllmarketClient::fromConfig(10, 1);
        try {
            if (!$client->isConfigured()) {
                throw new AllmarketApiException('API is not configured');
            }
            $client->pushOrderStatus($row['am_order_id'], [
                'status' => $status,
                'external_order_id' => (string) (int) $row['id_order'],
                'reference' => Validate::isLoadedObject($order) ? $order->reference : '',
                'tracking_number' => $tracking,
                'carrier' => $carrierName,
                'changed_at' => date('c'),
            ]);
        } catch (AllmarketApiException $e) {
            Db::getInstance()->update('allmarket_order', [
                'pending_status' => pSQL($status),
                'error' => pSQL(Tools::substr($e->getMessage(), 0, 1000)),
                'date_upd' => date('Y-m-d H:i:s'),
            ], '`id_allmarket_order` = ' . (int) $row['id_allmarket_order']);
            AllmarketLog::warning('status', 'Order ' . $row['am_order_id'] . ' → ' . $status . ' queued: ' . $e->getMessage());

            return false;
        }

        Db::getInstance()->update('allmarket_order', [
            'pushed_status' => pSQL($status),
            'am_status' => pSQL($status),
            'pending_status' => '',
            'error' => null,
            'date_upd' => date('Y-m-d H:i:s'),
        ], '`id_allmarket_order` = ' . (int) $row['id_allmarket_order'], 0, true);
        AllmarketLog::info('status', 'Order ' . $row['am_order_id'] . ' → ' . $status);

        return true;
    }

    public function retryStatuses()
    {
        $stats = ['sent' => 0, 'failed' => 0];
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . "allmarket_order`
             WHERE `id_shop` = " . $this->idShop . " AND `pending_status` != '' AND `id_order` > 0
             ORDER BY `date_upd` LIMIT 200"
        ) ?: [];
        foreach ($rows as $row) {
            $this->sendStatus($row, $row['pending_status']) ? ++$stats['sent'] : ++$stats['failed'];
        }

        return $stats;
    }

    /**
     * Applies a status received from allmarket.biz using the reverse mapping.
     *
     * @return bool true if the PrestaShop order state was changed
     */
    public function applyRemoteStatus($idOrder, $amStatus)
    {
        $amStatus = (string) $amStatus;
        $order = new Order((int) $idOrder);
        if (!Validate::isLoadedObject($order) || $amStatus === '') {
            return false;
        }
        $idState = 0;
        foreach (AllmarketConfig::getStatusMap() as $idMapped => $status) {
            if ($status === $amStatus) {
                $idState = (int) $idMapped;
                break;
            }
        }

        Db::getInstance()->update('allmarket_order', [
            'am_status' => pSQL($amStatus),
            'pushed_status' => pSQL($amStatus),
            'date_upd' => date('Y-m-d H:i:s'),
        ], '`id_order` = ' . (int) $idOrder);

        if (!$idState || (int) $order->getCurrentState() === $idState) {
            return false;
        }
        // Do not move an order backwards to a state it already passed.
        foreach ($order->getHistory((int) Context::getContext()->language->id) ?: [] as $history) {
            if ((int) $history['id_order_state'] === $idState) {
                return false;
            }
        }

        Allmarketsync::$applyingRemoteStatus = true;
        try {
            $history = new OrderHistory();
            $history->id_order = (int) $order->id;
            $history->id_employee = 0;
            $history->changeIdOrderState($idState, $order, true);
            $history->addWithemail(true);
        } finally {
            Allmarketsync::$applyingRemoteStatus = false;
        }
        AllmarketLog::info('status', 'Order #' . (int) $order->id . ' ← allmarket.biz status ' . $amStatus);

        return true;
    }

    public function getOrderLink($idOrder)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'allmarket_order` WHERE `id_order` = ' . (int) $idOrder
        ) ?: null;
    }
}

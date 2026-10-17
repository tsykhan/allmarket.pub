<?php
/***************************************************************************
 * allmarket.biz Sync — exchange engine: catalog, price/stock, orders,     *
 * statuses and feed tasks.                                                *
 *                                                                         *
 * Change detection is hash based: for each item the add-on stores a hash  *
 * of its content and a hash of its price/stock and sends only what        *
 * changed. This also catches imports and direct SQL edits.                *
 ***************************************************************************/

namespace Tygh\Addons\AllmarketSync;

class Exchange
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

    /** True while a status received from allmarket.biz is applied (no echo back). */
    public static $applyingRemoteStatus = false;

    protected $companyId;
    /** @var Client|null */
    protected $client;
    /** @var Catalog|null */
    protected $catalog;

    public function __construct()
    {
        Config::bindCompany();
        $this->companyId = Config::companyId();
    }

    public function client($timeout = 60)
    {
        if (!$this->client) {
            $this->client = Client::fromConfig($timeout);
        }

        return $this->client;
    }

    public function catalog()
    {
        if (!$this->catalog) {
            $this->catalog = new Catalog(Config::catalogOptions());
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
                } catch (\Exception $e) {
                    $stats[$sub] = ['error' => $e->getMessage()];
                }
            }

            return $stats;
        }
        if (!in_array($task, self::TASKS, true)) {
            throw new \InvalidArgumentException('Unknown task: ' . $task);
        }

        $lockKey = 'lock_' . $task;
        $lockedAt = (int) Config::state($lockKey);
        if ($lockedAt && TIME - $lockedAt < self::LOCK_TTL && !$force) {
            throw new \RuntimeException(sprintf('Task "%s" is already running since %s', $task, date('Y-m-d H:i:s', $lockedAt)));
        }
        $lockValue = (string) time();
        Config::setState($lockKey, $lockValue);
        // A fatal error mid-run must not leave the task locked for LOCK_TTL.
        register_shutdown_function(function () use ($lockKey, $lockValue) {
            if ((string) Config::state($lockKey) === $lockValue) {
                Config::setState($lockKey, '');
            }
        });

        @set_time_limit(0);
        @ignore_user_abort(true);
        $started = microtime(true);
        $error = null;
        $stats = [];
        try {
            $stats = $this->runTask($task, $force);
        } catch (\Exception $e) {
            $error = $e->getMessage();
            Log::error($task, $error);
        }
        Config::setState($lockKey, '');

        $stats['seconds'] = round(microtime(true) - $started, 1);
        Config::setJsonState('last_' . $task, [
            'date' => date('Y-m-d H:i:s'),
            'stats' => $stats,
            'error' => $error,
        ]);
        if ($error !== null) {
            throw new \RuntimeException($error);
        }
        Log::info($task, 'Done: ' . self::formatStats($stats));

        return $stats;
    }

    protected function runTask($task, $force)
    {
        switch ($task) {
            case self::TASK_CATALOG:
                $stats = [];
                if (Config::usesApi()) {
                    $stats = $this->syncCatalog(true, $force);
                }
                if (Config::usesFeed()) {
                    $stats += $this->generateFeeds(Feed::TYPES);
                }

                return $stats;
            case self::TASK_OFFERS:
                $stats = [];
                if (Config::usesApi()) {
                    $stats = $this->syncCatalog(false, false);
                }
                if (Config::usesFeed()) {
                    $stats += $this->generateFeeds(['offers']);
                }

                return $stats;
            case self::TASK_FEED:
                return $this->generateFeeds(Feed::TYPES);
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
        $feed = new Feed($this->catalog());
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
     * @param bool $force       resend everything and skip the delete safety check
     */
    public function syncCatalog($withContent, $force)
    {
        $task = $withContent ? self::TASK_CATALOG : self::TASK_OFFERS;
        $stats = ['products' => 0, 'offers' => 0, 'unchanged' => 0, 'deleted' => 0, 'errors' => 0];
        $catalog = $this->catalog();
        $batchSize = Config::batchSize();

        if ($withContent) {
            $stats['categories'] = $this->syncCategories($force);
        }

        $known = $this->loadKnown();
        $seen = [];
        $productBuf = [];
        $offerBuf = [];

        foreach ($catalog->productIdBatches() as $ids) {
            try {
                $items = $catalog->buildItems($ids, $withContent);
                if (!$withContent) {
                    // New or re-added items need the full card.
                    $needContent = [];
                    foreach ($ids as $productId) {
                        $key = Catalog::externalId($productId);
                        if (isset($items[$key]) && (!isset($known[$key]) || $known[$key]['status'] !== 'active')) {
                            $needContent[] = $productId;
                        }
                    }
                    if ($needContent) {
                        $items = $catalog->buildItems($needContent, true) + $items;
                    }
                }
            } catch (\Exception $e) {
                $stats['errors'] += count($ids);
                Log::error($task, 'Products #' . reset($ids) . '–#' . end($ids) . ': ' . $e->getMessage());
                // Do not delete what we failed to read.
                foreach ($ids as $productId) {
                    $seen[Catalog::externalId($productId)] = true;
                }
                continue;
            }

            foreach ($items as $key => $item) {
                $seen[$key] = true;
                $row = isset($known[$key]) ? $known[$key] : null;
                $isNew = !$row || $row['status'] !== 'active';
                $offerHash = Catalog::offerHash($item);

                if (isset($item['name'])) {
                    $contentHash = Catalog::contentHash($item);
                    if ($force || $isNew || $row['content_hash'] !== $contentHash) {
                        $productBuf[$key] = [
                            'item' => $item,
                            'content_hash' => $contentHash,
                            'offer_hash' => $offerHash,
                        ];
                        continue;
                    }
                }
                if ($row['offer_hash'] !== $offerHash) {
                    $offerBuf[$key] = [
                        'item' => Catalog::offerPart($item),
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
                Log::warning($task, sprintf(
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
        if (!$force && Config::state('categories_hash') === $hash) {
            return 0;
        }
        foreach (array_chunk($categories, 500) as $chunk) {
            $this->client()->pushCategories($chunk);
        }
        Config::setState('categories_hash', $hash);

        return count($categories);
    }

    /** @return array external_id => row */
    protected function loadKnown()
    {
        $known = [];
        foreach (db_get_array(
            'SELECT product_id, content_hash, offer_hash, status FROM ?:allmarket_sync_items WHERE company_id = ?i',
            $this->companyId
        ) as $row) {
            $known[Catalog::externalId($row['product_id'])] = $row;
        }

        return $known;
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
        foreach (array_chunk($buffer, Config::batchSize(), true) as $chunk) {
            $payload = array_values(array_map(function ($entry) {
                return $entry['item'];
            }, $chunk));
            try {
                $response = $kind === 'products'
                    ? $this->client()->pushProducts($payload)
                    : $this->client()->pushOffers($payload);
            } catch (ApiException $e) {
                if ($e->isTemporary() || in_array($e->httpStatus, [401, 403], true)) {
                    $buffer = [];
                    throw $e;
                }
                $stats['errors'] += count($chunk);
                Log::error($task, ucfirst($kind) . ' batch rejected: ' . $e->getMessage());
                continue;
            }

            $itemErrors = Client::itemErrors($response);
            $accepted = [];
            $resend = [];
            foreach ($chunk as $key => $entry) {
                if (isset($itemErrors[$key])) {
                    ++$stats['errors'];
                    Log::error($task, 'Item ' . $key . ': ' . $itemErrors[$key]);
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
        $values = [];
        foreach ($entries as $key => $entry) {
            $values[] = db_quote(
                '(?i, ?i, ?s, ?s, ?s, ?i, ?i)',
                $this->companyId,
                Catalog::parseExternalId($key),
                $entry['content_hash'],
                $entry['offer_hash'],
                'active',
                $contentSent ? TIME : 0,
                TIME
            );
        }
        db_query(
            'INSERT INTO ?:allmarket_sync_items (company_id, product_id, content_hash, offer_hash, status, date_content, date_offer)
             VALUES ?p
             ON DUPLICATE KEY UPDATE
                content_hash = VALUES(content_hash),
                offer_hash = VALUES(offer_hash),
                status = VALUES(status),
                date_content = IF(VALUES(date_content) > 0, VALUES(date_content), date_content),
                date_offer = VALUES(date_offer)',
            implode(', ', $values)
        );
    }

    protected function deleteItems($task, array $keys)
    {
        $deleted = 0;
        foreach (array_chunk($keys, Config::batchSize()) as $chunk) {
            $response = $this->client()->deleteProducts($chunk);
            $itemErrors = Client::itemErrors($response);
            $done = [];
            foreach ($chunk as $key) {
                if (isset($itemErrors[$key])) {
                    Log::error($task, 'Delete ' . $key . ': ' . $itemErrors[$key]);
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
        db_query(
            "UPDATE ?:allmarket_sync_items SET status = ?s, content_hash = '', offer_hash = '', date_offer = ?i
             WHERE company_id = ?i AND product_id IN (?n)",
            $status,
            TIME,
            $this->companyId,
            array_map([Catalog::class, 'parseExternalId'], $keys)
        );
    }

    /* ------------------------------------------------------------------ */
    /* Orders                                                              */
    /* ------------------------------------------------------------------ */

    public function importOrders()
    {
        $stats = ['received' => 0, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        if (!Config::isOn('orders_enabled')) {
            $stats['disabled'] = true;

            return $stats;
        }

        $importer = new OrderImporter($this);
        $since = (string) Config::state('orders_since');
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

        Config::setState('orders_since', $nextSince);

        return $stats;
    }

    /**
     * Sends a CS-Cart status to allmarket.biz if the order came from there.
     *
     * @param bool $resend send even if this status was already sent (tracking update)
     */
    public function pushOrderState($orderId, $status, $resend = false)
    {
        $row = $this->getOrderLink($orderId);
        if (!$row) {
            return false;
        }
        $map = Config::statusMap();
        if (!isset($map[$status])) {
            return false;
        }
        $amStatus = $map[$status];
        if (!$resend && $row['pushed_status'] === $amStatus) {
            return true;
        }

        return $this->sendStatus($row, $amStatus);
    }

    /** Latest shipment with a tracking number: [tracking_number, carrier name]. */
    protected function tracking($orderId)
    {
        $shipment = db_get_row(
            "SELECT s.tracking_number, s.carrier, s.shipping_id
             FROM ?:shipments s
             INNER JOIN ?:shipment_items si ON si.shipment_id = s.shipment_id
             WHERE si.order_id = ?i AND s.tracking_number != ''
             ORDER BY s.timestamp DESC, s.shipment_id DESC LIMIT 1",
            $orderId
        );
        if (!$shipment) {
            return ['', ''];
        }
        $carrier = '';
        if (!empty($shipment['shipping_id'])) {
            $carrier = (string) fn_get_shipping_name($shipment['shipping_id'], Config::langCode());
        }
        if ($carrier === '' && !empty($shipment['carrier'])) {
            $carrier = (string) $shipment['carrier'];
        }

        return [(string) $shipment['tracking_number'], $carrier];
    }

    protected function sendStatus(array $row, $amStatus)
    {
        list($tracking, $carrier) = $this->tracking((int) $row['order_id']);

        // Called from the order status hook: fail fast, the statuses task retries.
        $client = Client::fromConfig(10, 1);
        try {
            if (!$client->isConfigured()) {
                throw new ApiException('API is not configured');
            }
            $client->pushOrderStatus($row['am_order_id'], [
                'status' => $amStatus,
                'external_order_id' => (string) (int) $row['order_id'],
                'reference' => (string) (int) $row['order_id'],
                'tracking_number' => $tracking,
                'carrier' => $carrier,
                'changed_at' => date('c'),
            ]);
        } catch (ApiException $e) {
            db_query('UPDATE ?:allmarket_sync_orders SET ?u WHERE link_id = ?i', [
                'pending_status' => $amStatus,
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'updated' => TIME,
            ], $row['link_id']);
            Log::warning('status', 'Order ' . $row['am_order_id'] . ' → ' . $amStatus . ' queued: ' . $e->getMessage());

            return false;
        }

        db_query('UPDATE ?:allmarket_sync_orders SET ?u WHERE link_id = ?i', [
            'pushed_status' => $amStatus,
            'am_status' => $amStatus,
            'pending_status' => '',
            'error' => null,
            'updated' => TIME,
        ], $row['link_id']);
        Log::info('status', 'Order ' . $row['am_order_id'] . ' → ' . $amStatus . ($tracking !== '' ? ' (' . $tracking . ')' : ''));

        return true;
    }

    public function retryStatuses()
    {
        $stats = ['sent' => 0, 'failed' => 0];
        $rows = db_get_array(
            "SELECT * FROM ?:allmarket_sync_orders
             WHERE company_id = ?i AND pending_status != '' AND order_id > 0
             ORDER BY updated LIMIT 200",
            $this->companyId
        );
        foreach ($rows as $row) {
            $this->sendStatus($row, $row['pending_status']) ? ++$stats['sent'] : ++$stats['failed'];
        }

        return $stats;
    }

    /**
     * Applies a status received from allmarket.biz using the reverse mapping.
     *
     * @return bool true if the CS-Cart order status was changed
     */
    public function applyRemoteStatus($orderId, $amStatus)
    {
        $amStatus = (string) $amStatus;
        $order = fn_get_order_short_info($orderId);
        if (!$order || $amStatus === '') {
            return false;
        }
        $status = '';
        foreach (Config::statusMap() as $csStatus => $mapped) {
            if ($mapped === $amStatus) {
                $status = (string) $csStatus;
                break;
            }
        }

        db_query('UPDATE ?:allmarket_sync_orders SET ?u WHERE order_id = ?i', [
            'am_status' => $amStatus,
            'pushed_status' => $amStatus,
            'updated' => TIME,
        ], $orderId);

        if ($status === '' || $order['status'] === $status) {
            return false;
        }

        self::$applyingRemoteStatus = true;
        try {
            $changed = fn_change_order_status($orderId, $status, $order['status'], Config::notificationRules());
        } finally {
            self::$applyingRemoteStatus = false;
        }
        if ($changed) {
            Log::info('status', 'Order #' . (int) $orderId . ' ← allmarket.biz status ' . $amStatus);
        }

        return (bool) $changed;
    }

    public function getOrderLink($orderId)
    {
        return db_get_row('SELECT * FROM ?:allmarket_sync_orders WHERE order_id = ?i', $orderId) ?: null;
    }
}

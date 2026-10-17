<?php
/***************************************************************************
 * allmarket.biz Sync — creates CS-Cart orders from allmarket.biz orders   *
 * (payload: docs/API.md, GET /orders and the order.* webhook events).     *
 *                                                                         *
 * Orders are placed the way the admin "order management" does it: a cart *
 * with stored (allmarket.biz) prices and a stored shipping rate, then     *
 * fn_place_order() and a status change, so stock, promotions, hooks and   *
 * notifications behave as for any other order.                            *
 ***************************************************************************/

namespace Tygh\Addons\AllmarketSync;

use Tygh\Registry;

class OrderImporter
{
    const MAX_ATTEMPTS = 5;

    /** Staff note that marks an order created by this add-on (also detects interrupted attempts). */
    const MARKER = 'allmarket.biz order ';

    /** @var Exchange */
    protected $sync;
    protected $companyId;

    public function __construct(Exchange $sync)
    {
        $this->sync = $sync;
        $this->companyId = Config::companyId();
    }

    /**
     * @param bool $viaWebhook the caller returns the result to allmarket.biz itself, no ack call needed
     *
     * @return string imported|updated|skipped|errors
     */
    public function process(array $am, $viaWebhook = false)
    {
        $amId = isset($am['id']) ? trim((string) $am['id']) : '';
        if ($amId === '') {
            Log::error('orders', 'Order without id skipped');

            return 'errors';
        }
        $amStatus = isset($am['status']) ? (string) $am['status'] : 'new';
        $row = $this->getRow($amId);

        if ($row && (int) $row['order_id']) {
            $changed = false;
            if ($amStatus !== 'new' && $amStatus !== $row['am_status']) {
                $changed = $this->sync->applyRemoteStatus((int) $row['order_id'], $amStatus);
            }
            if (!$row['acked']) {
                $this->ack($row, (int) $row['order_id'], $viaWebhook);
            }

            return $changed ? 'updated' : 'skipped';
        }
        if ($row && (int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            return 'skipped';
        }
        if (in_array($amStatus, ['cancelled', 'returned'], true)) {
            return 'skipped';
        }

        if (!$row) {
            db_query('INSERT INTO ?:allmarket_sync_orders ?e', [
                'company_id' => $this->companyId,
                'am_order_id' => $amId,
                'am_number' => isset($am['number']) ? (string) $am['number'] : $amId,
                'am_status' => $amStatus,
                'payload' => json_encode($am),
                'created' => TIME,
                'updated' => TIME,
            ]);
            $row = $this->getRow($amId);
        }
        db_query('UPDATE ?:allmarket_sync_orders SET attempts = attempts + 1, updated = ?i WHERE link_id = ?i', TIME, $row['link_id']);

        try {
            // A previous attempt may have died after the order was written.
            $orderId = (int) db_get_field('SELECT order_id FROM ?:orders WHERE details = ?s ORDER BY order_id DESC LIMIT 1', self::MARKER . $amId);
            if ($orderId) {
                Log::warning('orders', 'Order ' . $amId . ': linked to #' . $orderId . ' created by an interrupted attempt');
            } else {
                $orderId = $this->createOrder($am);
            }
        } catch (\Exception $e) {
            db_query('UPDATE ?:allmarket_sync_orders SET ?u WHERE link_id = ?i', [
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'payload' => json_encode($am),
            ], $row['link_id']);
            Log::error('orders', 'Order ' . $amId . ' not imported: ' . $e->getMessage());

            return 'errors';
        }

        db_query('UPDATE ?:allmarket_sync_orders SET ?u WHERE link_id = ?i', [
            'order_id' => $orderId,
            'error' => null,
            'updated' => TIME,
        ], $row['link_id']);
        Log::info('orders', 'Order ' . $amId . ' imported as #' . $orderId);
        $this->ack($row, $orderId, $viaWebhook);

        return 'imported';
    }

    public function getRow($amOrderId)
    {
        return db_get_row(
            'SELECT * FROM ?:allmarket_sync_orders WHERE company_id = ?i AND am_order_id = ?s',
            $this->companyId,
            $amOrderId
        ) ?: null;
    }

    protected function ack(array $row, $orderId, $viaWebhook)
    {
        if (!$viaWebhook) {
            try {
                $this->sync->client()->ackOrder($row['am_order_id'], [
                    'external_order_id' => (string) (int) $orderId,
                    'reference' => (string) (int) $orderId,
                ]);
            } catch (ApiException $e) {
                Log::warning('orders', 'Ack of ' . $row['am_order_id'] . ' failed, will retry: ' . $e->getMessage());

                return false;
            }
        }
        db_query('UPDATE ?:allmarket_sync_orders SET acked = 1 WHERE link_id = ?i', $row['link_id']);

        return true;
    }

    /**
     * @return int order_id
     *
     * @throws \Exception
     */
    public function createOrder(array $am)
    {
        $amId = (string) $am['id'];
        $items = isset($am['items']) && is_array($am['items']) ? $am['items'] : [];
        if (!$items) {
            throw new \RuntimeException('Order has no items');
        }
        // Stored prices and stock rules of the admin order editor.
        fn_define('ORDER_MANAGEMENT', true);

        $currencies = Registry::get('currencies') ?: [];
        $iso = isset($am['currency']) ? strtoupper(trim((string) $am['currency'])) : '';
        if ($iso !== '' && !isset($currencies[$iso])) {
            throw new \RuntimeException('Currency ' . $iso . ' is not enabled in the store');
        }
        $coefficient = $iso !== '' ? ((float) $currencies[$iso]['coefficient'] ?: 1.0) : 1.0;
        $toPrimary = function ($amount) use ($coefficient) {
            return round((float) $amount * $coefficient, 2);
        };

        $c = isset($am['customer']) ? (array) $am['customer'] : [];
        $d = isset($am['delivery']) ? (array) $am['delivery'] : [];
        $email = isset($c['email']) ? trim((string) $c['email']) : '';
        if (!fn_validate_email($email)) {
            $email = 'am-' . strtolower(preg_replace('/[^a-z0-9]/i', '', $amId)) . '@allmarket.invalid';
        }

        $userCondition = '';
        if (fn_allowed_for('ULTIMATE') && Registry::get('settings.Stores.share_users') !== 'Y') {
            $userCondition = db_quote(' AND company_id = ?i', $this->companyId);
        }
        $userId = (int) db_get_field("SELECT user_id FROM ?:users WHERE email = ?s AND user_type = 'C' ?p ORDER BY user_id LIMIT 1", $email, $userCondition);
        $auth = $userId
            ? fn_fill_auth(fn_get_user_info($userId, false), [], false, 'C')
            : fn_fill_auth([], [], false, 'C');

        $cart = [];
        fn_clear_cart($cart, true);
        $cart['user_data'] = $this->userData($c, $d, $email);
        $cart['user_id'] = $userId;

        // Products, merged by product id.
        $lines = [];
        foreach ($items as $item) {
            $productId = $this->resolveProduct($item);
            $qty = max(1, (int) (isset($item['quantity']) ? $item['quantity'] : 1));
            if (isset($lines[$productId])) {
                $lines[$productId]['amount'] += $qty;
                continue;
            }
            $lines[$productId] = [
                'product_id' => $productId,
                'amount' => $qty,
                'name' => isset($item['name']) ? (string) $item['name'] : '#' . $productId,
                'price' => isset($item['price']) && is_numeric($item['price']) ? (float) $item['price'] : null,
            ];
        }
        $productData = [];
        foreach ($lines as $productId => $line) {
            $productData[$productId] = ['product_id' => $productId, 'amount' => $line['amount']];
            if (Config::isOn('use_am_prices') && $line['price'] !== null && $line['price'] >= 0) {
                $productData[$productId]['stored_price'] = 'Y';
                $productData[$productId]['price'] = $toPrimary($line['price']);
            }
        }
        fn_add_product_to_cart($productData, $cart, $auth);

        $inCart = [];
        foreach (isset($cart['products']) ? $cart['products'] : [] as $cartProduct) {
            $inCart[(int) $cartProduct['product_id']] = (isset($inCart[(int) $cartProduct['product_id']]) ? $inCart[(int) $cartProduct['product_id']] : 0) + (int) $cartProduct['amount'];
        }
        foreach ($lines as $productId => $line) {
            if (empty($inCart[$productId]) || $inCart[$productId] < $line['amount']) {
                throw new \RuntimeException(sprintf(
                    'Cannot add "%s" x%d to the order (product disabled, out of stock without backorders, or quantity rules): %s',
                    $line['name'],
                    $line['amount'],
                    self::notifications()
                ));
            }
        }

        // Shipping: the method from the settings, with the allmarket.biz cost if enabled.
        $shippingId = (int) Config::get('shipping_id');
        $cart['calculate_shipping'] = true;
        if ($shippingId) {
            $cart['chosen_shipping'] = [0 => $shippingId];
        }
        fn_calculate_cart_content($cart, $auth, 'A', true, 'F', true);
        $amShipping = Config::isOn('use_am_shipping') && isset($d['cost']) && is_numeric($d['cost']) ? $toPrimary(max(0, (float) $d['cost'])) : null;
        if ($amShipping !== null && !empty($cart['product_groups'])) {
            foreach ($cart['product_groups'] as $groupKey => $group) {
                foreach (isset($group['chosen_shippings']) ? $group['chosen_shippings'] : [] as $shippingKey => $shipping) {
                    // Whole cost on the first group (one store = one group).
                    $cart['stored_shipping'][$groupKey][$shippingKey] = $groupKey === key($cart['product_groups']) ? $amShipping : 0;
                }
            }
            if (!empty($cart['stored_shipping'])) {
                fn_calculate_cart_content($cart, $auth, 'A', true, 'F', true);
            }
        }
        if (!empty($cart['shipping_failed']) || !empty($cart['company_shipping_failed'])) {
            if ($shippingId) {
                Log::warning('orders', sprintf(
                    'Order %s: shipping method #%d is not available for this order (location, weight or price limits); placed without shipping',
                    $amId,
                    $shippingId
                ));
            }
            $cart['shipping_failed'] = false;
            $cart['company_shipping_failed'] = false;
        }

        $cart['payment_id'] = (int) Config::get('payment_id');
        $cart['notes'] = $this->buildMessage($am);
        $cart['details'] = self::MARKER . $amId;

        list($orderId) = fn_place_order($cart, $auth, 'save');
        $orderId = (int) $orderId;
        if (!$orderId) {
            throw new \RuntimeException('fn_place_order() did not create an order: ' . self::notifications());
        }
        db_query('UPDATE ?:orders SET details = ?s WHERE order_id = ?i', self::MARKER . $amId, $orderId);

        $status = $this->initialStatus($am);
        Exchange::$applyingRemoteStatus = true;
        try {
            fn_change_order_status($orderId, $status, '', Config::notificationRules(), true);
        } finally {
            Exchange::$applyingRemoteStatus = false;
        }

        if ($amShipping !== null && empty($cart['stored_shipping'])) {
            $this->applyShippingCost($orderId, $amShipping);
        }

        return $orderId;
    }

    /** Orders placed without a shipping method still get the allmarket.biz delivery cost. */
    protected function applyShippingCost($orderId, $cost)
    {
        $order = db_get_row('SELECT total, shipping_cost FROM ?:orders WHERE order_id = ?i', $orderId);
        if (!$order || abs((float) $order['shipping_cost'] - $cost) < 0.005) {
            return;
        }
        db_query(
            'UPDATE ?:orders SET shipping_cost = ?d, total = ?d WHERE order_id = ?i',
            $cost,
            (float) $order['total'] - (float) $order['shipping_cost'] + $cost,
            $orderId
        );
    }

    protected function initialStatus(array $am)
    {
        $paid = !empty($am['payment']['paid']);
        $status = (string) Config::get($paid ? 'status_paid' : 'status_new');
        $statuses = fn_get_simple_statuses(STATUSES_ORDER);

        return isset($statuses[$status]) ? $status : ($paid ? 'P' : 'O');
    }

    protected function userData(array $c, array $d, $email)
    {
        $country = !empty($d['country']) ? strtoupper(trim((string) $d['country'])) : '';
        if ($country === '' || !db_get_field('SELECT code FROM ?:countries WHERE code = ?s', $country)) {
            $country = strtoupper((string) Config::get('default_country', 'UA')) ?: 'UA';
        }
        $phone = isset($c['phone']) ? preg_replace('/[^+0-9. ()\/-]/', '', (string) $c['phone']) : '';
        $firstName = self::clean(isset($c['first_name']) ? $c['first_name'] : '', 128) ?: 'Покупець';
        $lastName = self::clean(isset($c['last_name']) ? $c['last_name'] : '', 128) ?: 'allmarket.biz';
        $sFirst = self::clean(isset($d['recipient_first_name']) ? $d['recipient_first_name'] : '', 128) ?: $firstName;
        $sLast = self::clean(isset($d['recipient_last_name']) ? $d['recipient_last_name'] : '', 128) ?: $lastName;

        $street = [];
        foreach (['address', 'warehouse'] as $field) {
            if (!empty($d[$field])) {
                $street[] = (string) $d[$field];
            }
        }
        $address = self::clean(implode(', ', $street), 255);
        $address2 = self::clean(!empty($d['method_name']) ? (string) $d['method_name'] : '', 255);
        $city = self::clean(isset($d['city']) ? $d['city'] : '', 64);
        $state = self::clean(isset($d['region']) ? $d['region'] : '', 32);
        $zip = self::clean(isset($d['postcode']) ? $d['postcode'] : '', 16);

        $data = [
            'email' => $email,
            'firstname' => $firstName,
            'lastname' => $lastName,
            'phone' => $phone,
        ];
        foreach (['b_', 's_'] as $prefix) {
            $data[$prefix . 'firstname'] = $prefix === 's_' ? $sFirst : $firstName;
            $data[$prefix . 'lastname'] = $prefix === 's_' ? $sLast : $lastName;
            $data[$prefix . 'address'] = $address;
            $data[$prefix . 'address_2'] = $address2;
            $data[$prefix . 'city'] = $city;
            $data[$prefix . 'state'] = $state;
            $data[$prefix . 'country'] = $country;
            $data[$prefix . 'zipcode'] = $zip;
            $data[$prefix . 'phone'] = $phone;
        }

        return $data;
    }

    /** @return int product id */
    protected function resolveProduct(array $item)
    {
        $productId = 0;
        if (!empty($item['external_id'])) {
            $productId = (int) db_get_field(
                'SELECT product_id FROM ?:products WHERE product_id = ?i',
                Catalog::parseExternalId($item['external_id'])
            );
        }
        if (!$productId && !empty($item['sku'])) {
            $productId = (int) db_get_field(
                "SELECT product_id FROM ?:products WHERE product_code = ?s ORDER BY status = 'A' DESC, product_id LIMIT 1",
                (string) $item['sku']
            );
        }
        if (!$productId) {
            throw new \RuntimeException(sprintf(
                'Product not found: external_id=%s sku=%s %s',
                isset($item['external_id']) ? $item['external_id'] : '',
                isset($item['sku']) ? $item['sku'] : '',
                isset($item['name']) ? $item['name'] : ''
            ));
        }

        return $productId;
    }

    protected function buildMessage(array $am)
    {
        $lines = ['allmarket.biz #' . (isset($am['number']) ? $am['number'] : $am['id'])];
        $d = isset($am['delivery']) ? (array) $am['delivery'] : [];
        $p = isset($am['payment']) ? (array) $am['payment'] : [];
        $c = isset($am['customer']) ? (array) $am['customer'] : [];
        if (!empty($d['method_name'])) {
            $lines[] = 'Доставка: ' . $d['method_name']
                . (!empty($d['city']) ? ', ' . $d['city'] : '')
                . (!empty($d['warehouse']) ? ', ' . $d['warehouse'] : '')
                . (!empty($d['address']) ? ', ' . $d['address'] : '');
        }
        if (!empty($p['method_name'])) {
            $lines[] = 'Оплата: ' . $p['method_name'] . (!empty($p['paid']) ? ' (оплачено)' : '');
        }
        if (!empty($c['phone'])) {
            $lines[] = 'Телефон: ' . $c['phone'];
        }
        if (!empty($am['comment'])) {
            $lines[] = 'Коментар: ' . $am['comment'];
        }

        return strip_tags(implode("\n", $lines));
    }

    protected static function clean($value, $max)
    {
        $value = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)));

        return mb_substr($value, 0, $max);
    }

    /** Error notifications collected by CS-Cart core during the import. */
    protected static function notifications()
    {
        $texts = [];
        foreach (fn_get_notifications() ?: [] as $notification) {
            if (isset($notification['type']) && in_array($notification['type'], ['E', 'W'], true)) {
                $texts[] = trim(strip_tags($notification['message']));
            }
        }

        return $texts ? implode('; ', array_unique($texts)) : 'no details';
    }
}

<?php
/**
 * Creates PrestaShop orders from allmarket.biz orders (payload: docs/API.md).
 *
 * @author    allmarket.biz
 * @copyright 2026 allmarket.biz
 * @license   Commercial
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AllmarketOrderImporter
{
    const MAX_ATTEMPTS = 5;

    /** @var PaymentModule */
    protected $module;
    /** @var AllmarketExchange */
    protected $sync;
    protected $idShop;

    public function __construct(PaymentModule $module, AllmarketExchange $sync, $idShop)
    {
        $this->module = $module;
        $this->sync = $sync;
        $this->idShop = (int) $idShop;
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
            AllmarketLog::error('orders', 'Order without id skipped');

            return 'errors';
        }
        $amStatus = isset($am['status']) ? (string) $am['status'] : 'new';
        $row = $this->getRow($amId);

        if ($row && (int) $row['id_order']) {
            $changed = false;
            if ($amStatus !== 'new' && $amStatus !== $row['am_status']) {
                $changed = $this->sync->applyRemoteStatus((int) $row['id_order'], $amStatus);
            }
            if (!$row['acked']) {
                $this->ack($row, (int) $row['id_order'], $viaWebhook);
            }

            return $changed ? 'updated' : 'skipped';
        }
        if ($row && (int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            return 'skipped';
        }
        if (in_array($amStatus, ['cancelled', 'returned'], true)) {
            return 'skipped';
        }

        $now = date('Y-m-d H:i:s');
        if (!$row) {
            Db::getInstance()->insert('allmarket_order', [
                'id_shop' => $this->idShop,
                'am_order_id' => pSQL($amId),
                'am_number' => pSQL(isset($am['number']) ? (string) $am['number'] : $amId),
                'am_status' => pSQL($amStatus),
                'payload' => pSQL(json_encode($am, JSON_UNESCAPED_UNICODE), true),
                'date_add' => $now,
                'date_upd' => $now,
            ]);
            $row = $this->getRow($amId);
        }
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'allmarket_order` SET `attempts` = `attempts` + 1, `date_upd` = \'' . $now . '\'
             WHERE `id_allmarket_order` = ' . (int) $row['id_allmarket_order']
        );

        try {
            // A previous attempt may have died after the order was written.
            $idOrder = (int) $row['id_cart'] ? (int) Order::getIdByCartId((int) $row['id_cart']) : 0;
            if ($idOrder) {
                AllmarketLog::warning('orders', 'Order ' . $amId . ': linked to #' . $idOrder . ' created by an interrupted attempt');
                $this->ensureState($idOrder, $am);
                $this->applyAmShipping($idOrder, $am);
            } else {
                if ((int) $row['id_cart']) {
                    // Cart-scoped prices left by an attempt that died before cleanup.
                    SpecificPrice::deleteByIdCart((int) $row['id_cart']);
                }
                $idOrder = $this->createOrder($am, (int) $row['id_allmarket_order']);
            }
        } catch (Exception $e) {
            Db::getInstance()->update('allmarket_order', [
                'error' => pSQL(Tools::substr($e->getMessage(), 0, 2000)),
                'payload' => pSQL(json_encode($am, JSON_UNESCAPED_UNICODE), true),
            ], '`id_allmarket_order` = ' . (int) $row['id_allmarket_order']);
            AllmarketLog::error('orders', 'Order ' . $amId . ' not imported: ' . $e->getMessage());

            return 'errors';
        }

        Db::getInstance()->update('allmarket_order', [
            'id_order' => (int) $idOrder,
            'error' => null,
            'date_upd' => date('Y-m-d H:i:s'),
        ], '`id_allmarket_order` = ' . (int) $row['id_allmarket_order'], 0, true);
        AllmarketLog::info('orders', 'Order ' . $amId . ' imported as #' . $idOrder);
        $this->ack($row, $idOrder, $viaWebhook);

        return 'imported';
    }

    public function getRow($amOrderId)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'allmarket_order`
             WHERE `id_shop` = ' . $this->idShop . " AND `am_order_id` = '" . pSQL($amOrderId) . "'"
        ) ?: null;
    }

    protected function ack(array $row, $idOrder, $viaWebhook)
    {
        $order = new Order((int) $idOrder);
        if (!$viaWebhook) {
            try {
                $this->sync->client()->ackOrder($row['am_order_id'], [
                    'external_order_id' => (string) (int) $idOrder,
                    'reference' => Validate::isLoadedObject($order) ? $order->reference : '',
                ]);
            } catch (AllmarketApiException $e) {
                AllmarketLog::warning('orders', 'Ack of ' . $row['am_order_id'] . ' failed, will retry: ' . $e->getMessage());

                return false;
            }
        }
        Db::getInstance()->update('allmarket_order', ['acked' => 1], '`id_allmarket_order` = ' . (int) $row['id_allmarket_order']);

        return true;
    }

    /**
     * @return int id_order
     *
     * @throws Exception
     */
    public function createOrder(array $am, $idLinkRow = 0)
    {
        $amId = (string) $am['id'];
        $items = isset($am['items']) && is_array($am['items']) ? $am['items'] : [];
        if (!$items) {
            throw new RuntimeException('Order has no items');
        }

        $context = Context::getContext();
        $shop = new Shop($this->idShop);
        $context->shop = $shop;
        $idLang = (int) Configuration::get('ALLMARKET_ID_LANG') ?: (int) Configuration::get('PS_LANG_DEFAULT');
        $context->language = new Language($idLang);

        $iso = isset($am['currency']) ? Tools::strtoupper((string) $am['currency']) : '';
        $idCurrency = $iso !== '' ? (int) Currency::getIdByIsoCode($iso, $this->idShop) : 0;
        if (!$idCurrency) {
            if ($iso !== '') {
                throw new RuntimeException('Currency ' . $iso . ' is not enabled in the shop');
            }
            $idCurrency = (int) Configuration::get('ALLMARKET_ID_CURRENCY') ?: (int) Configuration::get('PS_CURRENCY_DEFAULT');
        }
        $currency = new Currency($idCurrency);
        $context->currency = $currency;

        $customer = $this->resolveCustomer(isset($am['customer']) ? (array) $am['customer'] : [], $amId, $idLang);
        $context->customer = $customer;
        $address = $this->createAddress($customer, $am);
        $context->country = new Country((int) $address->id_country);

        $cart = new Cart();
        $cart->id_shop = $this->idShop;
        $cart->id_shop_group = (int) $shop->id_shop_group;
        $cart->id_customer = (int) $customer->id;
        $cart->id_address_delivery = (int) $address->id;
        $cart->id_address_invoice = (int) $address->id;
        $cart->id_currency = $idCurrency;
        $cart->id_lang = $idLang;
        $cart->secure_key = $customer->secure_key;
        $cart->id_guest = 0;
        $idCarrier = 0;
        $carrierRef = (int) Configuration::get('ALLMARKET_CARRIER_REF');
        if ($carrierRef) {
            $carrier = Carrier::getCarrierByReference($carrierRef);
            $idCarrier = $carrier ? (int) $carrier->id : 0;
        }
        $cart->id_carrier = $idCarrier;
        if (!$cart->add()) {
            throw new RuntimeException('Cannot create cart');
        }
        $context->cart = $cart;
        if ($idLinkRow) {
            Db::getInstance()->update('allmarket_order', ['id_cart' => (int) $cart->id], '`id_allmarket_order` = ' . (int) $idLinkRow);
        }

        try {
            $lines = [];
            foreach ($items as $item) {
                list($idProduct, $idPa) = $this->resolveProduct($item);
                $qty = max(1, (int) (isset($item['quantity']) ? $item['quantity'] : 1));
                $key = $idProduct . '-' . $idPa;
                if (isset($lines[$key])) {
                    $lines[$key]['qty'] += $qty;
                } else {
                    $lines[$key] = [
                        'id_product' => $idProduct,
                        'id_product_attribute' => $idPa,
                        'qty' => $qty,
                        'price' => isset($item['price']) ? (float) $item['price'] : null,
                        'name' => isset($item['name']) ? (string) $item['name'] : $key,
                    ];
                }
            }

            if (Configuration::get('ALLMARKET_USE_AM_PRICES')) {
                foreach ($lines as $line) {
                    if ($line['price'] !== null && $line['price'] >= 0) {
                        $this->addCartPrice($cart, $address, $line['id_product'], $line['id_product_attribute'], $line['price'], $idCurrency);
                    }
                }
                if (is_callable(['SpecificPrice', 'flushCache'])) {
                    SpecificPrice::flushCache();
                }
                if (is_callable(['Product', 'flushPriceCache'])) {
                    Product::flushPriceCache();
                }
            }

            foreach ($lines as $line) {
                $result = $cart->updateQty(
                    $line['qty'],
                    $line['id_product'],
                    $line['id_product_attribute'] ?: null,
                    false,
                    'up',
                    (int) $address->id,
                    $shop,
                    false,
                    true
                );
                if ($result !== true && (int) $result <= 0) {
                    throw new RuntimeException(sprintf(
                        'Cannot add "%s" x%d to cart (product inactive, out of stock with backorders disabled, or below minimal quantity)',
                        $line['name'],
                        $line['qty']
                    ));
                }
            }

            if ($idCarrier) {
                $cart->setDeliveryOption([(int) $address->id => $idCarrier . ',']);
                $cart->update();
            }

            $paid = !empty($am['payment']['paid']);
            $idState = self::initialState($am);
            $paymentName = 'allmarket.biz';
            if (!empty($am['payment']['method_name'])) {
                $paymentName .= ': ' . Tools::substr(strip_tags((string) $am['payment']['method_name']), 0, 200);
            }

            $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
            try {
                $this->module->validateOrder(
                    (int) $cart->id,
                    $idState,
                    $total,
                    $paymentName,
                    $this->buildMessage($am),
                    ['transaction_id' => $amId],
                    $idCurrency,
                    false,
                    $customer->secure_key,
                    $shop
                );
                $idOrder = (int) $this->module->currentOrder;
            } catch (Exception $e) {
                // The order may already be saved when a later step (mail, third-party hook) fails.
                $idOrder = (int) Order::getIdByCartId((int) $cart->id);
                if (!$idOrder) {
                    throw $e;
                }
                AllmarketLog::warning('orders', 'Order ' . $amId . ' created as #' . $idOrder . ', but: ' . $e->getMessage());
                $this->ensureState($idOrder, $am);
            }
            if (!$idOrder) {
                throw new RuntimeException('validateOrder() did not create an order');
            }
        } catch (Exception $e) {
            if (!Order::getIdByCartId((int) $cart->id)) {
                $cart->delete();
            }
            throw $e;
        } finally {
            SpecificPrice::deleteByIdCart((int) $cart->id);
        }

        if ($idCarrier && (int) (new Order($idOrder))->id_carrier !== $idCarrier) {
            AllmarketLog::warning('orders', sprintf(
                'Order %s: carrier #%d was not applied — check that it serves the zone of %s and the order weight/price ranges',
                $amId,
                $idCarrier,
                Country::getNameById($idLang, (int) $address->id_country)
            ));
        }
        $this->applyAmShipping($idOrder, $am);

        return $idOrder;
    }

    protected function applyAmShipping($idOrder, array $am)
    {
        if (Configuration::get('ALLMARKET_USE_AM_SHIPPING') && isset($am['delivery']['cost']) && is_numeric($am['delivery']['cost'])) {
            $this->applyShippingCost(new Order((int) $idOrder), (float) $am['delivery']['cost'], !empty($am['payment']['paid']));
        }
    }

    protected static function initialState(array $am)
    {
        $paid = !empty($am['payment']['paid']);
        $idState = (int) Configuration::get($paid ? 'ALLMARKET_OS_PAID' : 'ALLMARKET_OS_NEW');

        return $idState ?: (int) Configuration::get($paid ? 'PS_OS_PAYMENT' : 'PS_OS_PREPARATION');
    }

    /** Gives an order left without a state by an interrupted validateOrder() its initial state. */
    protected function ensureState($idOrder, array $am)
    {
        $order = new Order((int) $idOrder);
        if (!Validate::isLoadedObject($order) || (int) $order->current_state) {
            return;
        }
        Allmarketsync::$applyingRemoteStatus = true;
        try {
            $history = new OrderHistory();
            $history->id_order = (int) $order->id;
            $history->id_employee = 0;
            $history->changeIdOrderState(self::initialState($am), $order, true);
            $history->add();
        } finally {
            Allmarketsync::$applyingRemoteStatus = false;
        }
    }

    /** @return int[] [id_product, id_product_attribute] */
    protected function resolveProduct(array $item)
    {
        $p = _DB_PREFIX_;
        $idProduct = 0;
        $idPa = 0;
        if (!empty($item['external_id'])) {
            list($idProduct, $idPa) = AllmarketCatalog::parseExternalId($item['external_id']);
        }
        if (!$idProduct && !empty($item['sku'])) {
            $sku = pSQL((string) $item['sku']);
            $row = Db::getInstance()->getRow(
                "SELECT `id_product`, `id_product_attribute` FROM `{$p}product_attribute` WHERE `reference` = '{$sku}'"
            );
            if ($row) {
                $idProduct = (int) $row['id_product'];
                $idPa = (int) $row['id_product_attribute'];
            } else {
                $idProduct = (int) Db::getInstance()->getValue(
                    "SELECT `id_product` FROM `{$p}product` WHERE `reference` = '{$sku}'"
                );
            }
        }
        $name = isset($item['name']) ? (string) $item['name'] : '';
        if (!$idProduct || !Product::existsInDatabase($idProduct, 'product')) {
            throw new RuntimeException(sprintf('Product not found: external_id=%s sku=%s %s',
                isset($item['external_id']) ? $item['external_id'] : '',
                isset($item['sku']) ? $item['sku'] : '',
                $name));
        }
        if ($idPa) {
            $belongs = (int) Db::getInstance()->getValue(
                "SELECT 1 FROM `{$p}product_attribute` WHERE `id_product_attribute` = {$idPa} AND `id_product` = {$idProduct}"
            );
            if (!$belongs) {
                throw new RuntimeException(sprintf('Combination %d does not belong to product %d (%s)', $idPa, $idProduct, $name));
            }
        } elseif (Combination::isFeatureActive() && (new Product($idProduct))->hasAttributes()) {
            $idPa = (int) Product::getDefaultAttribute($idProduct);
        }

        return [$idProduct, $idPa];
    }

    /** Cart-scoped specific price so the order keeps the allmarket.biz price. */
    protected function addCartPrice(Cart $cart, Address $address, $idProduct, $idPa, $priceInclTax, $idCurrency)
    {
        $product = new Product((int) $idProduct, false, null, $this->idShop);
        $taxRate = (float) $product->getTaxesRate($address);

        $sp = new SpecificPrice();
        $sp->id_product = (int) $idProduct;
        $sp->id_product_attribute = (int) $idPa;
        $sp->id_cart = (int) $cart->id;
        $sp->id_shop = $this->idShop;
        $sp->id_shop_group = 0;
        $sp->id_currency = (int) $idCurrency;
        $sp->id_country = 0;
        $sp->id_group = 0;
        $sp->id_customer = 0;
        $sp->id_specific_price_rule = 0;
        $sp->price = round($priceInclTax / (1 + $taxRate / 100), 6);
        $sp->from_quantity = 1;
        $sp->reduction = 0;
        $sp->reduction_type = 'amount';
        $sp->reduction_tax = 1;
        $sp->from = '0000-00-00 00:00:00';
        $sp->to = '0000-00-00 00:00:00';
        if (!$sp->add()) {
            throw new RuntimeException('Cannot set allmarket.biz price for product #' . (int) $idProduct);
        }
    }

    protected function applyShippingCost(Order $order, $costInclTax, $paid)
    {
        if (!Validate::isLoadedObject($order)) {
            return;
        }
        $taxRate = 0.0;
        $carrier = new Carrier((int) $order->id_carrier);
        if (Validate::isLoadedObject($carrier)) {
            $taxRate = (float) $carrier->getTaxesRate(new Address((int) $order->id_address_delivery));
        }
        $incl = round(max(0, $costInclTax), 2);
        $excl = round($incl / (1 + $taxRate / 100), 2);
        $diffIncl = $incl - (float) $order->total_shipping_tax_incl;
        $diffExcl = $excl - (float) $order->total_shipping_tax_excl;
        if (abs($diffIncl) < 0.005 && abs($diffExcl) < 0.005) {
            return;
        }

        $order->total_shipping = $incl;
        $order->total_shipping_tax_incl = $incl;
        $order->total_shipping_tax_excl = $excl;
        $order->carrier_tax_rate = $taxRate;
        $order->total_paid = round($order->total_paid + $diffIncl, 2);
        $order->total_paid_tax_incl = round($order->total_paid_tax_incl + $diffIncl, 2);
        $order->total_paid_tax_excl = round($order->total_paid_tax_excl + $diffExcl, 2);
        if ($paid) {
            $order->total_paid_real = $order->total_paid_tax_incl;
        }
        $order->update();

        $orderCarrier = new OrderCarrier((int) $order->getIdOrderCarrier());
        if (Validate::isLoadedObject($orderCarrier)) {
            $orderCarrier->shipping_cost_tax_incl = $incl;
            $orderCarrier->shipping_cost_tax_excl = $excl;
            $orderCarrier->update();
        }

        if ($paid) {
            $payments = OrderPayment::getByOrderReference($order->reference);
            if (count($payments) === 1) {
                $payment = $payments[0];
                $payment->amount = $order->total_paid_tax_incl;
                $payment->update();
            }
        }
    }

    protected function resolveCustomer(array $c, $amId, $idLang)
    {
        $email = isset($c['email']) ? trim((string) $c['email']) : '';
        if (!Validate::isEmail($email)) {
            $email = 'am-' . Tools::strtolower(preg_replace('/[^a-z0-9]/i', '', $amId)) . '@allmarket.invalid';
        }

        $customer = new Customer();
        $customer->getByEmail($email, null, false);
        if (Validate::isLoadedObject($customer)) {
            return $customer;
        }

        $customer = new Customer();
        $customer->firstname = self::cleanName(isset($c['first_name']) ? $c['first_name'] : '', 'Покупець');
        $customer->lastname = self::cleanName(isset($c['last_name']) ? $c['last_name'] : '', 'Allmarket');
        $customer->email = $email;
        $customer->passwd = self::hashPassword(Tools::passwdGen(16));
        // Customer::add() refuses guests when guest checkout is disabled (the 1.6 default).
        $asGuest = (bool) Configuration::get('PS_GUEST_CHECKOUT_ENABLED');
        $customer->is_guest = $asGuest ? 1 : 0;
        $customer->id_default_group = (int) Configuration::get($asGuest ? 'PS_GUEST_GROUP' : 'PS_CUSTOMER_GROUP');
        $customer->id_shop = $this->idShop;
        $customer->id_shop_group = (int) Shop::getGroupFromShop($this->idShop);
        $customer->id_lang = (int) $idLang;
        $customer->newsletter = 0;
        $customer->optin = 0;
        $customer->active = 1;
        if (!$customer->add()) {
            throw new RuntimeException('Cannot create customer ' . $email);
        }

        return $customer;
    }

    protected function createAddress(Customer $customer, array $am)
    {
        $c = isset($am['customer']) ? (array) $am['customer'] : [];
        $d = isset($am['delivery']) ? (array) $am['delivery'] : [];

        $idCountry = !empty($d['country']) ? (int) Country::getByIso((string) $d['country']) : 0;
        if (!$idCountry) {
            $idCountry = (int) Configuration::get('ALLMARKET_ID_COUNTRY') ?: (int) Configuration::get('PS_COUNTRY_DEFAULT');
        }

        $phone = isset($c['phone']) ? preg_replace('/[^+0-9. ()\/-]/', '', (string) $c['phone']) : '';
        $street = [];
        foreach (['address', 'warehouse'] as $field) {
            if (!empty($d[$field])) {
                $street[] = (string) $d[$field];
            }
        }

        $address = new Address();
        $address->id_customer = (int) $customer->id;
        $address->id_country = $idCountry;
        $address->alias = 'allmarket.biz';
        $address->firstname = self::cleanName(isset($d['recipient_first_name']) ? $d['recipient_first_name'] : $customer->firstname, $customer->firstname);
        $address->lastname = self::cleanName(isset($d['recipient_last_name']) ? $d['recipient_last_name'] : $customer->lastname, $customer->lastname);
        $address->address1 = self::cleanText(implode(', ', $street), '/[!<>?=+@{}_$%]/u', 128) ?: '-';
        $address->city = self::cleanText(isset($d['city']) ? $d['city'] : '', '/[!<>;?=+@#"°{}_$%]/u', 64) ?: '-';
        $postcode = isset($d['postcode']) ? trim((string) $d['postcode']) : '';
        $address->postcode = Validate::isPostCode($postcode) ? $postcode : '';
        $address->phone = $phone;
        $address->phone_mobile = $phone;
        if (!empty($d['region']) && Country::containsStates($idCountry)) {
            $idState = (int) State::getIdByName((string) $d['region']);
            if ($idState) {
                $address->id_state = $idState;
            }
        }
        $other = [];
        if (!empty($d['method_name'])) {
            $other[] = (string) $d['method_name'];
        }
        if (!empty($d['region'])) {
            $other[] = (string) $d['region'];
        }
        $address->other = self::cleanText(implode('; ', $other), '/[<>{}]/u', 300);

        if (!$address->add()) {
            throw new RuntimeException('Cannot create delivery address');
        }

        return $address;
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
        $text = strip_tags(implode("\n", $lines));

        return Validate::isCleanHtml($text) ? $text : '';
    }

    protected static function cleanName($value, $fallback)
    {
        $value = preg_replace('/[0-9!<>,;?=+()\/\\\\@#"°*`{}_^$%:¤\[\]|\.。]/u', '', (string) $value);
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        $value = Tools::substr($value, 0, 64);

        return $value !== '' && Validate::isCustomerName($value) ? $value : $fallback;
    }

    protected static function cleanText($value, $forbidden, $max)
    {
        $value = trim(preg_replace('/\s+/u', ' ', preg_replace($forbidden, ' ', strip_tags((string) $value))));

        return Tools::substr($value, 0, $max);
    }

    protected static function hashPassword($plain)
    {
        try {
            $crypto = \PrestaShop\PrestaShop\Adapter\ServiceLocator::get('\\PrestaShop\\PrestaShop\\Core\\Crypto\\Hashing');

            return $crypto->hash($plain);
        } catch (Exception $e) {
            return md5(_COOKIE_KEY_ . $plain);
        }
    }
}

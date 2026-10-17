<?php
/**
 * allmarket.biz exchange module for PrestaShop.
 *
 * Uploads the product catalog to the allmarket.biz portal, keeps prices and
 * stock in sync on a schedule and imports orders placed on allmarket.biz.
 *
 * @author    allmarket.biz
 * @copyright 2026 allmarket.biz
 * @license   Commercial
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/AllmarketConfig.php';
require_once __DIR__ . '/classes/AllmarketLog.php';
require_once __DIR__ . '/classes/AllmarketApiException.php';
require_once __DIR__ . '/classes/AllmarketClient.php';
require_once __DIR__ . '/classes/AllmarketCatalog.php';
require_once __DIR__ . '/classes/AllmarketFeed.php';
require_once __DIR__ . '/classes/AllmarketOrderImporter.php';
require_once __DIR__ . '/classes/AllmarketExchange.php';

class Allmarketsync extends PaymentModule
{
    /** Statuses understood by allmarket.biz (see docs/API.md). */
    const AM_STATUSES = ['accepted', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'];

    /** Set while a status change is applied from allmarket.biz so it is not pushed back. */
    public static $applyingRemoteStatus = false;

    public function __construct()
    {
        $this->name = 'allmarketsync';
        $this->tab = 'market_place';
        $this->version = '1.1.0';
        $this->author = 'allmarket.biz';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];
        $this->currencies = false;

        parent::__construct();

        $this->displayName = $this->l('allmarket.biz exchange');
        $this->description = $this->l('Uploads the catalog to allmarket.biz, syncs prices, stock and orders.');
        $this->confirmUninstall = $this->l('All sync data (links to allmarket.biz items and orders) will be removed. Continue?');
    }

    /* ------------------------------------------------------------------ */
    /* Install / uninstall                                                 */
    /* ------------------------------------------------------------------ */

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        try {
            $ok = $this->installDb()
                && $this->installOrderState()
                && AllmarketConfig::installDefaults()
                && $this->registerHook('actionOrderStatusPostUpdate')
                && $this->registerHook('actionAdminOrdersTrackingNumberUpdate')
                && $this->registerHook('displayAdminOrderSide')
                && $this->registerHook('displayAdminOrderLeft');
        } catch (Exception $e) {
            $this->_errors[] = $e->getMessage();
            $ok = false;
        }
        if (!$ok) {
            // Do not leave a half-installed module registered.
            parent::uninstall();
        }

        return $ok;
    }

    public function uninstall()
    {
        $idState = (int) Configuration::get('ALLMARKET_OS_NEW');
        if ($idState) {
            $state = new OrderState($idState);
            if (Validate::isLoadedObject($state)) {
                // Orders may still reference it, so hide instead of deleting.
                $state->deleted = 1;
                $state->update();
            }
        }

        foreach (AllmarketFeed::TYPES as $type) {
            foreach (glob(AllmarketFeed::dir() . $type . '_*.xml') ?: [] as $file) {
                @unlink($file);
            }
        }

        return AllmarketConfig::uninstall()
            && $this->uninstallDb()
            && parent::uninstall();
    }

    protected function installDb()
    {
        $engine = _MYSQL_ENGINE_;
        $p = _DB_PREFIX_;
        $sql = [
            "CREATE TABLE IF NOT EXISTS `{$p}allmarket_offer` (
                `id_shop` INT UNSIGNED NOT NULL,
                `id_product` INT UNSIGNED NOT NULL,
                `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
                `content_hash` CHAR(32) NOT NULL DEFAULT '',
                `offer_hash` CHAR(32) NOT NULL DEFAULT '',
                `status` VARCHAR(16) NOT NULL DEFAULT 'active',
                `date_content` DATETIME NULL,
                `date_offer` DATETIME NULL,
                PRIMARY KEY (`id_shop`, `id_product`, `id_product_attribute`),
                KEY `status` (`status`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$p}allmarket_order` (
                `id_allmarket_order` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL,
                `am_order_id` VARCHAR(64) NOT NULL,
                `am_number` VARCHAR(64) NOT NULL DEFAULT '',
                `id_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_cart` INT UNSIGNED NOT NULL DEFAULT 0,
                `am_status` VARCHAR(32) NOT NULL DEFAULT '',
                `pushed_status` VARCHAR(32) NOT NULL DEFAULT '',
                `pending_status` VARCHAR(32) NOT NULL DEFAULT '',
                `acked` TINYINT(1) NOT NULL DEFAULT 0,
                `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `error` TEXT NULL,
                `payload` MEDIUMTEXT NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_allmarket_order`),
                UNIQUE KEY `am_order` (`id_shop`, `am_order_id`),
                KEY `id_order` (`id_order`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$p}allmarket_log` (
                `id_allmarket_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `level` VARCHAR(8) NOT NULL,
                `task` VARCHAR(32) NOT NULL,
                `message` TEXT NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_allmarket_log`),
                KEY `date_add` (`date_add`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    protected function uninstallDb()
    {
        $p = _DB_PREFIX_;

        return Db::getInstance()->execute(
            "DROP TABLE IF EXISTS `{$p}allmarket_offer`, `{$p}allmarket_order`, `{$p}allmarket_log`"
        );
    }

    protected function installOrderState()
    {
        // Reuse the state left (hidden) by a previous installation: old orders reference it.
        $idExisting = (int) Configuration::get('ALLMARKET_OS_NEW') ?: (int) Db::getInstance()->getValue(
            'SELECT `id_order_state` FROM `' . _DB_PREFIX_ . "order_state`
             WHERE `module_name` = '" . pSQL($this->name) . "' ORDER BY `id_order_state`"
        );
        if ($idExisting) {
            $existing = new OrderState($idExisting);
            if (Validate::isLoadedObject($existing)) {
                $existing->deleted = 0;

                return $existing->update() && Configuration::updateGlobalValue('ALLMARKET_OS_NEW', $idExisting);
            }
        }

        $state = new OrderState();
        foreach (Language::getLanguages(false) as $lang) {
            $state->name[$lang['id_lang']] = $lang['iso_code'] === 'uk'
                ? 'allmarket.biz: нове замовлення'
                : 'allmarket.biz: new order';
        }
        $state->color = '#FF6F00';
        $state->module_name = $this->name;
        $state->send_email = false;
        $state->logable = false;
        $state->invoice = false;
        $state->hidden = false;
        $state->unremovable = false;
        $state->delivery = false;
        $state->shipped = false;
        $state->paid = false;
        $state->deleted = false;
        if (!$state->add()) {
            return false;
        }

        return Configuration::updateGlobalValue('ALLMARKET_OS_NEW', (int) $state->id);
    }

    /* ------------------------------------------------------------------ */
    /* Hooks                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Pushes a PrestaShop order status change back to allmarket.biz.
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        if (self::$applyingRemoteStatus || empty($params['id_order']) || empty($params['newOrderStatus'])) {
            return;
        }
        $idState = (int) $params['newOrderStatus']->id;
        try {
            (new AllmarketExchange($this))->pushOrderState((int) $params['id_order'], $idState);
        } catch (Exception $e) {
            AllmarketLog::error('status', 'Order #' . (int) $params['id_order'] . ': ' . $e->getMessage());
        }
    }

    /**
     * Re-sends the current status with the new tracking number.
     */
    public function hookActionAdminOrdersTrackingNumberUpdate($params)
    {
        if (empty($params['order']) || !Validate::isLoadedObject($params['order'])) {
            return;
        }
        $order = $params['order'];
        try {
            (new AllmarketExchange($this))->pushOrderState((int) $order->id, (int) $order->getCurrentState(), true);
        } catch (Exception $e) {
            AllmarketLog::error('status', 'Order #' . (int) $order->id . ': ' . $e->getMessage());
        }
    }

    /** PrestaShop 1.7.7+ order page. */
    public function hookDisplayAdminOrderSide($params)
    {
        return $this->renderOrderPanel(isset($params['id_order']) ? (int) $params['id_order'] : 0);
    }

    /** PrestaShop 1.7.6 order page. */
    public function hookDisplayAdminOrderLeft($params)
    {
        if (version_compare(_PS_VERSION_, '1.7.7.0', '>=')) {
            return '';
        }

        return $this->renderOrderPanel(isset($params['id_order']) ? (int) $params['id_order'] : 0);
    }

    protected function renderOrderPanel($idOrder)
    {
        if (!$idOrder) {
            return '';
        }
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'allmarket_order` WHERE `id_order` = ' . (int) $idOrder
        );
        if (!$row) {
            return '';
        }
        $this->context->smarty->assign(['am_order' => $row]);

        return $this->display(__FILE__, 'views/templates/hook/admin_order.tpl');
    }

    /* ------------------------------------------------------------------ */
    /* Configuration page                                                  */
    /* ------------------------------------------------------------------ */

    public function getContent()
    {
        $output = $this->postProcess();

        return $output . $this->renderSettingsForm() . $this->renderCategoryMap() . $this->renderTools();
    }

    protected function postProcess()
    {
        $out = '';

        if (Tools::isSubmit('submitAllmarketCategoryMap')) {
            return $this->saveCategoryMap();
        }
        if (Tools::isSubmit('submitAllmarketRefreshPortalCats')) {
            try {
                $list = AllmarketConfig::getPortalCategories(0, true);

                return $this->displayConfirmation(sprintf($this->l('Loaded %d categories from allmarket.biz.'), count($list)));
            } catch (Exception $e) {
                return $this->displayError(Tools::safeOutput($e->getMessage()));
            }
        }

        if (Tools::isSubmit('submitAllmarketSettings')) {
            $errors = $this->saveSettings();

            return $errors
                ? $this->displayError(implode('<br>', $errors))
                : $this->displayConfirmation($this->l('Settings saved.'));
        }

        $actions = [
            'submitAllmarketPing' => 'ping',
            'submitAllmarketCatalog' => AllmarketExchange::TASK_CATALOG,
            'submitAllmarketOffers' => AllmarketExchange::TASK_OFFERS,
            'submitAllmarketOrders' => AllmarketExchange::TASK_ORDERS,
            'submitAllmarketFeed' => AllmarketExchange::TASK_FEED,
        ];
        foreach ($actions as $submit => $task) {
            if (!Tools::isSubmit($submit)) {
                continue;
            }
            try {
                if ($task === 'ping') {
                    $info = AllmarketClient::fromConfig()->ping();
                    $name = isset($info['shop']['name']) ? ' (' . $info['shop']['name'] . ')' : '';

                    return $this->displayConfirmation($this->l('Connection to allmarket.biz is OK.') . Tools::safeOutput($name));
                }
                $stats = (new AllmarketExchange($this))->run($task, (bool) Tools::getValue('force'));

                return $this->displayConfirmation(
                    sprintf($this->l('Task "%s" finished: %s'), $task, Tools::safeOutput(AllmarketExchange::formatStats($stats)))
                );
            } catch (Exception $e) {
                return $this->displayError(Tools::safeOutput($e->getMessage()));
            }
        }

        if (Tools::isSubmit('submitAllmarketResetHashes')) {
            Db::getInstance()->execute(
                'UPDATE `' . _DB_PREFIX_ . 'allmarket_offer` SET `content_hash` = \'\', `offer_hash` = \'\'
                 WHERE `id_shop` = ' . (int) $this->context->shop->id
            );
            Configuration::updateValue('ALLMARKET_CATEGORIES_HASH', '');

            return $this->displayConfirmation($this->l('Hashes reset: the next sync will resend the whole catalog.'));
        }

        if (Tools::isSubmit('submitAllmarketRetryOrders')) {
            $idShop = (int) $this->context->shop->id;
            $rows = Db::getInstance()->executeS(
                'SELECT `am_order_id`, `payload` FROM `' . _DB_PREFIX_ . 'allmarket_order`
                 WHERE `id_shop` = ' . $idShop . ' AND `id_order` = 0'
            ) ?: [];
            Db::getInstance()->update('allmarket_order', ['attempts' => 0], '`id_shop` = ' . $idShop . ' AND `id_order` = 0');
            $sync = new AllmarketExchange($this);
            $importer = new AllmarketOrderImporter($this, $sync, $idShop);
            $imported = 0;
            foreach ($rows as $row) {
                $payload = json_decode((string) $row['payload'], true);
                if (is_array($payload) && $importer->process($payload, !AllmarketClient::fromConfig()->isConfigured()) === 'imported') {
                    ++$imported;
                }
            }

            return $this->displayConfirmation(sprintf($this->l('Imported %d of %d failed orders. See the log for details.'), $imported, count($rows)));
        }

        if (Tools::isSubmit('submitAllmarketClearLog')) {
            AllmarketLog::clear();

            return $this->displayConfirmation($this->l('Log cleared.'));
        }

        if (Tools::isSubmit('submitAllmarketRegenerateTokens')) {
            Configuration::updateValue('ALLMARKET_CRON_TOKEN', AllmarketConfig::randomToken());
            Configuration::updateValue('ALLMARKET_FEED_TOKEN', AllmarketConfig::randomToken());
            Configuration::updateValue('ALLMARKET_WEBHOOK_SECRET', AllmarketConfig::randomToken(40));

            return $this->displayConfirmation($this->l('Tokens regenerated. Update the URLs in cron and on allmarket.biz.'));
        }

        return $out;
    }

    /**
     * @return string[] errors
     */
    protected function saveSettings()
    {
        $errors = [];

        $apiUrl = trim((string) Tools::getValue('ALLMARKET_API_URL'));
        if ($apiUrl !== '' && !Validate::isAbsoluteUrl($apiUrl)) {
            $errors[] = $this->l('API URL is not a valid absolute URL.');
        }
        $mode = (string) Tools::getValue('ALLMARKET_MODE');
        if (!in_array($mode, AllmarketConfig::MODES, true)) {
            $errors[] = $this->l('Unknown exchange mode.');
        }
        $markup = str_replace(',', '.', (string) Tools::getValue('ALLMARKET_PRICE_MARKUP'));
        if ($markup !== '' && !is_numeric($markup)) {
            $errors[] = $this->l('Markup must be a number.');
        }
        $batch = (int) Tools::getValue('ALLMARKET_BATCH_SIZE');
        if ($batch < 1 || $batch > 1000) {
            $errors[] = $this->l('Batch size must be between 1 and 1000.');
        }
        if ($errors) {
            return $errors;
        }

        Configuration::updateValue('ALLMARKET_MODE', $mode);
        Configuration::updateValue('ALLMARKET_API_URL', rtrim($apiUrl, '/'));
        // Empty key field means "keep the stored one" (it is never echoed back).
        $apiKey = trim((string) Tools::getValue('ALLMARKET_API_KEY'));
        if ($apiKey !== '') {
            Configuration::updateValue('ALLMARKET_API_KEY', $apiKey);
        }
        Configuration::updateValue('ALLMARKET_SHOP_ID', trim((string) Tools::getValue('ALLMARKET_SHOP_ID')));

        Configuration::updateValue('ALLMARKET_ID_LANG', (int) Tools::getValue('ALLMARKET_ID_LANG'));
        Configuration::updateValue('ALLMARKET_ID_CURRENCY', (int) Tools::getValue('ALLMARKET_ID_CURRENCY'));
        $categories = Tools::getValue('ALLMARKET_CATEGORIES');
        $categories = is_array($categories) ? array_values(array_unique(array_map('intval', $categories))) : [];
        Configuration::updateValue('ALLMARKET_CATEGORIES', json_encode($categories));
        Configuration::updateValue('ALLMARKET_COMBINATIONS', (int) Tools::getValue('ALLMARKET_COMBINATIONS'));
        Configuration::updateValue('ALLMARKET_ONLY_IN_STOCK', (int) Tools::getValue('ALLMARKET_ONLY_IN_STOCK'));
        Configuration::updateValue('ALLMARKET_IMAGE_TYPE', (string) Tools::getValue('ALLMARKET_IMAGE_TYPE'));
        Configuration::updateValue('ALLMARKET_PRICE_MARKUP', (float) $markup);
        Configuration::updateValue('ALLMARKET_BATCH_SIZE', $batch);

        Configuration::updateValue('ALLMARKET_ORDERS_ENABLED', (int) Tools::getValue('ALLMARKET_ORDERS_ENABLED'));
        Configuration::updateValue('ALLMARKET_OS_NEW', (int) Tools::getValue('ALLMARKET_OS_NEW'));
        Configuration::updateValue('ALLMARKET_OS_PAID', (int) Tools::getValue('ALLMARKET_OS_PAID'));
        Configuration::updateValue('ALLMARKET_CARRIER_REF', (int) Tools::getValue('ALLMARKET_CARRIER_REF'));
        Configuration::updateValue('ALLMARKET_ID_COUNTRY', (int) Tools::getValue('ALLMARKET_ID_COUNTRY'));
        Configuration::updateValue('ALLMARKET_USE_AM_PRICES', (int) Tools::getValue('ALLMARKET_USE_AM_PRICES'));
        Configuration::updateValue('ALLMARKET_USE_AM_SHIPPING', (int) Tools::getValue('ALLMARKET_USE_AM_SHIPPING'));

        $map = [];
        foreach (OrderState::getOrderStates((int) $this->context->language->id) as $state) {
            $value = (string) Tools::getValue('ALLMARKET_STATUS_' . (int) $state['id_order_state']);
            if (in_array($value, self::AM_STATUSES, true)) {
                $map[(int) $state['id_order_state']] = $value;
            }
        }
        Configuration::updateValue('ALLMARKET_STATUS_MAP', json_encode($map));

        return [];
    }

    protected function renderSettingsForm()
    {
        $idLang = (int) $this->context->language->id;

        $modeOptions = [
            ['id' => 'api', 'name' => $this->l('API: the module pushes data to allmarket.biz')],
            ['id' => 'feed', 'name' => $this->l('Feed: allmarket.biz downloads the XML feed by URL')],
            ['id' => 'both', 'name' => $this->l('API + feed')],
        ];

        $languages = [];
        foreach (Language::getLanguages(true) as $lang) {
            $languages[] = ['id' => (int) $lang['id_lang'], 'name' => $lang['name']];
        }
        $currencies = [];
        foreach (Currency::getCurrencies(false, true) as $currency) {
            $currencies[] = ['id' => (int) $currency['id_currency'], 'name' => $currency['iso_code'] . ' — ' . $currency['name']];
        }
        $imageTypes = [['id' => '', 'name' => $this->l('Original image')]];
        foreach (ImageType::getImagesTypes('products') as $type) {
            $imageTypes[] = ['id' => $type['name'], 'name' => $type['name'] . ' (' . $type['width'] . '×' . $type['height'] . ')'];
        }
        $states = [];
        foreach (OrderState::getOrderStates($idLang) as $state) {
            $states[] = ['id' => (int) $state['id_order_state'], 'name' => $state['name']];
        }
        $carriers = [['id' => 0, 'name' => $this->l('— not set —')]];
        foreach (Carrier::getCarriers($idLang, false, false, false, null, Carrier::ALL_CARRIERS) as $carrier) {
            $carriers[] = ['id' => (int) $carrier['id_reference'], 'name' => $carrier['name']];
        }
        $countries = [];
        foreach (Country::getCountries($idLang, true) as $country) {
            $countries[] = ['id' => (int) $country['id_country'], 'name' => $country['name']];
        }
        $amStatuses = [['id' => '', 'name' => $this->l('— do not send —')]];
        foreach (self::AM_STATUSES as $status) {
            $amStatuses[] = ['id' => $status, 'name' => $status];
        }

        $switch = function ($name, $label, $desc = '') {
            return [
                'type' => 'switch',
                'label' => $label,
                'name' => $name,
                'desc' => $desc,
                'is_bool' => true,
                'values' => [
                    ['id' => $name . '_on', 'value' => 1, 'label' => $this->l('Yes')],
                    ['id' => $name . '_off', 'value' => 0, 'label' => $this->l('No')],
                ],
            ];
        };

        $forms = [];
        $forms[] = ['form' => [
            'legend' => ['title' => $this->l('Connection to allmarket.biz'), 'icon' => 'icon-link'],
            'input' => [
                [
                    'type' => 'select', 'label' => $this->l('Exchange mode'), 'name' => 'ALLMARKET_MODE',
                    'options' => ['query' => $modeOptions, 'id' => 'id', 'name' => 'name'],
                ],
                [
                    'type' => 'text', 'label' => $this->l('API URL'), 'name' => 'ALLMARKET_API_URL',
                    'desc' => $this->l('Base URL of the allmarket.biz seller API, e.g. https://allmarket.biz/api/v1'),
                ],
                [
                    'type' => 'text', 'label' => $this->l('Seller ID'), 'name' => 'ALLMARKET_SHOP_ID',
                    'desc' => $this->l('Your company/shop ID in the allmarket.biz cabinet.'),
                ],
                [
                    'type' => 'password', 'label' => $this->l('API key'), 'name' => 'ALLMARKET_API_KEY',
                    'desc' => Configuration::get('ALLMARKET_API_KEY')
                        ? $this->l('A key is saved. Leave empty to keep it.')
                        : $this->l('Generate the key in the allmarket.biz seller cabinet.'),
                ],
            ],
            'submit' => ['title' => $this->l('Save'), 'name' => 'submitAllmarketSettings'],
        ]];

        $forms[] = ['form' => [
            'legend' => ['title' => $this->l('Catalog export'), 'icon' => 'icon-list'],
            'input' => [
                [
                    'type' => 'select', 'label' => $this->l('Language'), 'name' => 'ALLMARKET_ID_LANG',
                    'options' => ['query' => $languages, 'id' => 'id', 'name' => 'name'],
                ],
                [
                    'type' => 'select', 'label' => $this->l('Currency'), 'name' => 'ALLMARKET_ID_CURRENCY',
                    'options' => ['query' => $currencies, 'id' => 'id', 'name' => 'name'],
                    'desc' => $this->l('Prices are converted to this currency.'),
                ],
                [
                    'type' => 'categories', 'label' => $this->l('Categories to export'), 'name' => 'ALLMARKET_CATEGORIES',
                    'tree' => [
                        'id' => 'allmarket-categories-tree',
                        'selected_categories' => AllmarketConfig::getCategories(),
                        'use_checkbox' => true,
                        'use_search' => true,
                    ],
                    'desc' => $this->l('Nothing selected = whole catalog. A product is exported if it belongs to any selected category.'),
                ],
                $switch('ALLMARKET_COMBINATIONS', $this->l('Export combinations as separate offers'),
                    $this->l('Each combination becomes an offer with its own price and stock, grouped by group_id.')),
                $switch('ALLMARKET_ONLY_IN_STOCK', $this->l('Only items available for order'),
                    $this->l('Unavailable items are removed from allmarket.biz instead of being shown as "out of stock".')),
                [
                    'type' => 'select', 'label' => $this->l('Image size'), 'name' => 'ALLMARKET_IMAGE_TYPE',
                    'options' => ['query' => $imageTypes, 'id' => 'id', 'name' => 'name'],
                ],
                [
                    'type' => 'text', 'label' => $this->l('Price markup, %'), 'name' => 'ALLMARKET_PRICE_MARKUP',
                    'class' => 'fixed-width-sm', 'desc' => $this->l('Negative value = discount. 0 = shop prices.'),
                ],
                [
                    'type' => 'text', 'label' => $this->l('Batch size'), 'name' => 'ALLMARKET_BATCH_SIZE',
                    'class' => 'fixed-width-sm', 'desc' => $this->l('Items per API request.'),
                ],
            ],
            'submit' => ['title' => $this->l('Save'), 'name' => 'submitAllmarketSettings'],
        ]];

        $forms[] = ['form' => [
            'legend' => ['title' => $this->l('Orders from allmarket.biz'), 'icon' => 'icon-shopping-cart'],
            'input' => [
                $switch('ALLMARKET_ORDERS_ENABLED', $this->l('Import orders')),
                [
                    'type' => 'select', 'label' => $this->l('Status of a new order'), 'name' => 'ALLMARKET_OS_NEW',
                    'options' => ['query' => $states, 'id' => 'id', 'name' => 'name'],
                ],
                [
                    'type' => 'select', 'label' => $this->l('Status of a prepaid order'), 'name' => 'ALLMARKET_OS_PAID',
                    'options' => ['query' => $states, 'id' => 'id', 'name' => 'name'],
                ],
                [
                    'type' => 'select', 'label' => $this->l('Carrier'), 'name' => 'ALLMARKET_CARRIER_REF',
                    'options' => ['query' => $carriers, 'id' => 'id', 'name' => 'name'],
                    'desc' => $this->l('Assigned to imported orders. It must serve the delivery country; a free carrier is recommended.'),
                ],
                [
                    'type' => 'select', 'label' => $this->l('Delivery country'), 'name' => 'ALLMARKET_ID_COUNTRY',
                    'options' => ['query' => $countries, 'id' => 'id', 'name' => 'name'],
                    'desc' => $this->l('Used when the order has no country code.'),
                ],
                $switch('ALLMARKET_USE_AM_PRICES', $this->l('Use prices from the allmarket.biz order'),
                    $this->l('Otherwise current shop prices are applied.')),
                $switch('ALLMARKET_USE_AM_SHIPPING', $this->l('Use shipping cost from the allmarket.biz order')),
            ],
            'submit' => ['title' => $this->l('Save'), 'name' => 'submitAllmarketSettings'],
        ]];

        $statusInputs = [];
        foreach ($states as $state) {
            $statusInputs[] = [
                'type' => 'select', 'label' => $state['name'], 'name' => 'ALLMARKET_STATUS_' . $state['id'],
                'options' => ['query' => $amStatuses, 'id' => 'id', 'name' => 'name'],
            ];
        }
        $forms[] = ['form' => [
            'legend' => ['title' => $this->l('Order status mapping (shop → allmarket.biz)'), 'icon' => 'icon-exchange'],
            'description' => $this->l('When an imported order gets one of these statuses, allmarket.biz is notified. Statuses coming from allmarket.biz are applied by the same table in reverse.'),
            'input' => $statusInputs,
            'submit' => ['title' => $this->l('Save'), 'name' => 'submitAllmarketSettings'],
        ]];

        $values = AllmarketConfig::getFormValues();
        $values['ALLMARKET_API_KEY'] = '';
        $statusMap = AllmarketConfig::getStatusMap();
        foreach ($states as $state) {
            $values['ALLMARKET_STATUS_' . $state['id']] = isset($statusMap[$state['id']]) ? $statusMap[$state['id']] : '';
        }

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = $idLang;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->show_toolbar = false;
        $helper->submit_action = 'submitAllmarketSettings';
        $helper->tpl_vars = ['fields_value' => $values];

        return $helper->generateForm($forms);
    }

    /**
     * Saves the placement panel: am_cat[id] = portal category, am_mode[id] = map|inside; or the raw rules
     * (am_raw) when the portal tree is not available. Rules for categories not shown in the form are kept.
     */
    protected function saveCategoryMap()
    {
        if (Tools::getIsset('am_raw')) {
            Configuration::updateValue('ALLMARKET_CATEGORY_MAP', (string) Tools::getValue('am_raw'));
        } else {
            $map = AllmarketConfig::getCategoryMap();
            $cats = Tools::getValue('am_cat');
            $modes = Tools::getValue('am_mode');
            foreach (is_array($cats) ? $cats : [] as $idCategory => $portal) {
                $portal = trim((string) $portal);
                if ($portal === '' || !preg_match('/^[\w\-\.\/]+$/u', $portal)) {
                    unset($map[(int) $idCategory]);
                    continue;
                }
                $mode = is_array($modes) && isset($modes[$idCategory]) && $modes[$idCategory] === 'inside' ? 'inside' : 'map';
                $map[(int) $idCategory] = ['id' => $portal, 'mode' => $mode];
            }
            AllmarketConfig::setCategoryMap($map);
        }
        $out = $this->displayConfirmation($this->l('Category placement saved.'));
        if (Tools::getValue('am_send_now') && !AllmarketConfig::usesFeed()) {
            try {
                $stats = (new AllmarketExchange($this))->run(AllmarketExchange::TASK_CATALOG, false);
                $out .= $this->displayConfirmation(
                    sprintf($this->l('Task "%s" finished: %s'), AllmarketExchange::TASK_CATALOG, Tools::safeOutput(AllmarketExchange::formatStats($stats)))
                );
            } catch (Exception $e) {
                $out .= $this->displayError(Tools::safeOutput($e->getMessage()));
            }
        } else {
            $out .= $this->displayConfirmation($this->l('The portal applies it with the next catalog sync.'));
        }

        return $out;
    }

    /** Panel «Category placement on allmarket.biz»: the shop's category tree with a portal category picker per row. */
    protected function renderCategoryMap()
    {
        $catalog = new AllmarketCatalog((int) $this->context->shop->id, AllmarketConfig::getCatalogOptions());
        $categories = $catalog->getCategories();
        $map = AllmarketConfig::getCategoryMap();

        $portal = null;
        $portalError = '';
        if (AllmarketConfig::getMode() !== 'feed') {
            try {
                $portal = AllmarketConfig::getPortalCategories();
            } catch (Exception $e) {
                $portalError = $e->getMessage();
            }
        }
        $paths = [];
        foreach (is_array($portal) ? $portal : [] as $p) {
            $paths[(string) $p['id']] = (string) $p['path'];
        }

        // Rows in tree order with depth and the placement each category ends up with.
        $byParent = [];
        foreach ($categories as $c) {
            $byParent[(string) $c['parent_id']][] = $c;
        }
        $rows = [];
        $walk = function ($parent, $depth, $inherited) use (&$walk, &$rows, $byParent, $map, $paths) {
            if (empty($byParent[$parent]) || $depth > 30) {
                return;
            }
            foreach ($byParent[$parent] as $c) {
                $id = (int) $c['id'];
                $rule = isset($map[$id]) ? $map[$id] : null;
                // Resulting path on the portal; the portal creates subcategories under it.
                if ($rule) {
                    $path = isset($paths[$rule['id']]) ? $paths[$rule['id']] : '#' . $rule['id'];
                    $effective = $rule['mode'] === 'inside' ? $path . ' > ' . $c['name'] : $path;
                    $childInherited = $effective;
                } elseif ($inherited !== null) {
                    $effective = $inherited . ' > ' . $c['name'];
                    $childInherited = $effective;
                } else {
                    $effective = $this->l('portal root') . ' > ' . $c['name'];
                    $childInherited = $effective;
                }
                $rows[] = [
                    'id' => $id,
                    'name' => $c['name'],
                    'depth' => $depth,
                    'portal' => $rule ? (string) $rule['id'] : '',
                    'mode' => $rule ? $rule['mode'] : 'map',
                    'effective' => $effective,
                ];
                $walk((string) $id, $depth + 1, $childInherited);
            }
        };
        $walk('', 0, null);

        $this->context->smarty->assign([
            'am_action' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
            'am_rows' => $rows,
            'am_portal_json' => json_encode(is_array($portal) ? $portal : [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'am_portal_ok' => is_array($portal) && count($portal) > 0,
            'am_portal_error' => $portalError,
            'am_raw_map' => (string) Configuration::get('ALLMARKET_CATEGORY_MAP'),
            'am_feed_mode' => AllmarketConfig::getMode() === 'feed',
        ]);

        return $this->display(__FILE__, 'views/templates/admin/category_map.tpl');
    }

    protected function renderTools()
    {
        $idShop = (int) $this->context->shop->id;
        $link = $this->context->link;
        $cronToken = Configuration::get('ALLMARKET_CRON_TOKEN');
        $feedToken = Configuration::get('ALLMARKET_FEED_TOKEN');

        $cronUrls = [];
        foreach (AllmarketExchange::TASKS as $task) {
            $cronUrls[$task] = $link->getModuleLink($this->name, 'cron', ['token' => $cronToken, 'task' => $task], true, null, $idShop);
        }

        $counts = Db::getInstance()->executeS(
            'SELECT `status`, COUNT(*) AS n FROM `' . _DB_PREFIX_ . 'allmarket_offer`
             WHERE `id_shop` = ' . $idShop . ' GROUP BY `status`'
        );
        $offerCounts = [];
        foreach ($counts ?: [] as $row) {
            $offerCounts[$row['status']] = (int) $row['n'];
        }

        $orderStats = Db::getInstance()->getRow(
            'SELECT COUNT(*) AS total, SUM(`id_order` > 0) AS imported, SUM(`id_order` = 0) AS failed
             FROM `' . _DB_PREFIX_ . 'allmarket_order` WHERE `id_shop` = ' . $idShop
        );

        $lastRuns = [];
        foreach (AllmarketExchange::TASKS as $task) {
            $raw = Configuration::get('ALLMARKET_LAST_' . strtoupper($task));
            $lastRuns[$task] = $raw ? json_decode($raw, true) : null;
        }

        $this->context->smarty->assign([
            'am_action' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
            'am_cron_urls' => $cronUrls,
            'am_feed_full' => $link->getModuleLink($this->name, 'feed', ['token' => $feedToken, 'type' => 'full'], true, null, $idShop),
            'am_feed_offers' => $link->getModuleLink($this->name, 'feed', ['token' => $feedToken, 'type' => 'offers'], true, null, $idShop),
            'am_webhook_url' => $link->getModuleLink($this->name, 'webhook', [], true, null, $idShop),
            'am_webhook_secret' => Configuration::get('ALLMARKET_WEBHOOK_SECRET'),
            'am_cli' => 'php ' . _PS_MODULE_DIR_ . $this->name . '/cli.php',
            'am_offer_counts' => $offerCounts,
            'am_order_stats' => $orderStats,
            'am_last_runs' => $lastRuns,
            'am_logs' => AllmarketLog::latest(100),
            'am_mode' => AllmarketConfig::getMode(),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/tools.tpl');
    }
}

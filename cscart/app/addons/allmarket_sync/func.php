<?php
/***************************************************************************
 * allmarket.biz Sync — install, settings variants, hook handlers.         *
 ***************************************************************************/

use Tygh\Addons\AllmarketSync\Config;
use Tygh\Addons\AllmarketSync\Exchange;
use Tygh\Addons\AllmarketSync\Feed;
use Tygh\Addons\AllmarketSync\Log;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// The <autoload> of addon.xml is registered only after installation; install/uninstall need the classes too.
spl_autoload_register(function ($class) {
    $prefix = 'Tygh\\Addons\\AllmarketSync\\';
    if (strpos($class, $prefix) === 0) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

/* ---------------- install / uninstall ---------------- */

function fn_allmarket_sync_install()
{
    Config::ensureState();
}

function fn_allmarket_sync_uninstall()
{
    Config::dropState();
    fn_rm(Feed::dir());
}

/* ---------------- settings variants ---------------- */

function fn_settings_variants_addons_allmarket_sync_lang_code()
{
    $out = [];
    foreach (fn_get_translation_languages() as $code => $language) {
        $out[$code] = $language['name'];
    }

    return $out;
}

function fn_settings_variants_addons_allmarket_sync_currency()
{
    $out = [];
    foreach (fn_get_currencies_list() as $code => $currency) {
        $out[$code] = $code . ' — ' . $currency['description'];
    }

    return $out;
}

function fn_settings_variants_addons_allmarket_sync_status_new()
{
    return fn_get_simple_statuses(STATUSES_ORDER);
}

function fn_settings_variants_addons_allmarket_sync_status_paid()
{
    return fn_get_simple_statuses(STATUSES_ORDER);
}

function fn_settings_variants_addons_allmarket_sync_shipping_id()
{
    return ['0' => '—'] + fn_get_shippings(true);
}

function fn_settings_variants_addons_allmarket_sync_payment_id()
{
    $payments = db_get_hash_single_array(
        "SELECT p.payment_id, d.payment FROM ?:payments p
         LEFT JOIN ?:payment_descriptions d ON d.payment_id = p.payment_id AND d.lang_code = ?s
         WHERE p.status = 'A' ORDER BY p.position, d.payment",
        ['payment_id', 'payment'],
        DESCR_SL
    );

    return ['0' => '—'] + $payments;
}

/* ---------------- admin ---------------- */

/**
 * URLs for the allmarket.biz cabinet and cron.
 */
function fn_allmarket_sync_admin_urls()
{
    $cron = Config::token('cron_token');
    $feed = Config::token('feed_token');
    $root = rtrim(Registry::get('config.dir.root'), '/');
    $company = fn_allowed_for('ULTIMATE') ? ' --switch_company_id=' . Config::companyId() : '';

    return [
        'webhook' => Config::storefrontUrl('webhook'),
        'webhook_secret' => Config::token('webhook_secret'),
        'feed_full' => Config::storefrontUrl('feed', ['token' => $feed, 'type' => 'full']),
        'feed_offers' => Config::storefrontUrl('feed', ['token' => $feed, 'type' => 'offers']),
        'cron_offers' => Config::storefrontUrl('cron', ['token' => $cron, 'task' => 'all']),
        'cron_catalog' => Config::storefrontUrl('cron', ['token' => $cron, 'task' => 'catalog']),
        'cli_offers' => 'php ' . $root . '/admin.php --dispatch=allmarket_sync.cron --task=all' . $company,
        'cli_catalog' => 'php ' . $root . '/admin.php --dispatch=allmarket_sync.cron --task=catalog' . $company,
    ];
}

/* ---------------- hooks ---------------- */

/**
 * Hook "change_order_status": sends the mapped status of an allmarket.biz order back to the portal.
 */
function fn_allmarket_sync_change_order_status($status_to, $status_from, $order_info, $force_notification, $order_statuses, $place_order)
{
    if (Exchange::$applyingRemoteStatus || $status_to === $status_from || empty($order_info['order_id']) || !Config::usesApi()) {
        return;
    }
    try {
        (new Exchange())->pushOrderState((int) $order_info['order_id'], (string) $status_to);
    } catch (\Exception $e) {
        Log::warning('status', 'Order #' . (int) $order_info['order_id'] . ': ' . $e->getMessage());
    }
}

/**
 * Hook "create_shipment_post": resends the current status with the new tracking number.
 */
function fn_allmarket_sync_create_shipment_post($shipment_data, $order_info, $group_key, $all_products, $shipment_id)
{
    if (empty($shipment_data['tracking_number']) || empty($order_info['order_id']) || !Config::usesApi()) {
        return;
    }
    try {
        $orderId = (int) $order_info['order_id'];
        $status = (string) db_get_field('SELECT status FROM ?:orders WHERE order_id = ?i', $orderId);
        (new Exchange())->pushOrderState($orderId, $status, true);
    } catch (\Exception $e) {
        Log::warning('status', 'Order #' . (int) $order_info['order_id'] . ': ' . $e->getMessage());
    }
}

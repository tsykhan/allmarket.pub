<?php
/***************************************************************************
 * allmarket.biz Sync — admin.                                             *
 *   .manage     — connection, tasks, URLs, status mapping, orders, log    *
 *   .categories — placement of shop categories on allmarket.biz           *
 *   .cron       — console runner:                                         *
 *       php admin.php --dispatch=allmarket_sync.cron --task=offers        *
 ***************************************************************************/

use Tygh\Addons\AllmarketSync\ApiException;
use Tygh\Addons\AllmarketSync\Catalog;
use Tygh\Addons\AllmarketSync\Client;
use Tygh\Addons\AllmarketSync\Config;
use Tygh\Addons\AllmarketSync\Exchange;
use Tygh\Addons\AllmarketSync\Log;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($mode === 'run') {
        $task = isset($_REQUEST['task']) ? (string) $_REQUEST['task'] : '';
        $force = !empty($_REQUEST['force']);
        try {
            $stats = (new Exchange())->run($task, $force);
            fn_set_notification('N', __('notice'), __('allmarket_sync.task_done', [
                '[task]' => $task,
                '[stats]' => Exchange::formatStats($stats),
            ]));
        } catch (\Exception $e) {
            fn_set_notification('E', __('error'), $task . ': ' . $e->getMessage());
        }

        return [CONTROLLER_STATUS_OK, 'allmarket_sync.manage'];
    }

    if ($mode === 'ping') {
        try {
            $response = Client::fromConfig(20, 1)->ping();
            $shop = isset($response['shop']) ? (array) $response['shop'] : [];
            fn_set_notification('N', __('notice'), __('allmarket_sync.ping_ok', [
                '[name]' => isset($shop['name']) ? $shop['name'] : '',
                '[id]' => isset($shop['id']) ? $shop['id'] : '',
                '[currency]' => isset($shop['currency']) ? $shop['currency'] : '—',
            ]));
            if (!empty($shop['currency']) && strtoupper($shop['currency']) !== Config::currency()) {
                fn_set_notification('W', __('warning'), __('allmarket_sync.currency_mismatch', [
                    '[portal]' => strtoupper($shop['currency']),
                    '[shop]' => Config::currency(),
                ]));
            }
        } catch (ApiException $e) {
            fn_set_notification('E', __('error'), $e->getMessage());
        }

        return [CONTROLLER_STATUS_OK, 'allmarket_sync.manage'];
    }

    if ($mode === 'save_statuses') {
        Config::setStatusMap(isset($_REQUEST['status_map']) ? (array) $_REQUEST['status_map'] : []);
        fn_set_notification('N', __('notice'), __('text_changes_saved'));

        return [CONTROLLER_STATUS_OK, 'allmarket_sync.manage'];
    }

    if ($mode === 'retry_order' && !empty($_REQUEST['link_id'])) {
        db_query(
            'UPDATE ?:allmarket_sync_orders SET attempts = 0, error = NULL WHERE link_id = ?i AND company_id = ?i AND order_id = 0',
            $_REQUEST['link_id'],
            Config::companyId()
        );
        try {
            $stats = (new Exchange())->run(Exchange::TASK_ORDERS);
            fn_set_notification('N', __('notice'), Exchange::formatStats($stats));
        } catch (\Exception $e) {
            fn_set_notification('E', __('error'), $e->getMessage());
        }

        return [CONTROLLER_STATUS_OK, 'allmarket_sync.manage'];
    }

    if ($mode === 'clear_log') {
        Log::clear();

        return [CONTROLLER_STATUS_OK, 'allmarket_sync.manage'];
    }

    if ($mode === 'reset_secret') {
        $key = isset($_REQUEST['key']) && in_array($_REQUEST['key'], ['cron_token', 'feed_token', 'webhook_secret'], true) ? $_REQUEST['key'] : '';
        if ($key) {
            Config::setState($key, Config::randomToken($key === 'webhook_secret' ? 40 : 32));
            fn_set_notification('W', __('warning'), __('allmarket_sync.secret_reset'));
        }

        return [CONTROLLER_STATUS_OK, 'allmarket_sync.manage'];
    }

    if ($mode === 'save_categories') {
        if (isset($_REQUEST['am_raw'])) {
            // Text editor (portal tree unavailable).
            $map = Config::parseCategoryMap((string) $_REQUEST['am_raw']);
        } else {
            $map = [];
            $modes = isset($_REQUEST['am_mode']) ? (array) $_REQUEST['am_mode'] : [];
            foreach (isset($_REQUEST['am_cat']) ? (array) $_REQUEST['am_cat'] : [] as $categoryId => $portalId) {
                $portalId = preg_replace('/[^\w\-\.\/]/u', '', (string) $portalId);
                if ((int) $categoryId && $portalId !== '') {
                    $map[(int) $categoryId] = [
                        'id' => $portalId,
                        'mode' => isset($modes[$categoryId]) && $modes[$categoryId] === 'inside' ? 'inside' : 'map',
                    ];
                }
            }
        }
        Config::setCategoryMap($map);
        fn_set_notification('N', __('notice'), __('allmarket_sync.placement_saved', ['[count]' => count($map)]));

        if (!empty($_REQUEST['am_send_now']) && Config::usesApi()) {
            try {
                $stats = (new Exchange())->run(Exchange::TASK_CATALOG);
                fn_set_notification('N', __('notice'), __('allmarket_sync.task_done', [
                    '[task]' => Exchange::TASK_CATALOG,
                    '[stats]' => Exchange::formatStats($stats),
                ]));
            } catch (\Exception $e) {
                fn_set_notification('E', __('error'), $e->getMessage());
            }
        }

        return [CONTROLLER_STATUS_OK, 'allmarket_sync.categories'];
    }

    if ($mode === 'refresh_portal_cats') {
        try {
            $list = Config::portalCategories(0, true);
            fn_set_notification('N', __('notice'), __('allmarket_sync.portal_cats_loaded', ['[count]' => count((array) $list)]));
        } catch (ApiException $e) {
            fn_set_notification('E', __('error'), $e->getMessage());
        }

        return [CONTROLLER_STATUS_OK, 'allmarket_sync.categories'];
    }

    return [CONTROLLER_STATUS_OK, 'allmarket_sync.manage'];
}

if ($mode === 'cron') {
    // Console only; web cron goes through the storefront: index.php?dispatch=allmarket_sync.cron&token=…
    if (!defined('CONSOLE')) {
        return [CONTROLLER_STATUS_DENIED];
    }
    Log::$echo = !empty($_REQUEST['verbose']);
    $task = isset($_REQUEST['task']) ? (string) $_REQUEST['task'] : Exchange::TASK_ALL;
    try {
        $stats = (new Exchange())->run($task, !empty($_REQUEST['force']));
        fn_echo('allmarket.biz ' . $task . ': ' . Exchange::formatStats($stats) . PHP_EOL);
        exit(0);
    } catch (\Exception $e) {
        fn_echo('allmarket.biz ' . $task . ' failed: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

Registry::set('navigation.dynamic.sections', [
    'manage' => ['title' => __('allmarket_sync_manage'), 'href' => 'allmarket_sync.manage'],
    'categories' => ['title' => __('allmarket_sync_categories'), 'href' => 'allmarket_sync.categories'],
]);
Registry::set('navigation.dynamic.active_section', $mode);

if ($mode === 'manage') {
    Config::ensureState();
    $last = [];
    foreach (Exchange::TASKS as $task) {
        $last[$task] = Config::jsonState('last_' . $task, null);
    }
    $companyId = Config::companyId();

    Tygh::$app['view']->assign([
        'am_configured' => Client::fromConfig()->isConfigured(),
        'am_mode' => Config::mode(),
        'am_currency' => Config::currency(),
        'am_tasks' => Exchange::TASKS,
        'am_last' => $last,
        'am_urls' => fn_allmarket_sync_admin_urls(),
        'am_statuses' => fn_get_simple_statuses(STATUSES_ORDER),
        'am_status_map' => Config::statusMap(),
        'am_am_statuses' => Config::AM_STATUSES,
        'am_items' => db_get_hash_single_array(
            'SELECT status, COUNT(*) AS cnt FROM ?:allmarket_sync_items WHERE company_id = ?i GROUP BY status',
            ['status', 'cnt'],
            $companyId
        ),
        'am_orders' => db_get_array(
            'SELECT link_id, am_order_id, am_number, order_id, am_status, pushed_status, pending_status, acked, attempts, error, created
             FROM ?:allmarket_sync_orders WHERE company_id = ?i ORDER BY link_id DESC LIMIT 30',
            $companyId
        ),
        'am_log' => Log::latest(150, isset($_REQUEST['level']) ? (string) $_REQUEST['level'] : ''),
        'am_level' => isset($_REQUEST['level']) ? (string) $_REQUEST['level'] : '',
    ]);

} elseif ($mode === 'categories') {
    $catalog = new Catalog(Config::catalogOptions());
    $map = Config::categoryMap();

    $portal = null;
    $portalError = '';
    if (Config::usesApi() || Config::mode() === 'both') {
        try {
            $portal = Config::portalCategories();
        } catch (ApiException $e) {
            $portalError = $e->getMessage();
        }
    }
    $portalById = [];
    foreach ((array) $portal as $c) {
        $portalById[(string) $c['id']] = $c;
    }

    $rows = [];
    $resultPath = [];
    foreach ($catalog->categoryRows() as $row) {
        $id = $row['id'];
        $rule = isset($map[$id]) ? $map[$id] : null;
        // Where the category ends up on the portal, following the inheritance rules.
        if ($rule && $rule['mode'] === 'map') {
            $path = isset($portalById[$rule['id']]) ? $portalById[$rule['id']]['path'] : '#' . $rule['id'];
        } elseif ($rule) {
            $path = (isset($portalById[$rule['id']]) ? $portalById[$rule['id']]['path'] : '#' . $rule['id']) . ' > ' . $row['name'];
        } elseif ($row['parent_id'] && isset($resultPath[$row['parent_id']])) {
            // Missing subcategories are created inside the parent's portal category.
            $path = $resultPath[$row['parent_id']] . ' > ' . $row['name'];
        } else {
            $path = $row['name'];
        }
        $resultPath[$id] = $path;
        $rows[] = $row + [
            'portal' => $rule ? $rule['id'] : '',
            'mode' => $rule ? $rule['mode'] : 'map',
            'effective' => $path,
        ];
    }

    Tygh::$app['view']->assign([
        'am_rows' => $rows,
        'am_portal_ok' => is_array($portal) && $portal,
        'am_portal_error' => $portalError,
        'am_portal_json' => json_encode(array_values(array_map(function ($c) {
            return [
                'id' => (string) $c['id'],
                'name' => (string) $c['name'],
                'path' => isset($c['path']) ? (string) $c['path'] : (string) $c['name'],
                'depth' => isset($c['depth']) ? (int) $c['depth'] : 0,
            ];
        }, (array) $portal)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        'am_raw_map' => Config::categoryMapText($map),
        'am_feed_mode' => Config::mode() === 'feed',
    ]);
}

<?php
/***************************************************************************
 * allmarket.biz Sync — settings and persistent state.                     *
 *                                                                         *
 * Settings come from the add-on settings page (Registry). State that is   *
 * not a setting (tokens, placement rules, status map, locks, last runs)   *
 * lives in ?:storage_data under "allmarket_sync.<key>.<company_id>".      *
 ***************************************************************************/

namespace Tygh\Addons\AllmarketSync;

use Tygh\Registry;

class Config
{
    const VERSION = '1.0.0';

    const MODES = ['api', 'feed', 'both'];

    /** allmarket.biz order statuses (docs/API.md). */
    const AM_STATUSES = ['new', 'accepted', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'];

    public static function get($key, $default = '')
    {
        $value = Registry::get('addons.allmarket_sync.' . $key);

        return $value === null ? $default : $value;
    }

    public static function isOn($key)
    {
        return self::get($key) === 'Y';
    }

    /** Storefront owner in Ultimate, 0 elsewhere. */
    public static function companyId()
    {
        if (fn_allowed_for('ULTIMATE')) {
            $companyId = (int) Registry::get('runtime.company_id');

            return $companyId ?: (int) fn_get_default_company_id();
        }

        return 0;
    }

    /**
     * Ultimate: work as the storefront owner when the admin panel or console runs in the
     * "all storefronts" mode (orders, URLs and settings need a storefront).
     */
    public static function bindCompany()
    {
        if (fn_allowed_for('ULTIMATE') && !(int) Registry::get('runtime.company_id')) {
            $companyId = self::companyId();
            if ($companyId) {
                Registry::set('runtime.company_id', $companyId);
                Registry::set('runtime.company_data', fn_get_company_data($companyId));
            }
        }
    }

    /** Storefront of the exported company (Ultimate) or the default one. */
    public static function storefrontId()
    {
        try {
            /** @var \Tygh\Storefront\Repository $repository */
            $repository = \Tygh::$app['storefront.repository'];
            $storefront = fn_allowed_for('ULTIMATE') && self::companyId()
                ? $repository->findByCompanyId(self::companyId())
                : $repository->findDefault();

            return $storefront ? (int) $storefront->storefront_id : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /* ---------------- state ---------------- */

    protected static function stateKey($key)
    {
        return 'allmarket_sync.' . $key . '.' . self::companyId();
    }

    /** Reads straight from the DB (no Registry cache): locks are shared between processes. */
    public static function state($key, $default = '')
    {
        $value = db_get_field('SELECT data FROM ?:storage_data WHERE data_key = ?s', self::stateKey($key));

        return $value === null || $value === false ? $default : $value;
    }

    public static function setState($key, $value)
    {
        $value = (string) $value;
        if ($value === '') {
            db_query('DELETE FROM ?:storage_data WHERE data_key = ?s', self::stateKey($key));
        } else {
            db_replace_into('storage_data', ['data_key' => self::stateKey($key), 'data' => $value]);
        }
        Registry::del('storage_data.' . self::stateKey($key));
    }

    public static function jsonState($key, $default = [])
    {
        $value = json_decode((string) self::state($key), true);

        return is_array($value) ? $value : $default;
    }

    public static function setJsonState($key, $value)
    {
        self::setState($key, $value === null ? '' : json_encode($value, JSON_UNESCAPED_UNICODE));
    }

    /** Deletes the state of all storefronts (uninstall). */
    public static function dropState()
    {
        db_query('DELETE FROM ?:storage_data WHERE data_key LIKE ?l', 'allmarket_sync.%');
    }

    /* ---------------- tokens ---------------- */

    public static function randomToken($length = 32)
    {
        return substr(bin2hex(random_bytes($length)), 0, $length);
    }

    /** Creates missing tokens and the default status map. */
    public static function ensureState()
    {
        foreach (['cron_token' => 32, 'feed_token' => 32, 'webhook_secret' => 40] as $key => $length) {
            if (self::state($key) === '') {
                self::setState($key, self::randomToken($length));
            }
        }
        if (self::state('status_map') === '') {
            self::setJsonState('status_map', self::defaultStatusMap());
        }
    }

    public static function token($key)
    {
        $value = (string) self::state($key);
        if ($value === '') {
            self::ensureState();
            $value = (string) self::state($key);
        }

        return $value;
    }

    /* ---------------- modes ---------------- */

    public static function mode()
    {
        $mode = (string) self::get('mode', 'api');

        return in_array($mode, self::MODES, true) ? $mode : 'api';
    }

    public static function usesApi()
    {
        return self::mode() !== 'feed';
    }

    public static function usesFeed()
    {
        return self::mode() !== 'api';
    }

    /* ---------------- order statuses ---------------- */

    protected static function defaultStatusMap()
    {
        $map = [];
        $existing = fn_get_simple_statuses(STATUSES_ORDER);
        foreach (['P' => 'accepted', 'C' => 'delivered', 'I' => 'cancelled'] as $status => $amStatus) {
            if (isset($existing[$status])) {
                $map[$status] = $amStatus;
            }
        }

        return $map;
    }

    /** @return array<string, string> CS-Cart status => allmarket.biz status */
    public static function statusMap()
    {
        $out = [];
        foreach (self::jsonState('status_map') as $status => $amStatus) {
            if (in_array($amStatus, self::AM_STATUSES, true)) {
                $out[(string) $status] = (string) $amStatus;
            }
        }

        return $out;
    }

    public static function setStatusMap(array $map)
    {
        $clean = [];
        foreach ($map as $status => $amStatus) {
            if (preg_match('/^[A-Z]$/', (string) $status) && in_array($amStatus, self::AM_STATUSES, true)) {
                $clean[$status] = $amStatus;
            }
        }
        self::setJsonState('status_map', $clean);
    }

    /** $force_notification for fn_change_order_status(): customer / admin department / vendor. */
    public static function notificationRules()
    {
        return ['C' => self::isOn('notify_customer'), 'A' => self::isOn('notify_admin'), 'V' => false];
    }

    /* ---------------- category placement ---------------- */

    /**
     * Category placement rules, one per line:
     *   "shop_category_id=allmarket_category_id" — the shop category IS that portal category (subcategories inherit);
     *   "shop_category_id>allmarket_category_id" — the shop category is created INSIDE that portal category.
     *
     * @return array<int, array{id: string, mode: string}> mode: map | inside
     */
    public static function categoryMap()
    {
        return self::parseCategoryMap((string) self::state('category_map'));
    }

    public static function parseCategoryMap($text)
    {
        $map = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^(\d+)\s*(=>|=|;|:|\t|>)\s*([\w\-\.\/]+)/u', $line, $m)) {
                $map[(int) $m[1]] = ['id' => $m[3], 'mode' => $m[2] === '>' ? 'inside' : 'map'];
            }
        }

        return $map;
    }

    public static function categoryMapText(array $map)
    {
        ksort($map);
        $lines = [];
        foreach ($map as $categoryId => $rule) {
            if (!empty($rule['id'])) {
                $lines[] = (int) $categoryId . ($rule['mode'] === 'inside' ? '>' : '=') . preg_replace('/[^\w\-\.\/]/u', '', (string) $rule['id']);
            }
        }

        return implode("\n", $lines);
    }

    public static function setCategoryMap(array $map)
    {
        self::setState('category_map', self::categoryMapText($map));
    }

    /** Cached portal category tree (GET /portal-categories), refreshed when older than $maxAge seconds. */
    public static function portalCategories($maxAge = 21600, $refresh = false)
    {
        $cached = self::jsonState('portal_cats', null);
        $fresh = is_array($cached) && isset($cached['t'], $cached['categories']) && TIME - (int) $cached['t'] < $maxAge;
        if ($fresh && !$refresh) {
            return $cached['categories'];
        }
        $client = Client::fromConfig(20, 1);
        if (!$client->isConfigured()) {
            return is_array($cached) && isset($cached['categories']) ? $cached['categories'] : null;
        }
        $response = $client->getPortalCategories();
        $list = isset($response['categories']) && is_array($response['categories']) ? $response['categories'] : [];
        self::setJsonState('portal_cats', ['t' => TIME, 'categories' => $list]);

        return $list;
    }

    /* ---------------- catalog ---------------- */

    /** @return int[] export only these categories (and their subcategories); empty = all */
    public static function exportCategories()
    {
        $ids = preg_split('/[^0-9]+/', (string) self::get('categories'), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    public static function langCode()
    {
        $lang = (string) self::get('lang_code');
        $languages = fn_get_translation_languages();

        return isset($languages[$lang]) ? $lang : (string) Registry::get('settings.Appearance.frontend_default_language');
    }

    /** Export currency: must exist in the store; falls back to the primary currency. */
    public static function currency()
    {
        $code = strtoupper((string) self::get('currency'));
        $currencies = Registry::get('currencies') ?: [];

        return isset($currencies[$code]) ? $code : CART_PRIMARY_CURRENCY;
    }

    public static function catalogOptions()
    {
        return [
            'company_id' => self::companyId(),
            'lang_code' => self::langCode(),
            'currency' => self::currency(),
            'categories' => self::exportCategories(),
            'variations' => self::isOn('variations'),
            'only_in_stock' => self::isOn('only_in_stock'),
            'markup' => (float) str_replace(',', '.', (string) self::get('price_markup', '0')),
            'category_map' => self::categoryMap(),
        ];
    }

    public static function batchSize()
    {
        $size = (int) self::get('batch_size', 100);

        return $size > 0 ? min($size, 1000) : 100;
    }

    /* ---------------- URLs for the allmarket.biz cabinet / cron ---------------- */

    /** Storefront protocol for links built in the admin panel, console or cron. */
    public static function protocol()
    {
        return Registry::get('settings.Security.secure_storefront') === 'Y' ? 'https' : 'http';
    }

    public static function storefrontUrl($dispatch, array $params = [])
    {
        $query = $params ? '?' . http_build_query($params) : '';

        return fn_url('allmarket_sync.' . $dispatch . $query, 'C', self::protocol());
    }
}

<?php
/**
 * Settings of the allmarket.biz exchange module.
 *
 * @author    allmarket.biz
 * @copyright 2026 allmarket.biz
 * @license   Commercial
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AllmarketConfig
{
    const MODES = ['api', 'feed', 'both'];

    /** Keys shown on the settings form, with install defaults. */
    public static function defaults()
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $ukLang = (int) Language::getIdByIso('uk');
        $uah = (int) Currency::getIdByIsoCode('UAH');
        $uaCountry = (int) Country::getByIso('UA');

        return [
            'ALLMARKET_MODE' => 'api',
            'ALLMARKET_API_URL' => 'https://allmarket.biz/api/v1',
            'ALLMARKET_API_KEY' => '',
            'ALLMARKET_SHOP_ID' => '',
            'ALLMARKET_ID_LANG' => $ukLang ?: $idLang,
            'ALLMARKET_ID_CURRENCY' => $uah ?: (int) Configuration::get('PS_CURRENCY_DEFAULT'),
            'ALLMARKET_CATEGORIES' => '[]',
            'ALLMARKET_COMBINATIONS' => 1,
            'ALLMARKET_ONLY_IN_STOCK' => 0,
            'ALLMARKET_IMAGE_TYPE' => '',
            'ALLMARKET_PRICE_MARKUP' => 0,
            'ALLMARKET_BATCH_SIZE' => 100,
            'ALLMARKET_CATEGORY_MAP' => '',
            'ALLMARKET_PORTAL_CATS' => '',
            'ALLMARKET_ORDERS_ENABLED' => 1,
            'ALLMARKET_OS_PAID' => (int) Configuration::get('PS_OS_PAYMENT'),
            'ALLMARKET_CARRIER_REF' => 0,
            'ALLMARKET_ID_COUNTRY' => $uaCountry ?: (int) Configuration::get('PS_COUNTRY_DEFAULT'),
            'ALLMARKET_USE_AM_PRICES' => 1,
            'ALLMARKET_USE_AM_SHIPPING' => 1,
        ];
    }

    /** Keys not on the form (state, tokens). */
    const INTERNAL_KEYS = [
        'ALLMARKET_OS_NEW',
        'ALLMARKET_STATUS_MAP',
        'ALLMARKET_CRON_TOKEN',
        'ALLMARKET_FEED_TOKEN',
        'ALLMARKET_WEBHOOK_SECRET',
        'ALLMARKET_ORDERS_SINCE',
        'ALLMARKET_CATEGORIES_HASH',
        'ALLMARKET_LOCK_CATALOG',
        'ALLMARKET_LOCK_OFFERS',
        'ALLMARKET_LOCK_ORDERS',
        'ALLMARKET_LOCK_STATUSES',
        'ALLMARKET_LOCK_FEED',
        'ALLMARKET_LAST_CATALOG',
        'ALLMARKET_LAST_OFFERS',
        'ALLMARKET_LAST_ORDERS',
        'ALLMARKET_LAST_STATUSES',
        'ALLMARKET_LAST_FEED',
    ];

    public static function installDefaults()
    {
        $ok = true;
        foreach (self::defaults() as $key => $value) {
            $ok = $ok && Configuration::updateValue($key, $value);
        }
        $ok = $ok && Configuration::updateValue('ALLMARKET_STATUS_MAP', json_encode(self::defaultStatusMap()));
        $ok = $ok && Configuration::updateValue('ALLMARKET_CRON_TOKEN', self::randomToken());
        $ok = $ok && Configuration::updateValue('ALLMARKET_FEED_TOKEN', self::randomToken());
        $ok = $ok && Configuration::updateValue('ALLMARKET_WEBHOOK_SECRET', self::randomToken(40));

        return (bool) $ok;
    }

    public static function uninstall()
    {
        foreach (array_merge(array_keys(self::defaults()), self::INTERNAL_KEYS) as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    protected static function defaultStatusMap()
    {
        $map = [];
        $pairs = [
            'PS_OS_PAYMENT' => 'accepted',
            'PS_OS_PREPARATION' => 'processing',
            'PS_OS_SHIPPING' => 'shipped',
            'PS_OS_DELIVERED' => 'delivered',
            'PS_OS_CANCELED' => 'cancelled',
            'PS_OS_REFUND' => 'returned',
        ];
        foreach ($pairs as $key => $status) {
            $id = (int) Configuration::get($key);
            if ($id) {
                $map[$id] = $status;
            }
        }

        return $map;
    }

    public static function randomToken($length = 32)
    {
        if (function_exists('random_bytes')) {
            $bytes = random_bytes($length);
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes($length);
        } else {
            $bytes = md5(uniqid(mt_rand(), true) . _COOKIE_KEY_, true) . md5(uniqid(mt_rand(), true), true);
        }

        return Tools::substr(bin2hex($bytes) . md5(_COOKIE_KEY_ . microtime()), 0, $length);
    }

    public static function getFormValues()
    {
        $values = [];
        foreach (array_keys(self::defaults()) as $key) {
            $values[$key] = Configuration::get($key);
        }
        $values['ALLMARKET_OS_NEW'] = (int) Configuration::get('ALLMARKET_OS_NEW');
        $values['ALLMARKET_CATEGORIES'] = self::getCategories();

        return $values;
    }

    public static function getMode()
    {
        $mode = (string) Configuration::get('ALLMARKET_MODE');

        return in_array($mode, self::MODES, true) ? $mode : 'api';
    }

    public static function usesApi()
    {
        return self::getMode() !== 'feed';
    }

    public static function usesFeed()
    {
        return self::getMode() !== 'api';
    }

    /** @return int[] */
    public static function getCategories()
    {
        $ids = json_decode((string) Configuration::get('ALLMARKET_CATEGORIES'), true);

        return is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
    }

    /** @return array<int, string> id_order_state => allmarket status */
    public static function getStatusMap()
    {
        $map = json_decode((string) Configuration::get('ALLMARKET_STATUS_MAP'), true);
        if (!is_array($map)) {
            return [];
        }
        $out = [];
        foreach ($map as $idState => $status) {
            $out[(int) $idState] = (string) $status;
        }

        return $out;
    }

    /**
     * Category placement rules, one per line:
     *   "shop_category_id=allmarket_category_id"  — the shop category IS that portal category (subcategories inherit);
     *   "shop_category_id>allmarket_category_id"  — the shop category is created INSIDE that portal category.
     *
     * @return array<int, array{id: string, mode: string}> mode: map | inside
     */
    public static function getCategoryMap()
    {
        $map = [];
        $lines = preg_split('/\r\n|\r|\n/', (string) Configuration::get('ALLMARKET_CATEGORY_MAP'));
        foreach ($lines as $line) {
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

    /** Inverse of getCategoryMap(). */
    public static function setCategoryMap(array $map)
    {
        ksort($map);
        $lines = [];
        foreach ($map as $idCategory => $rule) {
            if (!empty($rule['id'])) {
                $lines[] = (int) $idCategory . ($rule['mode'] === 'inside' ? '>' : '=') . preg_replace('/[^\w\-\.\/]/u', '', (string) $rule['id']);
            }
        }
        Configuration::updateValue('ALLMARKET_CATEGORY_MAP', implode("\n", $lines));
    }

    /** Cached portal category tree (GET /portal-categories), refreshed when older than $maxAge seconds. */
    public static function getPortalCategories($maxAge = 21600, $refresh = false)
    {
        $cached = json_decode((string) Configuration::get('ALLMARKET_PORTAL_CATS'), true);
        $fresh = is_array($cached) && isset($cached['t'], $cached['categories']) && time() - (int) $cached['t'] < $maxAge;
        if ($fresh && !$refresh) {
            return $cached['categories'];
        }
        $client = AllmarketClient::fromConfig(20, 1);
        if (!$client->isConfigured()) {
            return is_array($cached) && isset($cached['categories']) ? $cached['categories'] : null;
        }
        $response = $client->getPortalCategories();
        $list = isset($response['categories']) && is_array($response['categories']) ? $response['categories'] : [];
        Configuration::updateValue('ALLMARKET_PORTAL_CATS', json_encode(['t' => time(), 'categories' => $list]));

        return $list;
    }

    public static function getCatalogOptions()
    {
        return [
            'id_lang' => (int) Configuration::get('ALLMARKET_ID_LANG') ?: (int) Configuration::get('PS_LANG_DEFAULT'),
            'id_currency' => (int) Configuration::get('ALLMARKET_ID_CURRENCY') ?: (int) Configuration::get('PS_CURRENCY_DEFAULT'),
            'categories' => self::getCategories(),
            'combinations' => (bool) Configuration::get('ALLMARKET_COMBINATIONS'),
            'only_in_stock' => (bool) Configuration::get('ALLMARKET_ONLY_IN_STOCK'),
            'image_type' => (string) Configuration::get('ALLMARKET_IMAGE_TYPE'),
            'markup' => (float) Configuration::get('ALLMARKET_PRICE_MARKUP'),
            'category_map' => self::getCategoryMap(),
        ];
    }

    public static function getBatchSize()
    {
        $size = (int) Configuration::get('ALLMARKET_BATCH_SIZE');

        return $size > 0 ? min($size, 1000) : 100;
    }
}

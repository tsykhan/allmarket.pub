<?php
/**
 * Product attributes from the shop → portal features («Характеристики», module/multitrade/features.api.php).
 *
 * The API runs without the VuCMS core, so only what features.api.php needs is booted: the core's
 * db_mysqli + DB facade, VCM_ROOT/VCM_PRE, $CFG['language'], $VCM_LANGS and content_langs().
 *
 * all_mtf_xref has no seller column, so PrestaShop ids are namespaced per seller:
 *   feature  u<userid>:f<id_feature>          value  u<userid>:fv<id_feature_value>
 *   variant  u<userid>:a<id_attribute_group>  value  u<userid>:av<id_attribute>
 * Without ids (older module versions) features/values are matched by name, as the cabinet form does.
 * New features/values are owned by the seller and follow the portal's settings (seller_own, moderation).
 */
class AmFeatures
{
    const ENGINE = 'prestashop';
    private static $ready;

    /** True when the portal has the features module, it is switched on, and the API is allowed to use it. */
    public static function ready(array $cfg)
    {
        if (self::$ready !== null) {
            return self::$ready;
        }
        self::$ready = false;
        $root = $cfg['portal_root'];
        if (empty($cfg['features']) || !is_file($root . '/module/multitrade/features.api.php')) {
            return false;
        }
        if (!function_exists('mtf_api_product_set')) {
            self::boot($cfg);
            require_once $root . '/module/multitrade/features.api.php';
        }
        self::$ready = mtf_on();
        return self::$ready;
    }

    private static function boot(array $cfg)
    {
        $root = $cfg['portal_root'];
        if (!defined('VCM_ROOT')) {
            define('VCM_ROOT', $root);
        }
        foreach (array('VCM_DEBUG' => 0, 'IN_ADMIN' => false) as $k => $v) {
            if (!defined($k)) {
                define($k, $v);
            }
        }
        if (!class_exists('DB', false)) {
            $c = AmDb::portalConfig($root, $cfg['db']);
            require_once $root . '/system/db_mysqli.class.php';
            $db = new db_mysqli();
            $db->halt = 0;
            $db->pre = $c['prefix'];
            $db->connect($c['host'], $c['user'], $c['pass'], $c['name'], 0, $c['charset'], 0);
            // Assigns DB::$db = $db from this scope.
            require $root . '/system/db.class.php';
        }
        if (!defined('VCM_PRE')) {
            define('VCM_PRE', DB::$db->pre);
        }
        if (empty($GLOBALS['CFG']['language'])) {
            $GLOBALS['CFG']['language'] = $cfg['content_lang'];
        }
        if (empty($GLOBALS['VCM_LANGS'])) {
            $langs = is_file($root . '/file/cache/langs.php') ? include $root . '/file/cache/langs.php' : null;
            $GLOBALS['VCM_LANGS'] = is_array($langs) && $langs ? $langs : array($cfg['content_lang'] => $cfg['content_lang']);
        }
        if (!function_exists('content_langs')) {
            require __DIR__ . '/am_content_langs.php';
        }
    }

    /**
     * Replaces the item's features with the shop's attributes.
     * @return int|null number of values written, null when features are not available (caller keeps the HTML table)
     */
    public static function save(array $cfg, $itemid, $userid, array $attrs)
    {
        if (!self::ready($cfg)) {
            return null;
        }
        $lang = $cfg['content_lang'];
        $mcfg = mtf_cfg();
        $create = !empty($mcfg['seller_own']);
        $uid = (int) $userid;

        // One entry per feature (PrestaShop allows several values of one feature on a product).
        $groups = array();
        foreach ($attrs as $a) {
            if (!is_array($a) || !isset($a['name'], $a['value'])) {
                continue;
            }
            $name = self::txt($a['name'], 250);
            $value = self::txt($a['value'], 900);
            if ($name === '' || $value === '') {
                continue;
            }
            $kind = isset($a['type']) && $a['type'] === 'variant' ? 'a' : 'f';
            $id = isset($a['id']) ? (int) $a['id'] : 0;
            $key = $id ? $kind . $id : 'n:' . mb_strtolower($name, 'UTF-8');
            if (!isset($groups[$key])) {
                $groups[$key] = array('spec' => array('names' => array($lang => $name)), 'values' => array(), 'custom' => array(), 'texts' => array());
                if ($id) {
                    $groups[$key]['spec']['ext'] = 'u' . $uid . ':' . $kind . $id;
                }
            }
            $g = &$groups[$key];
            $g['texts'][] = $value;
            $vid = isset($a['value_id']) ? (int) $a['value_id'] : 0;
            if (!empty($a['custom'])) {
                $g['custom'][] = $value;
            } else {
                $v = array('names' => array($lang => $value));
                if ($vid) {
                    $v['ext'] = 'u' . $uid . ':' . $kind . 'v' . $vid;
                }
                $g['values'][] = $v;
            }
            unset($g);
        }

        $items = array();
        foreach ($groups as $g) {
            $spec = $g['spec'];
            $spec['type'] = count($g['texts']) > 1 ? 'multiselect' : 'select';
            $fid = mtf_api_feature_ensure(self::ENGINE, $spec, $uid, $create, false);
            if (!$fid) {
                continue;
            }
            $f = DB::get_one('SELECT type FROM ' . VCM_PRE . 'mtf_feature WHERE id=' . (int) $fid);
            $type = $f ? $f['type'] : 'text';
            $first = $g['texts'][0];
            $item = array('feature' => $spec);
            if ($type === 'select' || $type === 'multiselect') {
                $item['values'] = $g['values'];
                if ($g['custom']) {
                    $item['custom'] = array($lang => implode(', ', $g['custom']));
                }
            } elseif ($type === 'number') {
                $x = str_replace(array(' ', ','), array('', '.'), $first);
                $item['num'] = preg_match('/-?\d+(?:\.\d+)?/', $x, $m) ? $m[0] : null;
            } elseif ($type === 'date') {
                $ts = strtotime($first . ' 12:00:00');
                $item['num'] = $ts ?: null;
            } elseif ($type === 'bool') {
                $w = mb_strtolower($first, 'UTF-8');
                $item['bool'] = in_array($w, array('1', '+', 'yes', 'true', 'так', 'да'), true) ? 1
                    : (in_array($w, array('0', '-', 'no', 'false', 'ні', 'нет'), true) ? 0 : null);
            } else {
                $item['text'] = array($lang => implode(', ', $g['texts']));
            }
            $items[] = $item;
        }

        // The card from the shop is the source of truth: values removed in the shop disappear here too.
        mtf_product_delete($itemid);
        return $items ? (int) mtf_api_product_set($itemid, $items, self::ENGINE, $uid, $create, false) : 0;
    }

    private static function txt($s, $max)
    {
        $s = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(AmText::bmp($s)), ENT_QUOTES, 'UTF-8')));
        return mb_substr($s, 0, $max, 'UTF-8');
    }
}

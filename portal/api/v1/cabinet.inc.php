<?php
/**
 * Seller cabinet page "API для інтернет-магазину": API key, default category, webhook.
 * Shown in the cabinet's «Інтеграції» hub, tab «Інтернет-магазин» (integrations.php?kind=shop&action=shopapi,
 * added by install/integrations_patch.php) after the VuCMS core has authenticated the member.
 *
 * @param int    $userid   logged-in member ($_userid)
 * @param string $username ($_username)
 * @param string $secret   per-installation secret for the CSRF token (VCM_KEY)
 * @return array view model for cabinet.view.php
 */
require_once __DIR__ . '/bootstrap.php';

function amapi_cabinet($userid, $username, $secret)
{
    $cfg = amapi_config();
    $db = amapi_db();
    $userid = (int) $userid;
    $token = hash_hmac('sha256', 'amapi-cabinet|' . $userid, (string) $secret);
    $vm = array('title' => 'API для інтернет-магазину', 'token' => $token, 'new_key' => '', 'notice' => '', 'error' => '');

    $member = AmAuth::member($db, $userid);
    $denied = !$member || in_array((int) $member['groupid'], $cfg['denied_groups'], true);

    $post = amapi_cabinet_post();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($post['amapi_action'])) {
        if (!isset($post['amapi_token']) || !hash_equals($token, (string) $post['amapi_token'])) {
            $vm['error'] = 'Сесія застаріла, оновіть сторінку і спробуйте ще раз.';
        } elseif ($denied) {
            $vm['error'] = 'Ваша група користувачів не має доступу до API.';
        } else {
            $action = (string) $post['amapi_action'];
            if ($action === 'key') {
                $vm['new_key'] = AmAuth::issue($db, $userid, $username);
                $vm['notice'] = 'Новий ключ створено. Скопіюйте його зараз — повністю він більше не показується. Старий ключ більше не діє.';
            } elseif ($action === 'save' && $db->value('SELECT userid FROM #__amapi_seller WHERE userid=?', array($userid))) {
                $url = trim(isset($post['webhook_url']) ? (string) $post['webhook_url'] : '');
                $sec = trim(isset($post['webhook_secret']) ? (string) $post['webhook_secret'] : '');
                $catid = isset($post['default_catid']) ? (int) $post['default_catid'] : 0;
                if ($catid && !$db->value('SELECT catid FROM #__category WHERE catid=? AND moduleid=?', array($catid, (int) $cfg['module_id']))) {
                    $catid = 0;
                }
                if ($url !== '' && (strlen($url) > 255 || !AmWebhook::validUrl($url))) {
                    $vm['error'] = 'Адреса вебхука має бути публічною http(s)-адресою вашого магазину.';
                } else {
                    $fields = array('webhook_url' => $url, 'default_catid' => $catid, 'enabled' => empty($post['enabled']) ? 0 : 1);
                    // An empty field keeps the stored secret (it is not printed back into the form).
                    if ($sec !== '') {
                        $fields['webhook_secret'] = mb_substr($sec, 0, 128, 'UTF-8');
                    }
                    $db->updateRow('amapi_seller', $fields, 'userid=?', array($userid));
                    $vm['notice'] = 'Налаштування збережено.';
                }
            } elseif ($action === 'test') {
                $s = $db->one('SELECT webhook_url, webhook_secret FROM #__amapi_seller WHERE userid=?', array($userid));
                if (!$s || $s['webhook_url'] === '') {
                    $vm['error'] = 'Спершу вкажіть адресу вебхука.';
                } elseif (!AmWebhook::validUrl($s['webhook_url'])) {
                    $vm['error'] = 'Адреса вебхука недоступна.';
                } else {
                    list($code, $data, $err) = AmWebhook::send($s['webhook_url'], $s['webhook_secret'], array('event' => 'ping'), (int) $cfg['webhook_timeout']);
                    $status = $err ?: ('OK' . (is_array($data) && isset($data['module']) ? ', модуль ' . $data['module'] : ''));
                    $db->exec('UPDATE #__amapi_seller SET webhook_status=? WHERE userid=?', array(mb_substr(date('Y-m-d H:i') . ' ping: ' . $status, 0, 255, 'UTF-8'), $userid));
                    if ($err) {
                        $vm['error'] = 'Магазин не відповів на перевірку: ' . $err;
                    } else {
                        $vm['notice'] = 'Магазин відповів: ' . $status;
                    }
                }
            }
        }
    }

    $s = $db->one('SELECT * FROM #__amapi_seller WHERE userid=?', array($userid));
    $vm['denied'] = $denied;
    $vm['seller'] = $s;
    $vm['shop_id'] = (string) $userid;
    $vm['api_url'] = amapi_cabinet_api_url();
    $vm['categories'] = amapi_cabinet_categories($db, (int) $cfg['module_id']);
    $vm['stats'] = array('items' => 0, 'items_deleted' => 0, 'orders' => 0, 'orders_acked' => 0);
    $vm['log'] = array();
    if ($s) {
        $vm['stats']['items'] = (int) $db->value('SELECT COUNT(*) FROM #__amapi_item WHERE userid=? AND deleted=0', array($userid));
        $vm['stats']['items_deleted'] = (int) $db->value('SELECT COUNT(*) FROM #__amapi_item WHERE userid=? AND deleted=1', array($userid));
        $vm['stats']['orders'] = (int) $db->value('SELECT COUNT(*) FROM #__multitrade_order WHERE seller=? AND addtime>=?', array($username, (int) $s['orders_from']));
        $vm['stats']['orders_acked'] = (int) $db->value('SELECT COUNT(*) FROM #__amapi_order WHERE userid=? AND acked=1', array($userid));
        $vm['log'] = $db->all('SELECT * FROM #__amapi_log WHERE userid=? ORDER BY id DESC LIMIT 30', array($userid));
    }
    return $vm;
}

/** Card on the hub's «Інтернет-магазин» tab: label and button depend on whether a key exists. */
function amapi_cabinet_card($userid)
{
    $s = amapi_db()->one('SELECT key_hash, enabled, last_used FROM #__amapi_seller WHERE userid=?', array((int) $userid));
    $on = $s && $s['key_hash'] !== '';
    return array(
        'tag' => $on ? ($s['enabled'] ? 'Підключено' : 'Вимкнено') : 'Seller API',
        'name' => 'PrestaShop та інші платформи',
        'intro' => $on && $s['last_used']
            ? 'Магазин востаннє звертався ' . date('d.m.Y H:i', (int) $s['last_used']) . '. Каталог, ціни, залишки й замовлення синхронізуються через API-ключ.'
            : 'Вивантаження каталогу, синхронізація цін і залишків, замовлення й статуси з вашим інтернет-магазином (модуль allmarketsync для PrestaShop) через API-ключ.',
        'button' => $on ? 'Налаштувати' : 'Підключити',
    );
}

/**
 * Form fields from the raw body: the VuCMS core escapes/filters $_POST (addslashes, SQL keyword
 * stripping) before this code runs, which would corrupt URLs and secrets.
 */
function amapi_cabinet_post()
{
    $post = array();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        parse_str((string) file_get_contents('php://input'), $post);
    }
    return $post;
}

function amapi_cabinet_api_url()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'allmarket.biz';
    return ($https ? 'https' : 'http') . '://' . $host . '/api/v1';
}

/** Multitrade categories as a flat indented list for a <select>. */
function amapi_cabinet_categories(AmDb $db, $moduleid)
{
    $rows = $db->all('SELECT catid, catname, parentid FROM #__category WHERE moduleid=? ORDER BY listorder, catid', array($moduleid));
    $children = array();
    foreach ($rows as $r) {
        $children[(int) $r['parentid']][] = $r;
    }
    $out = array();
    $walk = function ($parent, $depth) use (&$walk, &$out, $children) {
        if (empty($children[$parent]) || $depth > 10) {
            return;
        }
        foreach ($children[$parent] as $r) {
            $out[] = array('catid' => (int) $r['catid'], 'name' => str_repeat('— ', $depth) . AmText::unescape($r['catname']));
            $walk((int) $r['catid'], $depth + 1);
        }
    };
    $walk(0, 0);
    // Orphans (parent outside the module) at the end.
    $seen = array();
    foreach ($out as $c) {
        $seen[$c['catid']] = true;
    }
    foreach ($rows as $r) {
        if (!isset($seen[(int) $r['catid']])) {
            $out[] = array('catid' => (int) $r['catid'], 'name' => AmText::unescape($r['catname']));
        }
    }
    return $out;
}

function amapi_h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

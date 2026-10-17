<?php
/**
 * Adds the "Інтернет-магазин" tab with the Seller API page to the cabinet's «Інтеграції» hub
 * (module/member/integrations.inc.php + template/b2b-b2c/member/integrations.htm).
 *
 *   /opt/plesk/php/8.4/bin/php install/integrations_patch.php /var/www/vhosts/allmarket.biz/httpdocs          # dry run
 *   /opt/plesk/php/8.4/bin/php install/integrations_patch.php /var/www/vhosts/allmarket.biz/httpdocs --apply --backup-dir=/var/www/vhosts/allmarket.biz/amapi_backup
 *
 * Every insertion is anchored to text that must occur exactly once; nothing is written unless all
 * anchors match. Originals are kept as <name>.amapi-bak-<date> (next to the file, or in --backup-dir,
 * which should be outside the webroot). Running it again changes nothing.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
$root = isset($argv[1]) ? rtrim($argv[1], '/') : '';
$apply = in_array('--apply', $argv, true);
$template = 'b2b-b2c';
$backupDir = '';
foreach ($argv as $a) {
    if (strpos($a, '--template=') === 0) {
        $template = substr($a, 11);
    }
    if (strpos($a, '--backup-dir=') === 0) {
        $backupDir = rtrim(substr($a, 13), '/');
    }
}
if ($root === '' || !is_file($root . '/module/member/integrations.inc.php')) {
    fwrite(STDERR, "usage: php integrations_patch.php <httpdocs> [--apply] [--template=b2b-b2c]\n");
    exit(2);
}
const MARK = 'amapi-shop';

$patches = array(
    $root . '/module/member/integrations.inc.php' => array(
        // 1. Tab in $INT_KINDS (after the AI tab).
        array(
            'after' => "    \"ai\" => array(\"title\" => \"AI\", \"one\" => \"AI\", \"key_label\" => \$L[\"am_int_kind_key\"], \"icon\" => \"fa-magic\"),\n",
            'text' => "    // " . MARK . ": Seller API для інтернет-магазинів (api/v1, модуль PrestaShop allmarketsync)\n"
                . "    \"shop\" => array(\"title\" => am_lang_pick(array(\"ua-ua\" => \"Інтернет-магазин\", \"ru-ru\" => \"Интернет-магазин\", \"en-en\" => \"Online store\")), \"one\" => am_lang_pick(array(\"ua-ua\" => \"Інтернет-магазин\", \"ru-ru\" => \"Интернет-магазин\", \"en-en\" => \"Online store\")), \"key_label\" => \$L[\"am_int_kind_key\"], \"icon\" => \"fa-shopping-cart\"),\n",
        ),
        // 2. The page itself (before the generic "toggle" action).
        array(
            'before' => "    case \"toggle\":\n",
            'text' => "    case \"shopapi\": // " . MARK . "\n"
                . "        \$kind = \"shop\";\n"
                . "        require_once VCM_ROOT . \"/api/v1/cabinet.inc.php\";\n"
                . "        \$AM = amapi_cabinet(\$_userid, \$_username, VCM_KEY);\n"
                . "        \$head_title = \$AM[\"title\"];\n"
                . "        break;\n",
        ),
    ),
    $root . '/template/' . $template . '/member/integrations.htm' => array(
        // 3. Page section, before the hub's list view.
        array(
            'before' => "{else}\n<div class=\"am-ordhead\">\n  <h1 class=\"am-ordhead__t\">{\$L['am_member_integrations_84']}</h1>",
            'text' => "{elseif \$action == 'shopapi'}{php /* " . MARK . " */}\n"
                . "<div class=\"am-ordhead\">\n"
                . "  <h1 class=\"am-ordhead__t\">{\$AM[title]}</h1>\n"
                . "  <a class=\"btn btn-default int-back\" href=\"{\$int_base}?kind=shop\">{\$L['am_member_integrations_2']}</a>\n"
                . "</div>\n"
                . "{php include VCM_ROOT . '/api/v1/cabinet.view.php';}\n",
        ),
        // 4. Card on the "Інтернет-магазин" tab.
        array(
            'before' => "{if \$kind == 'logistics' && !\$np_connected}\n",
            'text' => "{if \$kind == 'shop'}{php /* " . MARK . " */ require_once VCM_ROOT . '/api/v1/cabinet.inc.php'; \$amapi_card = amapi_cabinet_card(\$_userid);}\n"
                . "<div class=\"int-preset\">\n"
                . "  <div class=\"int-preset__body\">\n"
                . "    <span class=\"int-preset__tag\">{\$amapi_card[tag]}</span>\n"
                . "    <div class=\"int-card__name\">{\$amapi_card[name]}</div>\n"
                . "    <p>{\$amapi_card[intro]}</p>\n"
                . "  </div>\n"
                . "  <a class=\"btn btn-primary btn-lg\" href=\"{\$int_base}?kind=shop&action=shopapi\">{\$amapi_card[button]}</a>\n"
                . "</div>\n"
                . "{/if}\n",
        ),
    ),
);

$plan = array();
$ok = true;
foreach ($patches as $file => $list) {
    if (!is_file($file)) {
        echo "MISSING  $file\n";
        $ok = false;
        continue;
    }
    $src = file_get_contents($file);
    if (strpos($src, MARK) !== false) {
        echo "DONE     $file (already patched)\n";
        continue;
    }
    $new = $src;
    foreach ($list as $i => $p) {
        $anchor = isset($p['after']) ? $p['after'] : $p['before'];
        $n = substr_count($new, $anchor);
        if ($n !== 1) {
            echo "ANCHOR   $file #" . ($i + 1) . " found $n times (need 1)\n";
            $ok = false;
            continue 2;
        }
        $new = isset($p['after']) ? str_replace($anchor, $anchor . $p['text'], $new) : str_replace($anchor, $p['text'] . $anchor, $new);
    }
    echo "READY    $file (+" . (strlen($new) - strlen($src)) . " bytes)\n";
    $plan[$file] = $new;
}
if (!$ok) {
    echo "Nothing written.\n";
    exit(1);
}
if (!$apply) {
    echo $plan ? "Dry run. Add --apply to write.\n" : "Nothing to do.\n";
    exit(0);
}
if ($backupDir !== '' && !is_dir($backupDir) && !mkdir($backupDir, 0700, true)) {
    echo "FAILED   backup dir $backupDir\n";
    exit(1);
}
foreach ($plan as $file => $new) {
    $bak = ($backupDir !== '' ? $backupDir . '/' . basename($file) : $file) . '.amapi-bak-' . date('Ymd-His');
    if (!copy($file, $bak)) {
        echo "FAILED   backup $bak\n";
        exit(1);
    }
    file_put_contents($file, $new);
    echo "PATCHED  $file (backup $bak)\n";
}

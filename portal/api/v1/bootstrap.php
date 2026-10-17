<?php
/**
 * Shared bootstrap for the Seller API, cron and the seller cabinet page.
 * Standalone: does not load the VuCMS core, talks to its tables directly (AmFeatures boots the
 * few core pieces the features module needs).
 */
foreach (array('AmDb', 'AmSchema', 'AmText', 'AmAuth', 'AmCatalog', 'AmOrders', 'AmWebhook', 'AmFeatures', 'AmCurrency', 'AmCategories') as $class) {
    require_once __DIR__ . '/lib/' . $class . '.php';
}

function amapi_config()
{
    static $cfg;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
        foreach (array(__DIR__ . '/config.local.php', (string) getenv('AMAPI_CONFIG')) as $file) {
            if ($file !== '' && is_file($file)) {
                $cfg = array_replace($cfg, (array) require $file);
            }
        }
    }
    return $cfg;
}

function amapi_db()
{
    static $db;
    if ($db === null) {
        $cfg = amapi_config();
        $db = AmDb::fromPortal($cfg['portal_root'], $cfg['db']);
        AmSchema::ensure($db);
    }
    return $db;
}

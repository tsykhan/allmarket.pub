<?php
/**
 * allmarket.biz Seller API — settings.
 * Local overrides (not overwritten on update): config.local.php returning an array with the same keys.
 */
return array(
    // Portal root (httpdocs). DB credentials are read from its dbvucmsconf.inc.php.
    'portal_root' => dirname(dirname(__DIR__)),
    // Multitrade ("Мегамаркет") module id in all_module.
    'module_id' => 23,
    // Status for new items: 3 = published, 2 = waiting for moderation.
    'item_status' => 3,
    // Value for all_multitrade.lang of new items (production items use 'ua-ua'; '' = all languages).
    'item_lang' => 'ua-ua',
    // Product attributes → portal features (module/multitrade/features.api.php, «Характеристики»).
    // false = keep them only as a table in the description.
    'features' => true,
    // Language of names/values coming from the shop (the module exports one language).
    'content_lang' => 'ua-ua',
    // Currencies accepted from shops (empty = any the portal has a rate for). Prices are converted into the
    // seller's accounting currency (Кабінет → Валюти; empty = portal default money_unit_rate, else portal_currency).
    'currencies' => array(),
    'portal_currency' => 'USD',
    // Stock written for positions that are available with quantity 0 (shop allows orders out of stock).
    'backorder_amount' => 0,
    // Items hidden on the portal (status 0) are published again on the next full update while the shop exports
    // them as active (moderator states 1/2 are kept). false = a hide on the portal wins.
    'republish_hidden' => true,
    // Create missing portal categories from the shop's tree (under the mapped / parent portal category).
    'create_categories' => true,
    // Max positions per batch request.
    'max_batch' => 500,
    // Max orders per GET /orders page.
    'orders_max_limit' => 100,
    // Seller groups that may not use the API (2 = banned, 3 = guest, 4 = awaiting approval).
    'denied_groups' => array(2, 3, 4),
    // Request log retention, days.
    'log_days' => 30,
    // Outgoing webhook: timeout (s) and attempts per order version before giving up.
    'webhook_timeout' => 10,
    'webhook_max_tries' => 8,
    // Token for running cron.php over HTTP (?token=…); empty = CLI only.
    'cron_token' => '',
    // Overrides for DB connection (normally taken from dbvucmsconf.inc.php).
    'db' => array(),
);

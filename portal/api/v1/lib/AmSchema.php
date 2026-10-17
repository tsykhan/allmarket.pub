<?php
/**
 * The API's own tables. Portal tables (multitrade, multitrade_order, member, company, category)
 * are only read/updated, never altered. Created on first request; bump VERSION to migrate.
 */
class AmSchema
{
    const VERSION = 2;

    public static function ensure(AmDb $db)
    {
        $flag = __DIR__ . '/../.schema-' . self::VERSION;
        if (is_file($flag) && $db->tableExists('amapi_seller')) {
            return;
        }
        foreach (self::tables() as $sql) {
            $db->query($sql);
        }
        @file_put_contents($flag, date('c'));
    }

    public static function tables()
    {
        $opt = ' ENGINE=InnoDB DEFAULT CHARSET=utf8';
        return array(
            // One row per seller who generated an API key.
            "CREATE TABLE IF NOT EXISTS `#__amapi_seller` (
              `userid` bigint(20) unsigned NOT NULL,
              `username` varchar(30) NOT NULL DEFAULT '',
              `key_hash` char(64) NOT NULL DEFAULT '',
              `key_hint` varchar(16) NOT NULL DEFAULT '',
              `enabled` tinyint(1) unsigned NOT NULL DEFAULT 1,
              `default_catid` int(10) unsigned NOT NULL DEFAULT 0,
              `orders_from` int(10) unsigned NOT NULL DEFAULT 0,
              `webhook_url` varchar(255) NOT NULL DEFAULT '',
              `webhook_secret` varchar(128) NOT NULL DEFAULT '',
              `webhook_status` varchar(255) NOT NULL DEFAULT '',
              `created` int(10) unsigned NOT NULL DEFAULT 0,
              `key_created` int(10) unsigned NOT NULL DEFAULT 0,
              `last_used` int(10) unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`userid`),
              KEY `key_hash` (`key_hash`)
            )" . $opt,
            // external_id (shop) <-> all_multitrade.itemid (portal).
            "CREATE TABLE IF NOT EXISTS `#__amapi_item` (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `userid` bigint(20) unsigned NOT NULL,
              `external_id` varchar(64) NOT NULL,
              `itemid` bigint(20) unsigned NOT NULL DEFAULT 0,
              `group_id` varchar(64) NOT NULL DEFAULT '',
              `shop_category_id` varchar(64) NOT NULL DEFAULT '',
              `sku` varchar(64) NOT NULL DEFAULT '',
              `ean` varchar(32) NOT NULL DEFAULT '',
              `old_price` decimal(10,2) DEFAULT NULL,
              `deleted` tinyint(1) unsigned NOT NULL DEFAULT 0,
              `created` int(10) unsigned NOT NULL DEFAULT 0,
              `updated` int(10) unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              UNIQUE KEY `seller_ext` (`userid`,`external_id`),
              KEY `itemid` (`itemid`)
            )" . $opt,
            // Shop category tree as sent by /categories/batch.
            "CREATE TABLE IF NOT EXISTS `#__amapi_category` (
              `userid` bigint(20) unsigned NOT NULL,
              `shop_id` varchar(64) NOT NULL,
              `parent_id` varchar(64) NOT NULL DEFAULT '',
              `name` varchar(255) NOT NULL DEFAULT '',
              `url` varchar(255) NOT NULL DEFAULT '',
              `catid` int(10) unsigned NOT NULL DEFAULT 0,
              `updated` int(10) unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`userid`,`shop_id`)
            )" . $opt,
            // Sync state of portal orders (all_multitrade_order.itemid).
            "CREATE TABLE IF NOT EXISTS `#__amapi_order` (
              `orderid` bigint(20) unsigned NOT NULL,
              `userid` bigint(20) unsigned NOT NULL,
              `acked` tinyint(1) unsigned NOT NULL DEFAULT 0,
              `acked_at` int(10) unsigned NOT NULL DEFAULT 0,
              `external_order_id` varchar(64) NOT NULL DEFAULT '',
              `reference` varchar(64) NOT NULL DEFAULT '',
              `shop_status` varchar(32) NOT NULL DEFAULT '',
              `shop_status_at` int(10) unsigned NOT NULL DEFAULT 0,
              `tracking_number` varchar(64) NOT NULL DEFAULT '',
              `webhook_hash` char(32) NOT NULL DEFAULT '',
              `webhook_at` int(10) unsigned NOT NULL DEFAULT 0,
              `webhook_tries` smallint(5) unsigned NOT NULL DEFAULT 0,
              `webhook_error` varchar(255) NOT NULL DEFAULT '',
              PRIMARY KEY (`orderid`),
              KEY `userid` (`userid`,`acked`)
            )" . $opt,
            // Portal categories the API created (only these are moved or removed when the shop's placement changes).
            "CREATE TABLE IF NOT EXISTS `#__amapi_created` (
              `catid` int(10) unsigned NOT NULL,
              `userid` bigint(20) unsigned NOT NULL,
              `shop_id` varchar(64) NOT NULL DEFAULT '',
              `created` int(10) unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`catid`),
              KEY `userid` (`userid`)
            )" . $opt,
            // Request journal shown in the seller cabinet.
            "CREATE TABLE IF NOT EXISTS `#__amapi_log` (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `userid` bigint(20) unsigned NOT NULL DEFAULT 0,
              `t` int(10) unsigned NOT NULL DEFAULT 0,
              `method` varchar(8) NOT NULL DEFAULT '',
              `route` varchar(100) NOT NULL DEFAULT '',
              `code` smallint(5) unsigned NOT NULL DEFAULT 0,
              `items` int(10) unsigned NOT NULL DEFAULT 0,
              `errors` int(10) unsigned NOT NULL DEFAULT 0,
              `ms` int(10) unsigned NOT NULL DEFAULT 0,
              `message` varchar(255) NOT NULL DEFAULT '',
              PRIMARY KEY (`id`),
              KEY `userid` (`userid`,`t`)
            )" . $opt,
        );
    }
}

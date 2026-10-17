-- Production (allmarket.biz, 2026-10-16) tables/columns used by the Seller API that the 2017 backup lacks.
-- DDL copied read-only from production (SHOW CREATE TABLE). Staging only.
SET sql_mode='ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION';
SET GLOBAL sql_mode='ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION';
ALTER TABLE `all_multitrade` ADD COLUMN IF NOT EXISTS `ubrandid` int(10) unsigned NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS `all_ubrand` (
  `itemid` int(10) unsigned NOT NULL AUTO_INCREMENT, `username` varchar(30) NOT NULL DEFAULT '', `name` varchar(100) NOT NULL DEFAULT '',
  `nkey` varchar(110) NOT NULL DEFAULT '', `thumb` varchar(255) NOT NULL DEFAULT '', `site` varchar(255) NOT NULL DEFAULT '',
  `industry` int(10) unsigned NOT NULL DEFAULT 0, `content` mediumtext DEFAULT NULL, `listorder` smallint(6) NOT NULL DEFAULT 0,
  `status` tinyint(3) unsigned NOT NULL DEFAULT 0, `reason` varchar(255) NOT NULL DEFAULT '', `addtime` int(10) unsigned NOT NULL DEFAULT 0,
  `edittime` int(10) unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`itemid`), UNIQUE KEY `nkey` (`nkey`), KEY `user` (`username`,`status`,`listorder`), KEY `st` (`status`), KEY `industry` (`industry`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
CREATE TABLE IF NOT EXISTS `all_mtf_feature` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT, `userid` int(10) unsigned NOT NULL DEFAULT 0, `groupid` int(10) unsigned NOT NULL DEFAULT 0,
  `code` varchar(64) NOT NULL DEFAULT '', `type` varchar(12) NOT NULL DEFAULT 'text', `sort` int(11) NOT NULL DEFAULT 0,
  `filterable` tinyint(4) NOT NULL DEFAULT 0, `status` tinyint(4) NOT NULL DEFAULT 1, `addtime` int(10) unsigned NOT NULL DEFAULT 0,
  `show_product` tinyint(4) NOT NULL DEFAULT 1, `show_list` tinyint(4) NOT NULL DEFAULT 0, `show_header` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `userid` (`userid`), KEY `groupid` (`groupid`), KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `all_mtf_value` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT, `featureid` int(10) unsigned NOT NULL, `userid` int(10) unsigned NOT NULL DEFAULT 0,
  `code` varchar(64) NOT NULL DEFAULT '', `sort` int(11) NOT NULL DEFAULT 0, PRIMARY KEY (`id`), KEY `featureid` (`featureid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `all_mtf_group` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT, `userid` int(10) unsigned NOT NULL DEFAULT 0, `code` varchar(64) NOT NULL DEFAULT '',
  `sort` int(11) NOT NULL DEFAULT 0, PRIMARY KEY (`id`), KEY `userid` (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `all_mtf_i18n` (
  `kind` varchar(8) NOT NULL, `ref` int(10) unsigned NOT NULL, `lang` varchar(8) NOT NULL, `name` varchar(255) NOT NULL DEFAULT '',
  `unit` varchar(32) NOT NULL DEFAULT '', `prefix` varchar(32) NOT NULL DEFAULT '', `description` varchar(1000) NOT NULL DEFAULT '',
  PRIMARY KEY (`kind`,`ref`,`lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `all_mtf_product` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT, `itemid` bigint(20) unsigned NOT NULL, `featureid` int(10) unsigned NOT NULL,
  `valueid` int(10) unsigned NOT NULL DEFAULT 0, `num` decimal(18,4) DEFAULT NULL, `custom` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `itemid` (`itemid`), KEY `fv` (`featureid`,`valueid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `all_mtf_product_i18n` (
  `pid` int(10) unsigned NOT NULL, `lang` varchar(8) NOT NULL, `text` varchar(1000) NOT NULL DEFAULT '', PRIMARY KEY (`pid`,`lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `all_mtf_xref` (
  `engine` varchar(16) NOT NULL, `kind` varchar(8) NOT NULL, `ref` int(10) unsigned NOT NULL, `ext_id` varchar(64) NOT NULL,
  PRIMARY KEY (`engine`,`kind`,`ext_id`), KEY `ref` (`kind`,`ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `all_mtf_user` (
  `userid` int(10) unsigned NOT NULL, `enabled` tinyint(4) NOT NULL DEFAULT 0, PRIMARY KEY (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `all_mtf_feature_cat` (
  `featureid` int(10) unsigned NOT NULL, `catid` int(10) unsigned NOT NULL, PRIMARY KEY (`featureid`,`catid`), KEY `catid` (`catid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- Currency rates (units per 1 USD) and the portal default currency, as on production.
CREATE TABLE IF NOT EXISTS `all_fx` (
  `currency` varchar(8) NOT NULL, `usd` double NOT NULL DEFAULT 0, `wb` double DEFAULT NULL, `wb_year` smallint(6) NOT NULL DEFAULT 0,
  `updated` int(10) unsigned NOT NULL DEFAULT 0, `wb_updated` int(10) unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`currency`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
INSERT IGNORE INTO `all_fx` (currency, usd, updated) VALUES ('USD',1,UNIX_TIMESTAMP()),('UAH',44.930464,UNIX_TIMESTAMP()),('EUR',0.89165,UNIX_TIMESTAMP()),('PLN',3.896818,UNIX_TIMESTAMP());
ALTER TABLE `all_company` ADD COLUMN IF NOT EXISTS `currency` varchar(8) NOT NULL DEFAULT '';
ALTER TABLE `all_company` ADD COLUMN IF NOT EXISTS `currencies` varchar(1000) NOT NULL DEFAULT '';
ALTER TABLE `all_company` ADD COLUMN IF NOT EXISTS `fx_manual` text NULL;
INSERT INTO `all_setting` (item, item_key, item_value) SELECT '1','money_unit_rate','USD' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `all_setting` WHERE item_key='money_unit_rate');

<?php
defined('IN_VUCMS') or exit('Access Denied');
/* Staging stand-in for the portal's dbvucmsconf.inc.php (same keys). */
$CFG['database'] = 'mysqli';
$CFG['pconnect'] = '0';
$CFG['db_host'] = 'amportal-db';
$CFG['db_name'] = 'allmarket';
$CFG['db_user'] = 'root';
$CFG['db_pass'] = 'staging';
$CFG['db_charset'] = 'utf8';
$CFG['tb_pre'] = 'all_';

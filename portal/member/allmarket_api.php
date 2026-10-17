<?php
/*
 * Old address of the Seller API page: the page now lives in the cabinet's «Інтеграції» hub,
 * tab «Інтернет-магазин» (see install/integrations_patch.php).
 */
require 'config.inc.php';
require '../common.inc.php';
dheader($MODULE[2]['linkurl'] . 'integrations.php?kind=shop&action=shopapi');

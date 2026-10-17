<?php
/***************************************************************************
 * allmarket.biz Sync — hooks.                                             *
 ***************************************************************************/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

fn_register_hooks(
    // Order status → allmarket.biz (with the tracking number of the latest shipment).
    'change_order_status',
    // A tracking number added after the status change is sent again.
    'create_shipment_post'
);

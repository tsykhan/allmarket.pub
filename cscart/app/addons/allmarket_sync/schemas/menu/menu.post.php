<?php
/***************************************************************************
 * allmarket.biz Sync — admin menu (Marketing).                            *
 * NB: the "Marketing" dropdown lives under $schema['central'], not 'top'. *
 ***************************************************************************/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

$schema['central']['marketing']['items']['allmarket_sync'] = [
    'attrs'    => ['class' => 'is-addon'],
    'href'     => 'allmarket_sync.manage',
    'position' => 750,
    'subitems' => [
        'allmarket_sync_manage' => [
            'href'     => 'allmarket_sync.manage',
            'position' => 10,
        ],
        'allmarket_sync_categories' => [
            'href'     => 'allmarket_sync.categories',
            'position' => 20,
        ],
    ],
];

return $schema;

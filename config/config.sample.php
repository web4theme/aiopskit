<?php
// Copy to config/config.php and edit. Environment variables win over these values.
return [
    'storage' => dirname(__DIR__) . '/storage',
    'timezone' => 'UTC',
    'user_agent' => 'AIOpsKit/1.0',

    'http' => [
        'timeout' => 15,
        'max_bytes' => 2097152,
    ],

    'odoo' => [
        'url' => '',
        'db' => '',
        'user' => '',
        'password' => '',
        // Models exposed to the MCP server. Empty = all models readable, no writes.
        'models' => ['res.partner', 'sale.order'],
        // Writes also require the model to be listed in `models`.
        'allow_write' => false,
        // Odoo is often on a private network (localhost, 10.x, 192.168.x).
        'allow_private' => true,
    ],
];

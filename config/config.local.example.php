<?php
// Settings for one server (for example your Hostinger account).
//
// 1. Copy this file to config/config.local.php on the server and fill it in.
// 2. config.local.php is not saved in Git, so the database password never goes to GitHub.
//
// Anything set here replaces the same setting in config.php. Leave out what you don't need to change.

return [
    // '' when the portal is at the root of the domain (https://yourdomain.com/).
    // '/portal' when it is in a folder (https://yourdomain.com/portal/).
    'base_url' => '',

    // Always false on a live server, so visitors never see error details.
    'debug' => false,

    // From hPanel → Databases → MySQL Databases (Hostinger adds a prefix like u123456789_ to the names).
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'u543028936_araltrack2026',
        'user' => 'u543028936_araltrack',
        'pass' => 'Araltrack2026**',
    ],
];

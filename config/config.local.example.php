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
        'name' => 'u123456789_araltrack',
        'user' => 'u123456789_araltrack',
        'pass' => 'the database password',
    ],

    // For teacher sign-up and "forgot password" emails: the site's address and a mailbox to send from.
    // Hostinger: hPanel → Emails → create e.g. no-reply@yourdomain.com, then use its address and password here.
    'app_url' => 'https://yourdomain.com',
    'mail' => [
        'transport'  => 'smtp',
        'from'       => 'no-reply@yourdomain.com',
        'from_name'  => 'AralTrack',
        'host'       => 'smtp.hostinger.com',
        'port'       => 465,
        'encryption' => 'ssl',
        'username'   => 'no-reply@yourdomain.com',
        'password'   => 'the mailbox password',
    ],

    // Online payments with PayMongo (dashboard → Developers): the secret key, and the secret of the webhook you add
    // for https://yourdomain.com/paymongo-webhook.php with the event "checkout_session.payment.paid".
    // Use the sk_test_ key first to try it; nothing is charged.
    'paymongo' => [
        'secret_key'     => 'sk_test_...',
        'webhook_secret' => 'whsk_...',
    ],
];

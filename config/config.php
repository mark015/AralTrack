<?php
// Settings for AralTrack (the school portal).
// This folder is not reachable from the browser (see the .htaccess files).

return [
    'app_name'    => 'AralTrack',
    'school_name' => 'Sample Senior High School',
    'base_url'    => '/student-portal',   // URL path of this folder under htdocs, no trailing slash
    'debug'       => false,               // true shows error details: only on your own computer (set it in config.local.php)
    'timezone'    => 'Asia/Manila',

    // true: after new code is deployed (e.g. automatically from GitHub), the first page load applies the
    // new database changes. They only add tables and columns; data is kept. false: run
    // "php database/migrate.php" yourself after each update.
    'auto_migrate' => true,

    // Teachers can create their own account at signup.php (a workspace of their own). Needs 'app_url' and
    // 'mail' below so the sign-up code and password-reset emails can be sent.
    'teacher_signup' => true,

    // The site's full address, used in links inside emails, e.g. 'https://mjtechcore.com'. Set it in config.local.php.
    'app_url' => '',

    // Sending email (sign-up codes, password resets). Set it in config.local.php.
    //   'log'  : nothing is sent; each email is saved in storage/mail/ (testing on your own computer, with debug on)
    //   'smtp' : sends through a mailbox, e.g. a Hostinger email account (smtp.hostinger.com, port 465, 'ssl')
    //   'mail' : PHP's mail() function
    'mail' => [
        'transport'  => 'log',
        'from'       => '',          // e.g. 'no-reply@yourdomain.com' (for smtp: the mailbox you sign in with)
        'from_name'  => 'AralTrack',
        'host'       => '',
        'port'       => 465,
        'encryption' => 'ssl',       // 'ssl' (port 465), 'tls' (port 587) or '' (no encryption)
        'username'   => '',
        'password'   => '',
    ],

    // Online payments (GCash, Maya, cards, QR Ph) through PayMongo. Set the keys in config.local.php:
    // secret_key from PayMongo → Developers (sk_test_… to try it, sk_live_… for real payments) and
    // webhook_secret from the webhook you add there for <app_url>/paymongo-webhook.php (event checkout_session.payment.paid).
    'paymongo' => [
        'secret_key'     => '',
        'webhook_secret' => '',
    ],

    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'student_portal',
        'user' => 'root',
        'pass' => '',
    ],

    // false: learners view grades with their LRN only.
    // true:  learners must also enter their birthdate (stops classmates who know an LRN).
    'require_birthdate' => false,

    // Sign-out after this many minutes without any page load
    'student_idle_minutes' => 15,
    'staff_idle_minutes'   => 60,   // longer, so teachers don't lose a half-typed grade sheet
    'gate_idle_minutes'    => 720,  // a Gate guard account on the gate phone stays signed in through the school day
    'parent_idle_minutes'  => 60,

    // Attendance is taken per subject class. A learner scanned more than this many
    // minutes after the class start time is marked Late.
    'late_after_minutes' => 15,

    // The address parents use to open the portal, printed on parent link slips,
    // e.g. 'https://portal.yourschool.edu.ph/student-portal'. Leave empty to use the
    // address of the computer that prints the slips.
    'public_url' => '',

    // Lock sign-in after too many failed tries inside the window
    'login_max_per_id' => 5,    // per LRN or username
    'login_max_per_ip' => 20,   // per IP address (a whole computer lab may share one IP)
    'login_window_min' => 15,

    // Senior High School. Each school year has 2 semesters: Q1–Q2 in the 1st, Q3–Q4 in the 2nd.
    'grade_levels' => [11, 12],
    'strands'      => ['STEM', 'ABM', 'HUMSS', 'GAS', 'TVL-ICT', 'TVL-HE', 'TVL-IA', 'TVL-AFA', 'Sports', 'Arts and Design'],

    'upload_max_bytes' => 2 * 1024 * 1024,
    'grade_min'        => 60,   // lowest transmuted grade (DepEd Order No. 8, s. 2015)
    'grade_max'        => 100,
    'passing_grade'    => 75,
];

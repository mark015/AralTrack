<?php
// Settings for the Student Grade Portal.
// This folder is not reachable from the browser (see the .htaccess files).

return [
    'app_name'    => 'Student Grade Portal',
    'school_name' => 'Sample Senior High School',
    'base_url'    => '/student-portal',   // URL path of this folder under htdocs, no trailing slash
    'debug'       => true,                // show error details; set to false on the live server
    'timezone'    => 'Asia/Manila',

    // true: after new code is deployed (e.g. automatically from GitHub), the first page load applies the
    // new database changes. They only add tables and columns; data is kept. false: run
    // "php database/migrate.php" yourself after each update.
    'auto_migrate' => true,

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

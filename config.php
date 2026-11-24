<?php
// Database settings (XAMPP / WAMP defaults). Change if yours differ.
define('DB_HOST', 'localhost');
define('DB_NAME', 'homedy');
define('DB_USER', 'root');
define('DB_PASS', '');

define('APP_NAME', 'HomeDy');

// BASE_URL is auto-detected from the folder the project sits in.
// Only set it by hand if links or CSS break, e.g.:
// define('BASE_URL', '/household-hiring');

date_default_timezone_set('Asia/Manila');

// Shows the real database error on register/login pages. Set to false when you submit/demo.
define('APP_DEBUG', true);

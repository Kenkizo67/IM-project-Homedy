<?php
// Email settings. Copy this file to  config.mail.php  and fill it in. Keep config.mail.php private
// (it holds your email password). Without config.mail.php, no real emails are sent: each one is only
// printed in the terminal where the PHP server runs, so you can still see what would be sent.
//
// Gmail example: turn on 2-Step Verification, create an "App password"
// (Google Account > Security > App passwords) and use it as MAIL_PASS. Your normal password won't work.

define('MAIL_ENABLED',   true);
define('MAIL_HOST',      'smtp.gmail.com');
define('MAIL_PORT',      587);                 // 587 with 'tls'   |   465 with 'ssl'
define('MAIL_SECURE',    'tls');               // 'tls' (STARTTLS), 'ssl', or '' for none
define('MAIL_USER',      'yourname@gmail.com');
define('MAIL_PASS',      'your-16-letter-app-password');
define('MAIL_FROM',      'yourname@gmail.com');
define('MAIL_FROM_NAME', 'HomeDy');

// Address of the website, used for the "Log in" link inside emails. Leave '' to leave the link out.
define('APP_URL',        '');                  // e.g. 'http://192.168.1.5:8000'

// Payment reminders: first reminder after this many hours unpaid, then again every this many hours.
define('REMINDER_AFTER_HOURS', 24);
define('REMINDER_EVERY_HOURS', 24);

<?php
// Sends payment reminder emails for completed jobs that are still unpaid.
// Run it by hand or on a schedule (command line only):   php83 cron/payment_reminders.php
// Alpine daily example (crontab -e):   0 9 * * *  php83 /public/homedy/household-hiring/cron/payment_reminders.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line.');
}
require_once __DIR__ . '/../includes/init.php';
$n = send_payment_reminders(db());
echo "Payment reminders sent: $n\n";

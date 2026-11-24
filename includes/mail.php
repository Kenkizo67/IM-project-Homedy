<?php
declare(strict_types=1);

/*
 * Email notifications.
 *  - Settings come from config.mail.php (see config.mail.example.php). Without it, emails are only
 *    printed to the PHP log (the terminal), nothing is sent.
 *  - Sending never breaks the page: any failure is logged and the app carries on.
 *  - Plain-text emails sent over SMTP with a tiny built-in client (no libraries needed).
 *    TLS needs the php openssl extension (Alpine: apk add php83-openssl ca-certificates).
 */

if (is_file(__DIR__ . '/../config.mail.php')) {
    require_once __DIR__ . '/../config.mail.php';
}
foreach ([
    'MAIL_ENABLED' => false, 'MAIL_HOST' => '', 'MAIL_PORT' => 587, 'MAIL_SECURE' => 'tls',
    'MAIL_USER' => '', 'MAIL_PASS' => '', 'MAIL_FROM' => '', 'MAIL_FROM_NAME' => 'HomeDy', 'APP_URL' => '',
    'REMINDER_AFTER_HOURS' => 24, 'REMINDER_EVERY_HOURS' => 24,
] as $name => $default) {
    if (!defined($name)) {
        define($name, $default);
    }
}

/** Keeps line breaks out of anything that goes into a header (stops header injection). */
function mail_oneline(string $s): string
{
    return trim(preg_replace('/[\r\n]+/', ' ', $s) ?? '');
}

/** Reads one (possibly multi-line) SMTP reply and returns [code, text]. */
function smtp_read($fp): array
{
    $text = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $text .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    return [(int) substr($text, 0, 3), trim($text)];
}

function smtp_cmd($fp, string $cmd, array $okCodes): string
{
    if ($cmd !== '') {
        fwrite($fp, $cmd . "\r\n");
    }
    [$code, $text] = smtp_read($fp);
    if (!in_array($code, $okCodes, true)) {
        throw new RuntimeException('SMTP error after "' . (str_starts_with($cmd, 'AUTH') || strlen($cmd) > 60 ? '…' : $cmd) . '": ' . $text);
    }
    return $text;
}

/** Sends one plain-text email over SMTP. Throws on any problem. */
function smtp_send(string $to, string $subject, string $body): void
{
    $secure = strtolower((string) MAIL_SECURE);
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . MAIL_HOST . ':' . MAIL_PORT, $errno, $errstr, 6);
    if (!$fp) {
        throw new RuntimeException("Cannot connect to the mail server ($errstr)");
    }
    stream_set_timeout($fp, 8);
    try {
        smtp_cmd($fp, '', [220]);
        $host = preg_replace('/[^A-Za-z0-9.-]/', '', (string) gethostname()) ?: 'localhost';
        smtp_cmd($fp, 'EHLO ' . $host, [250]);
        if ($secure === 'tls') {
            smtp_cmd($fp, 'STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not start TLS (is the php openssl extension installed?)');
            }
            smtp_cmd($fp, 'EHLO ' . $host, [250]);
        }
        if (MAIL_USER !== '') {
            smtp_cmd($fp, 'AUTH LOGIN', [334]);
            smtp_cmd($fp, base64_encode(MAIL_USER), [334]);
            smtp_cmd($fp, base64_encode(MAIL_PASS), [235]);
        }
        $from = MAIL_FROM !== '' ? MAIL_FROM : MAIL_USER;
        smtp_cmd($fp, 'MAIL FROM:<' . mail_oneline($from) . '>', [250]);
        smtp_cmd($fp, 'RCPT TO:<' . mail_oneline($to) . '>', [250, 251]);
        smtp_cmd($fp, 'DATA', [354]);

        $fromName = mail_oneline(MAIL_FROM_NAME);
        $headers = [
            'Date: ' . date('r'),
            'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . mail_oneline($from) . '>',
            'To: <' . mail_oneline($to) . '>',
            'Subject: =?UTF-8?B?' . base64_encode(mail_oneline($subject)) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(8)) . '@homedy.local>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $msg = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
        fwrite($fp, $msg . "\r\n.\r\n");
        [$code, $text] = smtp_read($fp);
        if ($code !== 250) {
            throw new RuntimeException('Mail server refused the message: ' . $text);
        }
        smtp_cmd($fp, 'QUIT', [221]);
    } finally {
        fclose($fp);
    }
}

/** Sends (or, without mail settings, just logs) one email. Never throws. Returns true when it was sent. */
function send_mail(?string $to, string $subject, string $body): bool
{
    $to = trim((string) $to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (!MAIL_ENABLED || MAIL_HOST === '') {
        error_log("[HomeDy mail NOT sent - email is not set up] To: $to | Subject: " . mail_oneline($subject) . "\n" . $body);
        return false;
    }
    try {
        smtp_send($to, $subject, $body);
        return true;
    } catch (Throwable $e) {
        error_log('[HomeDy mail FAILED] To: ' . $to . ' | ' . $e->getMessage());
        return false;
    }
}

function mail_link(string $path): string
{
    return APP_URL !== '' ? "\n\nLog in: " . rtrim(APP_URL, '/') . '/' . ltrim($path, '/') : '';
}

function mail_sign(): string
{
    return "\n\n— " . APP_NAME;
}

// ---- The notifications ------------------------------------------------------

/** Request details with the customer's email and name, or null. */
function mail_request(PDO $pdo, int $requestId): ?array
{
    $s = $pdo->prepare(
        'SELECT r.request_id, r.price, r.workers_needed, r.status, c.category_name,
                u.email, u.first_name, p.amount, p.payment_status
         FROM job_requests r
         JOIN service_categories c ON c.category_id = r.category_id
         JOIN customers cu ON cu.customer_id = r.customer_id
         JOIN users u ON u.user_id = cu.user_id
         LEFT JOIN payments p ON p.request_id = r.request_id
         WHERE r.request_id = ?'
    );
    $s->execute([$requestId]);
    return $s->fetch() ?: null;
}

function mail_worker_name(PDO $pdo, int $workerId): string
{
    $s = $pdo->prepare('SELECT u.first_name, u.last_name FROM workers w JOIN users u ON u.user_id = w.user_id WHERE w.worker_id = ?');
    $s->execute([$workerId]);
    $r = $s->fetch();
    return $r ? $r['first_name'] . ' ' . $r['last_name'] : 'A worker';
}

/**
 * A worker accepted the customer's request. $needsDecision = true when the customer must still accept
 * or reject them (open request); false when the customer had picked this worker, who is now on the job.
 */
function notify_worker_accepted(PDO $pdo, int $requestId, int $workerId, bool $needsDecision): void
{
    try {
        $req = mail_request($pdo, $requestId);
        if (!$req) {
            return;
        }
        $name = mail_worker_name($pdo, $workerId);
        $onJob = worker_names(load_request_workers($pdo, [$requestId])[$requestId] ?? []);

        $body = "Hi {$req['first_name']},\n\n";
        if ($needsDecision) {
            $body .= "$name accepted your {$req['category_name']} request (#$requestId) and is waiting for your answer.\n"
                   . "Open My requests to accept or reject them.";
        } else {
            $body .= "$name accepted your {$req['category_name']} request (#$requestId) and is now on the job.";
        }
        if ($onJob !== '') {
            $body .= "\n\nWorkers on the job so far: $onJob.";
        }
        send_mail(
            $req['email'],
            $needsDecision ? "$name accepted your request #$requestId" : "$name is on your request #$requestId",
            $body . mail_link('customer/requests.php') . mail_sign()
        );
    } catch (Throwable $e) {
        error_log('notify_worker_accepted: ' . $e->getMessage());
    }
}

/** The admin verified a worker. */
function notify_worker_verified(PDO $pdo, int $workerId): void
{
    try {
        $s = $pdo->prepare('SELECT u.email, u.first_name FROM workers w JOIN users u ON u.user_id = w.user_id WHERE w.worker_id = ?');
        $s->execute([$workerId]);
        $w = $s->fetch();
        if (!$w) {
            return;
        }
        send_mail(
            $w['email'],
            'Your ' . APP_NAME . ' profile is verified',
            "Hi {$w['first_name']},\n\nGood news! An admin reviewed your documents and verified your profile.\n"
            . "You can now accept jobs that match your skills." . mail_link('worker/open_jobs.php') . mail_sign()
        );
    } catch (Throwable $e) {
        error_log('notify_worker_verified: ' . $e->getMessage());
    }
}

/** The job is completed: tell the customer who did it and what is due. */
function notify_job_done(PDO $pdo, int $requestId): void
{
    try {
        $req = mail_request($pdo, $requestId);
        if (!$req) {
            return;
        }
        $done = worker_names(load_request_workers($pdo, [$requestId])[$requestId] ?? [], ['done']);
        $body = "Hi {$req['first_name']},\n\nYour {$req['category_name']} request (#$requestId) is completed.\n"
              . ($done !== '' ? "Done by: $done.\n" : '');
        if ($req['amount'] !== null && $req['payment_status'] === 'unpaid') {
            $body .= "\nPayment due: " . money($req['amount']) . ' (not yet paid).'
                   . "\nOpen My requests to pay, and to rate the workers.";
        } else {
            $body .= "\nOpen My requests to rate the workers.";
        }
        send_mail($req['email'], "Your request #$requestId is done" . ($req['payment_status'] === 'unpaid' ? ' - payment due' : ''),
                  $body . mail_link('customer/requests.php') . mail_sign());
    } catch (Throwable $e) {
        error_log('notify_job_done: ' . $e->getMessage());
    }
}

/**
 * Reminds customers about completed jobs that are still unpaid. One reminder per job per
 * REMINDER_EVERY_HOURS, starting REMINDER_AFTER_HOURS after the job was completed.
 * Returns how many reminders were sent.
 */
function send_payment_reminders(PDO $pdo): int
{
    $doneBefore = date('Y-m-d H:i:s', time() - (int) REMINDER_AFTER_HOURS * 3600);
    $lastBefore = date('Y-m-d H:i:s', time() - (int) REMINDER_EVERY_HOURS * 3600);
    $s = $pdo->prepare(
        "SELECT p.payment_id, p.request_id
         FROM payments p JOIN job_requests r ON r.request_id = p.request_id
         WHERE p.payment_status = 'unpaid' AND r.status = 'completed' AND r.date_completed <= ?
           AND (p.last_reminder IS NULL OR p.last_reminder <= ?)
         ORDER BY p.payment_id LIMIT 200"
    );
    $s->execute([$doneBefore, $lastBefore]);
    $sent = 0;
    foreach ($s->fetchAll() as $p) {
        $req = mail_request($pdo, (int) $p['request_id']);
        if (!$req) {
            continue;
        }
        $done = worker_names(load_request_workers($pdo, [(int) $p['request_id']])[(int) $p['request_id']] ?? [], ['done']);
        $ok = send_mail(
            $req['email'],
            "Reminder: payment for request #{$p['request_id']} is still unpaid",
            "Hi {$req['first_name']},\n\nYour {$req['category_name']} job (#{$p['request_id']}) was completed"
            . ($done !== '' ? " by $done" : '') . ", but the payment of " . money($req['amount']) . " is not paid yet.\n"
            . "Please open My requests and pay to settle it." . mail_link('customer/requests.php') . mail_sign()
        );
        if ($ok) {
            // Only a real send counts, so a failed email is retried next time.
            $pdo->prepare('UPDATE payments SET last_reminder = ? WHERE payment_id = ?')->execute([now_sql(), (int) $p['payment_id']]);
            $sent++;
        }
    }
    return $sent;
}

<?php
// Diagnostic page: open it in the browser to see what is wrong with the database setup.
// Delete this file (or set APP_DEBUG to false) when everything works.
require_once __DIR__ . '/config.php';
if (!APP_DEBUG) { http_response_code(404); exit('Not found'); }

$expected = [
    'users'              => ['user_id','username','password_hash','first_name','last_name','email','role'],
    'admins'             => ['admin_id','user_id','contact_number'],
    'customers'          => ['customer_id','user_id','address','contact_number'],
    'workers'            => ['worker_id','user_id','address','contact_number','is_verified','date_registered'],
    'worker_documents'   => ['document_id','worker_id','doc_type','original_name','stored_name','file_size','date_uploaded'],
    'service_categories' => ['category_id','category_name'],
    'worker_skills'      => ['worker_id','category_id'],
    'job_requests'       => ['request_id','customer_id','worker_id','category_id','description','price','status','date_request','date_accepted','date_completed'],
    'reviews'            => ['review_id','request_id','customer_id','worker_id','rating','quality_rating','punctuality_rating','professionalism_rating','efficiency_rating','remarks','date_created'],
    'payments'           => ['payment_id','request_id','amount','payment_method','payment_status','payment_date'],
];
$rows = []; $fatal = null;

foreach (['localhost', '127.0.0.1'] as $host) {
    try {
        new PDO("mysql:host=$host;charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $rows[] = ["Connect to MySQL server at <code>$host</code>", true, 'OK'];
    } catch (PDOException $e) {
        $rows[] = ["Connect to MySQL server at <code>$host</code>", false, htmlspecialchars($e->getMessage())];
    }
}

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $rows[] = ['Open database <code>' . DB_NAME . '</code> using DB_HOST <code>' . DB_HOST . '</code>', true, 'OK'];

    $have = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($expected as $table => $cols) {
        if (!in_array($table, $have, true)) {
            $rows[] = ["Table <code>$table</code>", false, 'MISSING: run database/schema.sql'];
            continue;
        }
        $actual = array_column($pdo->query("DESCRIBE `$table`")->fetchAll(), 'Field');
        $missing = array_diff($cols, $actual);
        $extra = array_diff($actual, $cols);
        $msg = $missing ? 'Missing columns: ' . implode(', ', $missing) . '. This table is from an older schema; drop it and re-run schema.sql.' : 'OK';
        if (!$missing && $extra) { $msg .= ' (extra columns: ' . implode(', ', $extra) . ')'; }
        $rows[] = ["Table <code>$table</code>", !$missing, htmlspecialchars($msg)];
    }
    $n = (int) $pdo->query('SELECT COUNT(*) FROM service_categories')->fetchColumn();
    $rows[] = ['Service categories loaded', $n > 0, "$n found" . ($n ? '' : ' (workers cannot pick skills)')];
} catch (PDOException $e) {
    $rows[] = ['Open database <code>' . DB_NAME . '</code> using DB_HOST <code>' . DB_HOST . '</code>', false, htmlspecialchars($e->getMessage())];
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Database check</title>
<style>body{font:15px/1.5 system-ui,sans-serif;max-width:760px;margin:24px auto;padding:0 16px}
table{border-collapse:collapse;width:100%}td{border:1px solid #ddd;padding:8px;vertical-align:top}
.ok{color:#0a6b3d;font-weight:700}.bad{color:#b3261e;font-weight:700}code{background:#f2f2f2;padding:1px 4px;border-radius:4px}</style></head>
<body><h1>Database check</h1>
<table><?php foreach ($rows as [$label, $ok, $msg]): ?>
<tr><td><?= $label ?></td><td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✔' : '✘' ?></td><td><?= $msg ?></td></tr>
<?php endforeach; ?></table>
<p>Settings read from <code>config.php</code>: user <code><?= htmlspecialchars(DB_USER) ?></code>, password <?= DB_PASS === '' ? '(empty)' : '(set)' ?>.</p>
</body></html>

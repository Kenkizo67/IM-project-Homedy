<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin');

$pdo = db();
require_once __DIR__ . '/../includes/charts.php';

// "Send payment reminders" button
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_reminders') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
    } else {
        $n = send_payment_reminders($pdo);
        flash($n > 0 ? 'success' : 'error', $n > 0
            ? "Sent $n payment reminder" . ($n === 1 ? '' : 's') . '.'
            : (MAIL_ENABLED ? 'No reminders to send right now (none are due, or they were reminded recently).'
                            : 'Email is not set up yet, so nothing was sent. See config.mail.example.php.'));
    }
    redirect('admin/dashboard.php');
}

// ---- Workers: awaiting verification / verified and on a job / verified and free
$busy = "EXISTS (SELECT 1 FROM request_workers rw JOIN job_requests r ON r.request_id = rw.request_id
                 WHERE rw.worker_id = w.worker_id AND rw.status = 'selected' AND r.status = 'accepted')";
$w = $pdo->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN w.is_verified = 0 THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN w.is_verified = 1 AND $busy THEN 1 ELSE 0 END), 0) AS busy,
            COALESCE(SUM(CASE WHEN w.is_verified = 1 AND NOT $busy THEN 1 ELSE 0 END), 0) AS free
     FROM workers w"
)->fetch();
$workersTotal   = (int) $w['total'];
$workersPending = (int) $w['pending'];
$workersBusy    = (int) $w['busy'];
$workersFree    = (int) $w['free'];

// ---- Customers: with a request in progress / only finished or cancelled requests / no requests yet
$active = "EXISTS (SELECT 1 FROM job_requests r WHERE r.customer_id = c.customer_id AND r.status IN ('pending', 'accepted'))";
$any    = "EXISTS (SELECT 1 FROM job_requests r WHERE r.customer_id = c.customer_id)";
$c = $pdo->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN $active THEN 1 ELSE 0 END), 0) AS active,
            COALESCE(SUM(CASE WHEN NOT $active AND $any THEN 1 ELSE 0 END), 0) AS past,
            COALESCE(SUM(CASE WHEN NOT $any THEN 1 ELSE 0 END), 0) AS none
     FROM customers c"
)->fetch();
$customers       = (int) $c['total'];
$customersActive = (int) $c['active'];
$customersPast   = (int) $c['past'];
$customersNone   = (int) $c['none'];

$byStatus = $pdo->query('SELECT status, COUNT(*) FROM job_requests GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$reqTotal = array_sum(array_map('intval', $byStatus));

$pay = $pdo->query(
    "SELECT COALESCE(SUM(CASE WHEN payment_status = 'paid'   THEN amount ELSE 0 END), 0) AS paid,
            COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN amount ELSE 0 END), 0) AS unpaid
     FROM payments"
)->fetch();

$pendingWorkers = $pdo->query(
    'SELECT w.worker_id, w.contact_number, u.first_name, u.last_name, u.username
     FROM workers w JOIN users u ON u.user_id = w.user_id
     WHERE w.is_verified = 0 ORDER BY w.worker_id DESC LIMIT 5'
)->fetchAll();

$recent = $pdo->query(
    "SELECT r.request_id, r.status, r.date_request, c.category_name,
            cu.first_name AS c_first, cu.last_name AS c_last,
            r.workers_needed
     FROM job_requests r
     JOIN service_categories c ON c.category_id = r.category_id
     JOIN customers cs ON cs.customer_id = r.customer_id
     JOIN users cu ON cu.user_id = cs.user_id
     ORDER BY r.request_id DESC LIMIT 5"
)->fetchAll();
$team = load_request_workers($pdo, array_column($recent, 'request_id'));

$pageTitle = 'Admin dashboard';
$activeNav = 'dashboard';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/subnav.php';
?>
<main class="container panel-page">
  <div class="page-head">
    <h1>Admin overview</h1>
  </div>

  <?php if ($workersPending > 0): ?>
    <div class="alert alert-error">
      <strong><?= $workersPending ?></strong> worker<?= $workersPending > 1 ? 's are' : ' is' ?> waiting for verification.
      <a href="<?= e(url('admin/workers.php?status=pending')) ?>">Review now</a>
    </div>
  <?php endif; ?>

  <div class="stats stats-top">
    <a class="stat stat-link" href="<?= e(url('admin/customers.php')) ?>"><div class="stat-num"><?= $customers ?></div><div class="stat-label">Customers</div></a>
    <a class="stat stat-link" href="<?= e(url('admin/workers.php')) ?>"><div class="stat-num"><?= $workersTotal ?></div><div class="stat-label">Workers</div></a>
    <a class="stat stat-link" href="<?= e(url('admin/requests.php')) ?>"><div class="stat-num"><?= $reqTotal ?></div><div class="stat-label">Requests</div></a>
    <div class="stat"><div class="stat-num"><?= e(money($pay['paid'])) ?></div><div class="stat-label">Collected</div></div>
    <div class="stat <?= (float) $pay['unpaid'] > 0 ? 'stat-warn' : '' ?>"><div class="stat-num"><?= e(money($pay['unpaid'])) ?></div><div class="stat-label">Awaiting payment</div></div>
  </div>

  <h2 class="section-title">People</h2>
  <div class="charts">
    <?= pie_card('Workers', [
        ['label' => 'Verified, on a job',      'value' => $workersBusy,    'color' => '#1f5c4a', 'text' => '#ffffff'],
        ['label' => 'Verified, available',     'value' => $workersFree,    'color' => '#7fb8a3', 'text' => '#12332a'],
        ['label' => 'Awaiting verification',   'value' => $workersPending, 'color' => '#e2a92f', 'text' => '#3a2a00',
         'href' => url('admin/workers.php?status=pending')],
    ]) ?>
    <?= pie_card('Customers', [
        ['label' => 'Request in progress',     'value' => $customersActive, 'color' => '#d4673e', 'text' => '#ffffff'],
        ['label' => 'Past requests only',      'value' => $customersPast,   'color' => '#eeb08f', 'text' => '#4a1d0a'],
        ['label' => 'No requests yet',         'value' => $customersNone,   'color' => '#b9b2a4', 'text' => '#2b2a26'],
    ]) ?>
  </div>

  <h2 class="section-title">Requests</h2>
  <div class="stats">
    <div class="stat"><div class="stat-num"><?= (int) ($byStatus['pending'] ?? 0) ?></div><div class="stat-label">Pending</div></div>
    <div class="stat"><div class="stat-num"><?= (int) ($byStatus['accepted'] ?? 0) ?></div><div class="stat-label">In progress</div></div>
    <div class="stat"><div class="stat-num"><?= (int) ($byStatus['completed'] ?? 0) ?></div><div class="stat-label">Completed</div></div>
    <div class="stat"><div class="stat-num"><?= (int) ($byStatus['cancelled'] ?? 0) ?></div><div class="stat-label">Cancelled</div></div>
  </div>

  <?php if ((float) $pay['unpaid'] > 0): ?>
    <form method="post" class="reminder-bar" onsubmit="return confirm('Email every customer whose completed job is still unpaid?');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="send_reminders">
      <button class="btn btn-outline btn-sm" type="submit">Send payment reminders</button>
      <span class="muted"><?= e(money($pay['unpaid'])) ?> is waiting to be paid. Emails customers with unpaid completed jobs (once a day at most per job).</span>
    </form>
  <?php endif; ?>

  <div class="dash-cols">
    <section>
      <div class="page-head">
        <h2 class="section-title" style="margin:0">Workers to verify</h2>
        <a class="btn btn-outline btn-sm" href="<?= e(url('admin/workers.php?status=pending')) ?>">See all</a>
      </div>
      <?php if (!$pendingWorkers): ?>
        <div class="empty">No workers are waiting for verification.</div>
      <?php else: ?>
        <div class="req-list">
          <?php foreach ($pendingWorkers as $p): ?>
            <div class="req">
              <div class="req-head">
                <span class="req-title"><?= e($p['first_name'] . ' ' . $p['last_name']) ?><small>@<?= e($p['username']) ?></small></span>
                <?= status_chip('pending') ?>
              </div>
              <div class="req-meta"><span><?= e($p['contact_number']) ?></span></div>
              <div class="req-actions"><a class="btn btn-outline btn-sm" href="<?= e(url('admin/worker.php?id=' . (int) $p['worker_id'])) ?>">Review documents</a></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section>
      <div class="page-head">
        <h2 class="section-title" style="margin:0">Latest requests</h2>
        <a class="btn btn-outline btn-sm" href="<?= e(url('admin/requests.php')) ?>">See all</a>
      </div>
      <?php if (!$recent): ?>
        <div class="empty">No requests yet.</div>
      <?php else: ?>
        <div class="req-list">
          <?php foreach ($recent as $r): ?>
            <div class="req">
              <div class="req-head">
                <span class="req-title"><?= e($r['category_name']) ?><small>#<?= (int) $r['request_id'] ?></small></span>
                <?= status_chip($r['status']) ?>
              </div>
              <div class="req-meta">
                <span>Customer: <strong><?= e($r['c_first'] . ' ' . $r['c_last']) ?></strong></span>
                <?php $names = worker_names($team[(int) $r['request_id']] ?? []); ?>
                <span>Workers: <?= $names !== '' ? e($names) : 'none yet' ?> (needs <?= (int) $r['workers_needed'] ?>)</span>
                <span>Requested <?= e(fmt_dt($r['date_request'])) ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin');

$pdo = db();

$customers = (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();

$w = $pdo->query(
    'SELECT COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END), 0) AS verified
     FROM workers'
)->fetch();
$workersTotal    = (int) $w['total'];
$workersVerified = (int) $w['verified'];
$workersPending  = $workersTotal - $workersVerified;

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
            wu.first_name AS w_first, wu.last_name AS w_last
     FROM job_requests r
     JOIN service_categories c ON c.category_id = r.category_id
     JOIN customers cs ON cs.customer_id = r.customer_id
     JOIN users cu ON cu.user_id = cs.user_id
     LEFT JOIN workers w ON w.worker_id = r.worker_id
     LEFT JOIN users wu ON wu.user_id = w.user_id
     ORDER BY r.request_id DESC LIMIT 5"
)->fetchAll();

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

  <p class="stat-group">People</p>
  <div class="stats">
    <div class="stat"><div class="stat-num"><?= $customers ?></div><div class="stat-label">Customers</div></div>
    <div class="stat"><div class="stat-num"><?= $workersTotal ?></div><div class="stat-label">Workers</div></div>
    <div class="stat"><div class="stat-num"><?= $workersVerified ?></div><div class="stat-label">Verified workers</div></div>
    <div class="stat"><div class="stat-num"><?= $workersPending ?></div><div class="stat-label">Awaiting verification</div></div>
  </div>

  <p class="stat-group">Requests</p>
  <div class="stats">
    <div class="stat"><div class="stat-num"><?= $reqTotal ?></div><div class="stat-label">Total requests</div></div>
    <div class="stat"><div class="stat-num"><?= (int) ($byStatus['pending'] ?? 0) ?></div><div class="stat-label">Pending</div></div>
    <div class="stat"><div class="stat-num"><?= (int) ($byStatus['accepted'] ?? 0) ?></div><div class="stat-label">In progress</div></div>
    <div class="stat"><div class="stat-num"><?= (int) ($byStatus['completed'] ?? 0) ?></div><div class="stat-label">Completed</div></div>
    <div class="stat"><div class="stat-num"><?= (int) ($byStatus['cancelled'] ?? 0) ?></div><div class="stat-label">Cancelled</div></div>
  </div>

  <p class="stat-group">Payments</p>
  <div class="stats">
    <div class="stat"><div class="stat-num"><?= e(money($pay['paid'])) ?></div><div class="stat-label">Paid</div></div>
    <div class="stat"><div class="stat-num"><?= e(money($pay['unpaid'])) ?></div><div class="stat-label">Awaiting payment</div></div>
  </div>

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

  <div class="page-head" style="margin-top:28px">
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
            <span>Worker: <?= $r['w_first'] ? e($r['w_first'] . ' ' . $r['w_last']) : 'not assigned yet' ?></span>
            <span>Requested <?= e(fmt_dt($r['date_request'])) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/init.php';
require_role('customer');

$cid = (int) $_SESSION['profile_id'];
$pdo = db();

$s = $pdo->prepare(
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN status = 'pending'   THEN 1 ELSE 0 END), 0) AS pending,
        COALESCE(SUM(CASE WHEN status = 'accepted'  THEN 1 ELSE 0 END), 0) AS accepted,
        COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) AS completed
     FROM job_requests WHERE customer_id = ?"
);
$s->execute([$cid]);
$stats = $s->fetch();

$s = $pdo->prepare(
    "SELECT COUNT(*) FROM payments p
     JOIN job_requests r ON r.request_id = p.request_id
     WHERE r.customer_id = ? AND p.payment_status = 'unpaid'"
);
$s->execute([$cid]);
$unpaid = (int) $s->fetchColumn();

$s = $pdo->prepare(
    "SELECT r.request_id, r.status, r.date_request, c.category_name,
            u.first_name AS w_first, u.last_name AS w_last
     FROM job_requests r
     JOIN service_categories c ON c.category_id = r.category_id
     LEFT JOIN workers w ON w.worker_id = r.worker_id
     LEFT JOIN users u ON u.user_id = w.user_id
     WHERE r.customer_id = ?
     ORDER BY r.request_id DESC LIMIT 5"
);
$s->execute([$cid]);
$recent = $s->fetchAll();

$pageTitle = 'Customer dashboard';
$activeNav = 'dashboard';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/subnav.php';
?>
<main class="container panel-page">
  <div class="page-head">
    <h1>Hello, <?= e($_SESSION['first_name']) ?> 👋</h1>
    <a class="btn btn-primary" href="<?= e(url('customer/new_request.php')) ?>">+ New request</a>
  </div>

  <?php if ($unpaid > 0): ?>
    <div class="alert alert-error">
      You have <strong><?= $unpaid ?></strong> completed job<?= $unpaid > 1 ? 's' : '' ?> waiting for payment.
      <a href="<?= e(url('customer/requests.php?status=completed')) ?>">Pay now</a>
    </div>
  <?php endif; ?>

  <div class="stats">
    <div class="stat"><div class="stat-num"><?= (int) $stats['total'] ?></div><div class="stat-label">Total requests</div></div>
    <div class="stat"><div class="stat-num"><?= (int) $stats['pending'] ?></div><div class="stat-label">Waiting for a worker</div></div>
    <div class="stat"><div class="stat-num"><?= (int) $stats['accepted'] ?></div><div class="stat-label">In progress</div></div>
    <div class="stat"><div class="stat-num"><?= (int) $stats['completed'] ?></div><div class="stat-label">Completed</div></div>
  </div>

  <h2 class="section-title">Recent requests</h2>
  <?php if (!$recent): ?>
    <div class="empty">
      <p>You haven't posted any requests yet.</p>
      <a class="btn btn-primary" href="<?= e(url('customer/new_request.php')) ?>">Post your first request</a>
    </div>
  <?php else: ?>
    <div class="req-list">
      <?php foreach ($recent as $r): ?>
        <div class="req">
          <div class="req-head">
            <span class="req-title"><?= e($r['category_name']) ?><small>#<?= (int) $r['request_id'] ?></small></span>
            <?= status_chip($r['status']) ?>
          </div>
          <div class="req-meta">
            <span>Requested <?= e(fmt_dt($r['date_request'])) ?></span>
            <?php if ($r['w_first']): ?>
              <span>Worker: <strong><?= e($r['w_first'] . ' ' . $r['w_last']) ?></strong></span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <p><a href="<?= e(url('customer/requests.php')) ?>">View all requests →</a></p>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

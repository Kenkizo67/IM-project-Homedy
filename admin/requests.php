<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin');

$pdo = db();
$statuses = ['all', 'pending', 'accepted', 'completed', 'cancelled'];
$status = in_array($_GET['status'] ?? 'all', $statuses, true) ? ($_GET['status'] ?? 'all') : 'all';
$q = trim((string) ($_GET['q'] ?? ''));

// ---- Actions --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
    } elseif (($_POST['action'] ?? '') === 'cancel') {
        $stmt = $pdo->prepare(
            "UPDATE job_requests SET status = 'cancelled'
             WHERE request_id = ? AND status IN ('pending', 'accepted')"
        );
        $stmt->execute([(int) ($_POST['request_id'] ?? 0)]);
        $stmt->rowCount() === 1
            ? flash('success', 'Request cancelled.')
            : flash('error', 'That request can no longer be cancelled.');
    }
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    redirect('admin/requests.php' . ($qs !== '' ? '?' . $qs : ''));
}

// ---- List -----------------------------------------------------------------
$sql = "SELECT r.request_id, r.description, r.price, r.status, r.date_request, r.date_accepted, r.date_completed,
               c.category_name,
               cu.first_name AS c_first, cu.last_name AS c_last, cu.username AS c_user, cs.contact_number AS c_contact,
               wu.first_name AS w_first, wu.last_name AS w_last, wu.username AS w_user,
               p.amount, p.payment_status, p.payment_method, p.payment_date,
               rv.rating, rv.quality_rating, rv.punctuality_rating, rv.professionalism_rating, rv.efficiency_rating, rv.remarks
        FROM job_requests r
        JOIN service_categories c ON c.category_id = r.category_id
        JOIN customers cs ON cs.customer_id = r.customer_id
        JOIN users cu ON cu.user_id = cs.user_id
        LEFT JOIN workers w ON w.worker_id = r.worker_id
        LEFT JOIN users wu ON wu.user_id = w.user_id
        LEFT JOIN payments p ON p.request_id = r.request_id
        LEFT JOIN reviews rv ON rv.request_id = r.request_id
        WHERE 1 = 1";
$params = [];
if ($status !== 'all') {
    $sql .= ' AND r.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $sql .= ' AND (r.description LIKE ? OR c.category_name LIKE ? OR cu.first_name LIKE ? OR cu.last_name LIKE ? OR cu.username LIKE ?
                   OR wu.first_name LIKE ? OR wu.last_name LIKE ? OR wu.username LIKE ?)';
    $like = '%' . $q . '%';
    for ($i = 0; $i < 8; $i++) {
        $params[] = $like;
    }
}
$sql .= ' ORDER BY r.request_id DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pageTitle = 'Requests';
$activeNav = 'requests';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/subnav.php';
?>
<main class="container panel-page">
  <div class="page-head"><h1>All requests</h1></div>

  <form class="search-form" method="get">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search description, service, customer or worker">
    <button class="btn btn-primary btn-sm" type="submit">Search</button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/requests.php?status=' . $status)) ?>">Clear</a><?php endif; ?>
  </form>

  <div class="filters">
    <?php foreach ($statuses as $st): ?>
      <a href="<?= e(url('admin/requests.php?status=' . $st . ($q !== '' ? '&q=' . urlencode($q) : ''))) ?>"
         class="<?= $status === $st ? 'active' : '' ?>"><?= e(ucfirst($st)) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$requests): ?>
    <div class="empty">No requests found.</div>
  <?php else: ?>
    <div class="req-list">
      <?php foreach ($requests as $r): ?>
        <div class="req">
          <div class="req-head">
            <span class="req-title"><?= e($r['category_name']) ?><small>#<?= (int) $r['request_id'] ?></small></span>
            <?= status_chip($r['status']) ?>
          </div>
          <p class="req-desc"><?= e($r['description']) ?></p>
          <div class="req-meta">
            <span>Customer: <strong><?= e($r['c_first'] . ' ' . $r['c_last']) ?></strong> (@<?= e($r['c_user']) ?>) · <?= e($r['c_contact']) ?></span>
            <span>Worker:
              <?php if ($r['w_first']): ?>
                <strong><?= e($r['w_first'] . ' ' . $r['w_last']) ?></strong> (@<?= e($r['w_user']) ?>)
              <?php else: ?>
                not assigned yet
              <?php endif; ?>
            </span>
            <span>Price set by customer: <span class="price-tag"><?= e(money($r['price'])) ?></span></span>
            <span>Requested: <?= e(fmt_dt($r['date_request'])) ?></span>
            <?php if ($r['date_accepted']): ?><span>Accepted: <?= e(fmt_dt($r['date_accepted'])) ?></span><?php endif; ?>
            <?php if ($r['date_completed']): ?><span>Completed: <?= e(fmt_dt($r['date_completed'])) ?></span><?php endif; ?>
            <?php if ($r['amount'] !== null): ?>
              <span>Amount: <strong><?= e(money($r['amount'])) ?></strong> <?= status_chip($r['payment_status']) ?>
                <?php if ($r['payment_status'] === 'paid'): ?>· <?= e($r['payment_method']) ?>, <?= e(fmt_dt($r['payment_date'])) ?><?php endif; ?>
              </span>
            <?php endif; ?>
          </div>
          <?= review_box($r, 'Customer review') ?>
          <?php if (in_array($r['status'], ['pending', 'accepted'], true)): ?>
            <div class="req-actions">
              <form method="post" onsubmit="return confirm('Cancel this request?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit">Cancel request</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

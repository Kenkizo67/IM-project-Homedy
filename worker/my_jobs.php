<?php
require_once __DIR__ . '/../includes/init.php';
require_role('worker');

$wid = (int) $_SESSION['profile_id'];
$pdo = db();

$tabs = ['accepted' => 'Active', 'completed' => 'Completed', 'all' => 'All'];
$filter = array_key_exists($_GET['status'] ?? 'accepted', $tabs) ? ($_GET['status'] ?? 'accepted') : 'accepted';

// ---- Mark a job completed (payment = the price the customer set) ---------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = (int) ($_POST['request_id'] ?? 0);

    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "UPDATE job_requests SET status = 'completed', date_completed = ?
                 WHERE request_id = ? AND worker_id = ? AND status = 'accepted' AND price > 0"
            );
            $stmt->execute([now_sql(), $requestId, $wid]);

            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                flash('error', 'That job cannot be completed (it may not be yours, or the customer has not set a price).');
            } else {
                $pdo->prepare(
                    "INSERT INTO payments (request_id, amount, payment_status)
                     SELECT request_id, price, 'unpaid' FROM job_requests WHERE request_id = ?"
                )->execute([$requestId]);
                $pdo->commit();
                flash('success', 'Job marked as completed. The customer can now pay the agreed price.');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'Something went wrong. Please try again.');
        }
    }
    redirect('worker/my_jobs.php' . ($filter !== 'accepted' ? '?status=' . $filter : ''));
}

// ---- List ----------------------------------------------------------------
$sql = "SELECT r.request_id, r.description, r.price, r.status, r.date_request, r.date_accepted, r.date_completed,
               c.category_name,
               u.first_name, u.last_name, cu.address, cu.contact_number,
               p.payment_status, p.payment_method, p.payment_date,
               rv.rating, rv.quality_rating, rv.punctuality_rating, rv.professionalism_rating, rv.efficiency_rating, rv.remarks
        FROM job_requests r
        JOIN service_categories c ON c.category_id = r.category_id
        JOIN customers cu ON cu.customer_id = r.customer_id
        JOIN users u ON u.user_id = cu.user_id
        LEFT JOIN payments p ON p.request_id = r.request_id
        LEFT JOIN reviews rv ON rv.request_id = r.request_id
        WHERE r.worker_id = ?";
$params = [$wid];
if ($filter !== 'all') {
    $sql .= ' AND r.status = ?';
    $params[] = $filter;
}
$sql .= ' ORDER BY r.request_id DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$jobs = $stmt->fetchAll();

$pageTitle = 'My jobs';
$activeNav = 'jobs';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/subnav.php';
?>
<main class="container panel-page">
  <div class="page-head"><h1>My jobs</h1></div>

  <div class="filters">
    <?php foreach ($tabs as $key => $label): ?>
      <a href="<?= e(url('worker/my_jobs.php' . ($key === 'accepted' ? '' : '?status=' . $key))) ?>"
         class="<?= $filter === $key ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$jobs): ?>
    <div class="empty">
      Nothing here yet.
      <?php if ($filter === 'accepted'): ?><br><a href="<?= e(url('worker/open_jobs.php')) ?>">Browse open jobs</a><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="req-list">
      <?php foreach ($jobs as $r): ?>
        <div class="req">
          <div class="req-head">
            <span class="req-title"><?= e($r['category_name']) ?><small>#<?= (int) $r['request_id'] ?></small></span>
            <?= status_chip($r['status']) ?>
          </div>
          <p class="req-desc"><?= e($r['description']) ?></p>
          <div class="req-meta">
            <span>Price set by customer: <span class="price-tag"><?= e(money($r['price'])) ?></span>
              <?php if ($r['payment_status']): ?><?= status_chip($r['payment_status']) ?><?php endif; ?>
              <?php if ($r['payment_status'] === 'paid'): ?>· <?= e($r['payment_method']) ?>, <?= e(fmt_dt($r['payment_date'])) ?><?php endif; ?>
            </span>
            <span>Customer: <strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong> · <?= e($r['contact_number']) ?></span>
            <span>Address: <?= e($r['address']) ?></span>
            <span>Accepted: <?= e(fmt_dt($r['date_accepted'])) ?></span>
            <?php if ($r['date_completed']): ?>
              <span>Completed: <?= e(fmt_dt($r['date_completed'])) ?></span>
            <?php endif; ?>
          </div>

          <?= review_box($r, 'Customer review') ?>

          <?php if ($r['status'] === 'accepted'): ?>
            <div class="req-actions">
              <form method="post" onsubmit="return confirm('Mark this job as completed? The customer will be asked to pay <?= e(money($r['price'])) ?>.');">
                <?= csrf_field() ?>
                <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
                <button class="btn btn-primary btn-sm" type="submit">Mark completed</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/init.php';
require_role('worker');

$wid = (int) $_SESSION['profile_id'];
$pdo = db();

$tabs = ['accepted' => 'Active', 'waiting' => 'Waiting for customer', 'completed' => 'Completed', 'all' => 'All'];
$filter = array_key_exists($_GET['status'] ?? 'accepted', $tabs) ? ($_GET['status'] ?? 'accepted') : 'accepted';

// ---- Mark my part of the job as done ------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = (int) ($_POST['request_id'] ?? 0);

    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
    } else {
        try {
            $res = mark_worker_done($pdo, $requestId, $wid);
            match ($res) {
                'completed' => flash('success', 'Marked as done. You were the last one, so the job is completed and the customer can pay.'),
                'ok'        => flash('success', 'Marked as done. The job completes when everyone has finished.'),
                default     => flash('error', 'That job cannot be completed (it may not be yours, or it is no longer active).'),
            };
        } catch (Throwable $e) {
            error_log('Mark done failed: ' . $e->getMessage());
            flash('error', 'Something went wrong. Please try again.');
        }
    }
    redirect('worker/my_jobs.php' . ($filter !== 'accepted' ? '?status=' . $filter : ''));
}

// ---- List ----------------------------------------------------------------
$sql = "SELECT r.request_id, r.description, r.price, r.workers_needed, r.status AS request_status,
               r.date_request, r.date_accepted, r.date_completed,
               rw.status AS my_status, rw.date_done,
               c.category_name,
               u.first_name, u.last_name, cu.address, cu.contact_number,
               p.payment_status, p.payment_method, p.payment_date,
               rv.rating, rv.quality_rating, rv.punctuality_rating, rv.professionalism_rating, rv.efficiency_rating, rv.remarks
        FROM request_workers rw
        JOIN job_requests r ON r.request_id = rw.request_id
        JOIN service_categories c ON c.category_id = r.category_id
        JOIN customers cu ON cu.customer_id = r.customer_id
        JOIN users u ON u.user_id = cu.user_id
        LEFT JOIN payments p ON p.request_id = r.request_id
        LEFT JOIN reviews rv ON rv.request_id = r.request_id AND rv.worker_id = rw.worker_id
        WHERE rw.worker_id = ? AND rw.status IN ('applied', 'selected', 'done', 'rejected')";
$params = [$wid];
if ($filter === 'accepted')  { $sql .= " AND rw.status = 'selected' AND r.status = 'accepted'"; }
if ($filter === 'waiting')   { $sql .= " AND rw.status = 'applied' AND r.status IN ('pending', 'accepted')"; }
if ($filter === 'completed') { $sql .= " AND rw.status = 'done'"; }
$sql .= ' ORDER BY r.request_id DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$jobs = $stmt->fetchAll();
$team = load_request_workers($pdo, array_column($jobs, 'request_id'));

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
      <?php if ($filter === 'accepted' || $filter === 'waiting'): ?><br><a href="<?= e(url('worker/open_jobs.php')) ?>">Browse open jobs</a><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="req-list">
      <?php foreach ($jobs as $r):
        $mine = $r['my_status'];
        $show = in_array($mine, ['selected', 'done'], true);   // the customer's details are for accepted workers only
        $mates = $show ? worker_names(array_filter($team[(int) $r['request_id']] ?? [], fn($w) => (int) $w['worker_id'] !== $wid)) : '';
      ?>
        <div class="req">
          <div class="req-head">
            <span class="req-title"><?= e($r['category_name']) ?><small>#<?= (int) $r['request_id'] ?></small></span>
            <?= status_chip($mine) ?>
          </div>
          <p class="req-desc"><?= e($r['description']) ?></p>
          <div class="req-meta">
            <span>Your pay: <span class="price-tag"><?= e(money($r['price'])) ?></span>
              <?php if ($show && $r['payment_status']): ?><?= status_chip($r['payment_status']) ?><?php endif; ?>
              <?php if ($show && $r['payment_status'] === 'paid'): ?>· <?= e($r['payment_method']) ?>, <?= e(fmt_dt($r['payment_date'])) ?><?php endif; ?>
            </span>
            <?php if ($show): ?>
              <span>Customer: <strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong> · <?= e($r['contact_number']) ?></span>
              <span>Address: <?= e($r['address']) ?></span>
              <?php if ($mates !== ''): ?><span>Also on this job: <?= e($mates) ?></span><?php endif; ?>
            <?php else: ?>
              <span>From: <strong><?= e($r['first_name']) ?></strong> (details are shared if the customer accepts you)</span>
            <?php endif; ?>
            <?php if ($mine === 'done' && $r['date_done']): ?>
              <span>You finished: <?= e(fmt_dt($r['date_done'])) ?></span>
            <?php endif; ?>
          </div>

          <?= review_box($r, 'Customer review') ?>

          <?php if ($mine === 'selected' && $r['request_status'] === 'accepted'): ?>
            <div class="req-actions">
              <form method="post" onsubmit="return confirm('Mark your part of this job as done?');">
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

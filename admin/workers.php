<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin');

$pdo = db();
$filters = ['all' => 'All', 'pending' => 'Pending', 'verified' => 'Verified'];
$status  = array_key_exists($_GET['status'] ?? 'all', $filters) ? ($_GET['status'] ?? 'all') : 'all';
$q       = trim((string) ($_GET['q'] ?? ''));

// ---- Actions --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
    } elseif ($action === 'verify' || $action === 'unverify') {
        $stmt = $pdo->prepare('UPDATE workers SET is_verified = ? WHERE worker_id = ?');
        $stmt->execute([$action === 'verify' ? 1 : 0, (int) ($_POST['worker_id'] ?? 0)]);
        flash('success', $action === 'verify' ? 'Worker verified. They can now accept jobs.' : 'Worker verification removed.');
    } elseif ($action === 'reset_password') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $chk = $pdo->prepare('SELECT u.username FROM users u JOIN workers w ON w.user_id = u.user_id WHERE u.user_id = ?');
        $chk->execute([$uid]);
        $username = $chk->fetchColumn();
        $new = $username ? admin_reset_password($pdo, $uid, 'worker') : null;
        $new
            ? flash('success', 'New temporary password for @' . $username . ': ' . $new . ' — share it with the worker and ask them to change it.')
            : flash('error', 'Could not reset that password.');
    }
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    redirect('admin/workers.php' . ($qs !== '' ? '?' . $qs : ''));
}

// ---- List -----------------------------------------------------------------
$sql = "SELECT w.worker_id, w.address, w.contact_number, w.is_verified, w.date_registered,
               (SELECT COUNT(*) FROM worker_documents d WHERE d.worker_id = w.worker_id) AS doc_count,
               u.user_id, u.username, u.first_name, u.last_name, u.email,
               (SELECT COUNT(*) FROM job_requests r WHERE r.worker_id = w.worker_id AND r.status = 'accepted')  AS active_jobs,
               (SELECT COUNT(*) FROM job_requests r WHERE r.worker_id = w.worker_id AND r.status = 'completed') AS done_jobs,
               (SELECT AVG(rv.rating) FROM reviews rv WHERE rv.worker_id = w.worker_id) AS avg_rating,
               (SELECT COUNT(*) FROM reviews rv WHERE rv.worker_id = w.worker_id) AS review_count,
               (SELECT AVG(rv.quality_rating) FROM reviews rv WHERE rv.worker_id = w.worker_id) AS quality_rating,
               (SELECT AVG(rv.punctuality_rating) FROM reviews rv WHERE rv.worker_id = w.worker_id) AS punctuality_rating,
               (SELECT AVG(rv.professionalism_rating) FROM reviews rv WHERE rv.worker_id = w.worker_id) AS professionalism_rating,
               (SELECT AVG(rv.efficiency_rating) FROM reviews rv WHERE rv.worker_id = w.worker_id) AS efficiency_rating
        FROM workers w JOIN users u ON u.user_id = w.user_id WHERE 1 = 1";
$params = [];
if ($status === 'pending')  { $sql .= ' AND w.is_verified = 0'; }
if ($status === 'verified') { $sql .= ' AND w.is_verified = 1'; }
if ($q !== '') {
    $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR w.contact_number LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$sql .= ' ORDER BY w.is_verified ASC, w.worker_id DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$workers = $stmt->fetchAll();

$skills = [];
foreach ($pdo->query(
    'SELECT ws.worker_id, c.category_name FROM worker_skills ws
     JOIN service_categories c ON c.category_id = ws.category_id ORDER BY c.category_name'
)->fetchAll() as $row) {
    $skills[(int) $row['worker_id']][] = $row['category_name'];
}

$pageTitle = 'Workers';
$activeNav = 'workers';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/subnav.php';
?>
<main class="container panel-page">
  <div class="page-head"><h1>Workers</h1></div>

  <form class="search-form" method="get">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, username, email or number">
    <button class="btn btn-primary btn-sm" type="submit">Search</button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/workers.php?status=' . $status)) ?>">Clear</a><?php endif; ?>
  </form>

  <div class="filters">
    <?php foreach ($filters as $key => $label): ?>
      <a href="<?= e(url('admin/workers.php?status=' . $key . ($q !== '' ? '&q=' . urlencode($q) : ''))) ?>"
         class="<?= $status === $key ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$workers): ?>
    <div class="empty">No workers found.</div>
  <?php else: ?>
    <div class="req-list">
      <?php foreach ($workers as $w): ?>
        <div class="req">
          <div class="req-head">
            <span class="req-title"><?= e($w['first_name'] . ' ' . $w['last_name']) ?><small>@<?= e($w['username']) ?></small></span>
            <?= $w['is_verified'] ? '<span class="chip chip-accepted">Verified</span>' : '<span class="chip chip-pending">Pending</span>' ?>
          </div>
          <div class="req-meta">
            <span>Email: <?= e($w['email']) ?></span>
            <span>Contact: <?= e($w['contact_number']) ?></span>
            <span>Address: <?= e($w['address']) ?></span>
            <span>Registered: <?= e(fmt_dt($w['date_registered'])) ?> · Documents: <strong><?= (int) $w['doc_count'] ?></strong></span>
            <span>Active jobs: <strong><?= (int) $w['active_jobs'] ?></strong> · Completed: <strong><?= (int) $w['done_jobs'] ?></strong></span>
            <span>Rating: <?= stars((int) $w['review_count'] > 0 ? (float) $w['avg_rating'] : null) ?>
              <?php if ((int) $w['review_count'] > 0): ?>(<?= e(number_format((float) $w['avg_rating'], 1)) ?> from <?= (int) $w['review_count'] ?> review<?= (int) $w['review_count'] === 1 ? '' : 's' ?>) overall<?php endif; ?>
            </span>
            <?= dimension_averages($w) ?>
          </div>
          <?php if (!empty($skills[(int) $w['worker_id']])): ?>
            <div class="tags">
              <?php foreach ($skills[(int) $w['worker_id']] as $s): ?><span class="tag"><?= e($s) ?></span><?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="req-actions">
            <a class="btn btn-outline btn-sm" href="<?= e(url('admin/worker.php?id=' . (int) $w['worker_id'])) ?>">View details &amp; documents</a>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="worker_id" value="<?= (int) $w['worker_id'] ?>">
              <?php if ($w['is_verified']): ?>
                <input type="hidden" name="action" value="unverify">
                <button class="btn btn-danger btn-sm" type="submit" onclick="return confirm('Remove verification? They will not be able to accept new jobs.');">Remove verification</button>
              <?php else: ?>
                <input type="hidden" name="action" value="verify">
                <button class="btn btn-primary btn-sm" type="submit" <?= (int) $w['doc_count'] === 0 ? "onclick=\"return confirm('No documents submitted. Verify anyway?');\"" : '' ?>>Verify worker</button>
              <?php endif; ?>
            </form>
            <form method="post" onsubmit="return confirm('Generate a new temporary password for this worker?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reset_password">
              <input type="hidden" name="user_id" value="<?= (int) $w['user_id'] ?>">
              <button class="btn btn-warn btn-sm" type="submit">Reset password</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

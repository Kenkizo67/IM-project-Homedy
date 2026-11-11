<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin');

$pdo = db();
$q = trim((string) ($_GET['q'] ?? ''));

// ---- Actions --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
    } elseif (($_POST['action'] ?? '') === 'reset_password') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $chk = $pdo->prepare('SELECT u.username FROM users u JOIN customers c ON c.user_id = u.user_id WHERE u.user_id = ?');
        $chk->execute([$uid]);
        $username = $chk->fetchColumn();
        $new = $username ? admin_reset_password($pdo, $uid, 'customer') : null;
        $new
            ? flash('success', 'New temporary password for @' . $username . ': ' . $new . ' — share it with the customer and ask them to change it.')
            : flash('error', 'Could not reset that password.');
    }
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    redirect('admin/customers.php' . ($qs !== '' ? '?' . $qs : ''));
}

// ---- List -----------------------------------------------------------------
$sql = "SELECT c.customer_id, c.address, c.contact_number,
               u.user_id, u.username, u.first_name, u.last_name, u.email,
               (SELECT COUNT(*) FROM job_requests r WHERE r.customer_id = c.customer_id) AS total_req,
               (SELECT COUNT(*) FROM job_requests r WHERE r.customer_id = c.customer_id AND r.status IN ('pending','accepted')) AS open_req,
               (SELECT COUNT(*) FROM job_requests r WHERE r.customer_id = c.customer_id AND r.status = 'completed') AS done_req
        FROM customers c JOIN users u ON u.user_id = c.user_id WHERE 1 = 1";
$params = [];
if ($q !== '') {
    $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR c.contact_number LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$sql .= ' ORDER BY c.customer_id DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$pageTitle = 'Customers';
$activeNav = 'customers';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/subnav.php';
?>
<main class="container panel-page">
  <div class="page-head"><h1>Customers</h1></div>

  <form class="search-form" method="get">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, username, email or number">
    <button class="btn btn-primary btn-sm" type="submit">Search</button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/customers.php')) ?>">Clear</a><?php endif; ?>
  </form>

  <?php if (!$customers): ?>
    <div class="empty">No customers found.</div>
  <?php else: ?>
    <div class="req-list">
      <?php foreach ($customers as $c): ?>
        <div class="req">
          <div class="req-head">
            <span class="req-title"><?= e($c['first_name'] . ' ' . $c['last_name']) ?><small>@<?= e($c['username']) ?></small></span>
            <span class="chip chip-accepted"><?= (int) $c['total_req'] ?> request<?= (int) $c['total_req'] === 1 ? '' : 's' ?></span>
          </div>
          <div class="req-meta">
            <span>Email: <?= e($c['email']) ?></span>
            <span>Contact: <?= e($c['contact_number']) ?></span>
            <span>Address: <?= e($c['address']) ?></span>
            <span>Open: <strong><?= (int) $c['open_req'] ?></strong> · Completed: <strong><?= (int) $c['done_req'] ?></strong></span>
          </div>
          <div class="req-actions">
            <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/requests.php?q=' . urlencode($c['username']))) ?>">View requests</a>
            <form method="post" onsubmit="return confirm('Generate a new temporary password for this customer?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reset_password">
              <input type="hidden" name="user_id" value="<?= (int) $c['user_id'] ?>">
              <button class="btn btn-warn btn-sm" type="submit">Reset password</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

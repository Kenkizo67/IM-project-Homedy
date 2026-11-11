<?php
require_once __DIR__ . '/../includes/init.php';
require_role('worker');

$wid = (int) $_SESSION['profile_id'];
$pdo = db();

$s = $pdo->prepare('SELECT is_verified FROM workers WHERE worker_id = ?');
$s->execute([$wid]);
$verified = (bool) $s->fetchColumn();

// ---- Accept a job ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
    } elseif (!$verified) {
        flash('error', 'Your profile must be verified before you can accept jobs.');
    } else {
        // One atomic UPDATE: succeeds only if the job is still open AND matches my skills.
        $stmt = $pdo->prepare(
            "UPDATE job_requests
             SET worker_id = ?, status = 'accepted', date_accepted = ?
             WHERE request_id = ? AND status = 'pending' AND worker_id IS NULL
               AND category_id IN (SELECT category_id FROM worker_skills WHERE worker_id = ?)"
        );
        $stmt->execute([$wid, now_sql(), (int) ($_POST['request_id'] ?? 0), $wid]);
        if ($stmt->rowCount() === 1) {
            flash('success', 'Job accepted! You can see the customer\'s details under My jobs.');
            redirect('worker/my_jobs.php');
        }
        flash('error', 'Sorry, that job is no longer available.');
    }
    redirect('worker/open_jobs.php');
}

$open = [];
if ($verified) {
    $s = $pdo->prepare(
        "SELECT r.request_id, r.description, r.price, r.date_request, c.category_name, u.first_name
         FROM job_requests r
         JOIN service_categories c ON c.category_id = r.category_id
         JOIN customers cu ON cu.customer_id = r.customer_id
         JOIN users u ON u.user_id = cu.user_id
         WHERE r.status = 'pending' AND r.worker_id IS NULL
           AND r.category_id IN (SELECT category_id FROM worker_skills WHERE worker_id = ?)
         ORDER BY r.request_id DESC LIMIT 100"
    );
    $s->execute([$wid]);
    $open = $s->fetchAll();
}

$pageTitle = 'Open jobs';
$activeNav = 'open';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/subnav.php';
?>
<main class="container panel-page">
  <div class="page-head"><h1>Open jobs</h1></div>

  <?php if (!$verified): ?>
    <div class="alert alert-error">Your profile is waiting for admin verification. Open jobs will show up here once you're verified.</div>
  <?php elseif (!$open): ?>
    <div class="empty">No open jobs match your skills right now. Check back soon.</div>
  <?php else: ?>
    <p class="muted">Showing pending requests that match your skills. The customer's address is revealed after you accept.</p>
    <div class="req-list">
      <?php foreach ($open as $r): ?>
        <div class="req">
          <div class="req-head">
            <span class="req-title"><?= e($r['category_name']) ?><small>#<?= (int) $r['request_id'] ?></small></span>
            <?= status_chip('pending') ?>
          </div>
          <p class="req-desc"><?= e($r['description']) ?></p>
          <div class="req-meta">
            <span>Price offered: <span class="price-tag"><?= e(money($r['price'])) ?></span> (set by the customer)</span>
            <span>From: <strong><?= e($r['first_name']) ?></strong></span>
            <span>Posted <?= e(fmt_dt($r['date_request'])) ?></span>
          </div>
          <div class="req-actions">
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
              <button class="btn btn-primary btn-sm" type="submit">Accept job for <?= e(money($r['price'])) ?></button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

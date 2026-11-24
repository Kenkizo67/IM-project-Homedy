<?php
require_once __DIR__ . '/../includes/init.php';
require_role('worker');

$wid = (int) $_SESSION['profile_id'];
$pdo = db();

$s = $pdo->prepare('SELECT is_verified FROM workers WHERE worker_id = ?');
$s->execute([$wid]);
$verified = (bool) $s->fetchColumn();

// ---- Actions: apply to an open job, or answer an invitation ---------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $requestId = (int) ($_POST['request_id'] ?? 0);

    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
    } elseif (!$verified) {
        flash('error', 'Your profile must be verified before you can accept jobs.');
    } elseif ($action === 'apply') {
        $res = apply_to_request($pdo, $requestId, $wid);
        match ($res) {
            'ok'     => flash('success', 'Sent! The customer will accept or reject you. Check My jobs for their answer.'),
            'exists' => flash('error', 'You already answered this request.'),
            'full'   => flash('error', 'Sorry, all the places for that job are filled.'),
            default  => flash('error', 'Sorry, that job is no longer available.'),
        };
    } elseif ($action === 'accept_invite') {
        $res = select_worker($pdo, $requestId, $wid, 'invited');
        if ($res === 'ok') {
            flash('success', 'You are on the job! You can see the customer\'s details under My jobs.');
            redirect('worker/my_jobs.php');
        }
        flash('error', $res === 'full' ? 'Sorry, all the places for that job are filled.' : 'Sorry, that invitation is no longer available.');
    } elseif ($action === 'decline_invite') {
        decline_invitation($pdo, $requestId, $wid)
            ? flash('success', 'Invitation declined.')
            : flash('error', 'That invitation is no longer available.');
    }
    redirect('worker/open_jobs.php');
}

$invites = [];
$open = [];
if ($verified) {
    $slotSql = "(SELECT COUNT(*) FROM request_workers x WHERE x.request_id = r.request_id AND x.status IN ('selected', 'done'))";

    // Customers who picked me
    $s = $pdo->prepare(
        "SELECT r.request_id, r.description, r.price, r.workers_needed, r.date_request, c.category_name, u.first_name,
                $slotSql AS taken
         FROM request_workers rw
         JOIN job_requests r ON r.request_id = rw.request_id
         JOIN service_categories c ON c.category_id = r.category_id
         JOIN customers cu ON cu.customer_id = r.customer_id
         JOIN users u ON u.user_id = cu.user_id
         WHERE rw.worker_id = ? AND rw.status = 'invited' AND r.status IN ('pending', 'accepted')
         ORDER BY r.request_id DESC LIMIT 100"
    );
    $s->execute([$wid]);
    $invites = $s->fetchAll();

    // Open to everyone with my skills, and I have not answered yet
    $s = $pdo->prepare(
        "SELECT r.request_id, r.description, r.price, r.workers_needed, r.date_request, c.category_name, u.first_name,
                $slotSql AS taken
         FROM job_requests r
         JOIN service_categories c ON c.category_id = r.category_id
         JOIN customers cu ON cu.customer_id = r.customer_id
         JOIN users u ON u.user_id = cu.user_id
         WHERE r.status IN ('pending', 'accepted') AND r.open_to_all = 1
           AND r.category_id IN (SELECT category_id FROM worker_skills WHERE worker_id = ?)
           AND NOT EXISTS (SELECT 1 FROM request_workers rw WHERE rw.request_id = r.request_id AND rw.worker_id = ?)
           AND $slotSql < r.workers_needed
         ORDER BY r.request_id DESC LIMIT 100"
    );
    $s->execute([$wid, $wid]);
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
  <?php else: ?>

    <?php if ($invites): ?>
      <h2 class="section-title">Customers who picked you</h2>
      <div class="req-list">
        <?php foreach ($invites as $r): ?>
          <div class="req">
            <div class="req-head">
              <span class="req-title"><?= e($r['category_name']) ?><small>#<?= (int) $r['request_id'] ?></small></span>
              <?= status_chip('invited') ?>
            </div>
            <p class="req-desc"><?= e($r['description']) ?></p>
            <div class="req-meta">
              <span>Pay: <span class="price-tag"><?= e(money($r['price'])) ?></span> per worker</span>
              <span>Workers needed: <strong><?= (int) $r['workers_needed'] ?></strong> (<?= (int) $r['taken'] ?> filled)</span>
              <span>From: <strong><?= e($r['first_name']) ?></strong> · Posted <?= e(fmt_dt($r['date_request'])) ?></span>
            </div>
            <div class="req-actions">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="accept_invite">
                <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
                <button class="btn btn-primary btn-sm" type="submit">Accept job for <?= e(money($r['price'])) ?></button>
              </form>
              <form method="post" onsubmit="return confirm('Decline this invitation?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="decline_invite">
                <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit">Decline</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <h2 class="section-title" <?= $invites ? 'style="margin-top:28px"' : '' ?>>Jobs matching your skills</h2>
    <?php if (!$open): ?>
      <div class="empty">No open jobs match your skills right now. Check back soon.</div>
    <?php else: ?>
      <p class="muted">When you accept, the customer decides whether to take you. Their address is revealed only if they accept you.</p>
      <div class="req-list">
        <?php foreach ($open as $r): ?>
          <div class="req">
            <div class="req-head">
              <span class="req-title"><?= e($r['category_name']) ?><small>#<?= (int) $r['request_id'] ?></small></span>
              <?= status_chip('pending') ?>
            </div>
            <p class="req-desc"><?= e($r['description']) ?></p>
            <div class="req-meta">
              <span>Pay: <span class="price-tag"><?= e(money($r['price'])) ?></span> per worker (set by the customer)</span>
              <span>Workers needed: <strong><?= (int) $r['workers_needed'] ?></strong> (<?= (int) $r['taken'] ?> filled)</span>
              <span>From: <strong><?= e($r['first_name']) ?></strong> · Posted <?= e(fmt_dt($r['date_request'])) ?></span>
            </div>
            <div class="req-actions">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="apply">
                <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
                <button class="btn btn-primary btn-sm" type="submit">Accept job for <?= e(money($r['price'])) ?></button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

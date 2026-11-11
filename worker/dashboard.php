<?php
require_once __DIR__ . '/../includes/init.php';
require_role('worker');

$wid = (int) $_SESSION['profile_id'];
$pdo = db();

$s = $pdo->prepare('SELECT is_verified FROM workers WHERE worker_id = ?');
$s->execute([$wid]);
$verified = (bool) $s->fetchColumn();

// My skills
$s = $pdo->prepare(
    'SELECT c.category_name FROM worker_skills ws
     JOIN service_categories c ON c.category_id = ws.category_id
     WHERE ws.worker_id = ? ORDER BY c.category_name'
);
$s->execute([$wid]);
$skills = $s->fetchAll(PDO::FETCH_COLUMN);

// Job counts
$s = $pdo->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN status = 'accepted'  THEN 1 ELSE 0 END), 0) AS active,
        COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) AS completed
     FROM job_requests WHERE worker_id = ?"
);
$s->execute([$wid]);
$jobs = $s->fetch();

// Earnings
$s = $pdo->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN p.payment_status = 'paid'   THEN p.amount ELSE 0 END), 0) AS paid,
        COALESCE(SUM(CASE WHEN p.payment_status = 'unpaid' THEN p.amount ELSE 0 END), 0) AS unpaid
     FROM payments p JOIN job_requests r ON r.request_id = p.request_id
     WHERE r.worker_id = ?"
);
$s->execute([$wid]);
$earn = $s->fetch();

// Ratings from customers
$s = $pdo->prepare(
    'SELECT COUNT(*) AS n, AVG(rating) AS avg_rating,
            AVG(quality_rating) AS quality_rating, AVG(punctuality_rating) AS punctuality_rating,
            AVG(professionalism_rating) AS professionalism_rating, AVG(efficiency_rating) AS efficiency_rating
     FROM reviews WHERE worker_id = ?'
);
$s->execute([$wid]);
$rate = $s->fetch();
$reviewCount = (int) $rate['n'];
$avgRating = $reviewCount > 0 ? (float) $rate['avg_rating'] : null;

// Open jobs matching my skills
$openCount = 0;
if ($verified) {
    $s = $pdo->prepare(
        "SELECT COUNT(*) FROM job_requests r
         WHERE r.status = 'pending' AND r.worker_id IS NULL
           AND r.category_id IN (SELECT category_id FROM worker_skills WHERE worker_id = ?)"
    );
    $s->execute([$wid]);
    $openCount = (int) $s->fetchColumn();
}

// Active jobs
$s = $pdo->prepare(
    "SELECT r.request_id, r.description, r.price, r.date_accepted, c.category_name,
            u.first_name, u.last_name
     FROM job_requests r
     JOIN service_categories c ON c.category_id = r.category_id
     JOIN customers cu ON cu.customer_id = r.customer_id
     JOIN users u ON u.user_id = cu.user_id
     WHERE r.worker_id = ? AND r.status = 'accepted'
     ORDER BY r.request_id DESC LIMIT 5"
);
$s->execute([$wid]);
$active = $s->fetchAll();

$pageTitle = 'Worker dashboard';
$activeNav = 'dashboard';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/subnav.php';
?>
<main class="container panel-page">
  <div class="page-head">
    <h1>Hello, <?= e($_SESSION['first_name']) ?> 👋</h1>
    <?= $verified ? '<span class="chip chip-accepted">Verified</span>' : '<span class="chip chip-pending">Pending verification</span>' ?>
  </div>

  <?php if (!$verified): ?>
    <div class="alert alert-error">
      An admin needs to verify your profile before you can see and accept jobs. Please check back soon.
    </div>
  <?php endif; ?>

  <div class="stats">
    <div class="stat"><div class="stat-num"><?= $openCount ?></div><div class="stat-label">Open jobs for you</div></div>
    <div class="stat"><div class="stat-num"><?= (int) $jobs['active'] ?></div><div class="stat-label">Active jobs</div></div>
    <div class="stat"><div class="stat-num"><?= (int) $jobs['completed'] ?></div><div class="stat-label">Completed jobs</div></div>
    <div class="stat">
      <div class="stat-num"><?= $avgRating !== null ? e(number_format($avgRating, 1)) . ' ★' : '—' ?></div>
      <div class="stat-label">Average rating (<?= $reviewCount ?> review<?= $reviewCount === 1 ? '' : 's' ?>)</div>
    </div>
    <div class="stat"><div class="stat-num"><?= e(money($earn['paid'])) ?></div><div class="stat-label">Paid to you</div></div>
    <div class="stat"><div class="stat-num"><?= e(money($earn['unpaid'])) ?></div><div class="stat-label">Awaiting payment</div></div>
  </div>

  <?php if ($reviewCount > 0): ?>
    <div class="card" style="margin-bottom:20px">
      <strong>Your ratings</strong>
      <ul class="dims">
        <li><span>Overall Satisfaction</span><span><?= stars($avgRating) ?> <?= e(number_format($avgRating, 1)) ?></span></li>
      </ul>
      <?= dimension_averages($rate) ?>
      <p class="note">Based on <?= $reviewCount ?> customer review<?= $reviewCount === 1 ? '' : 's' ?>. Overall Satisfaction is the score shown on your public profile.</p>
    </div>
  <?php endif; ?>

  <p class="muted">Your skills:
    <?= $skills ? e(implode(', ', $skills)) : 'none yet' ?>
  </p>

  <div class="page-head">
    <h2 class="section-title" style="margin:0">Active jobs</h2>
    <?php if ($verified): ?>
      <a class="btn btn-primary btn-sm" href="<?= e(url('worker/open_jobs.php')) ?>">Find jobs</a>
    <?php endif; ?>
  </div>
  <?php if (!$active): ?>
    <div class="empty">No active jobs right now.</div>
  <?php else: ?>
    <div class="req-list">
      <?php foreach ($active as $r): ?>
        <div class="req">
          <div class="req-head">
            <span class="req-title"><?= e($r['category_name']) ?><small>#<?= (int) $r['request_id'] ?></small></span>
            <?= status_chip('accepted') ?>
          </div>
          <p class="req-desc"><?= e($r['description']) ?></p>
          <div class="req-meta">
            <span>Price: <span class="price-tag"><?= e(money($r['price'])) ?></span></span>
            <span>Customer: <strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <p><a href="<?= e(url('worker/my_jobs.php')) ?>">Manage my jobs →</a></p>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

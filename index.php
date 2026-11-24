<?php
require_once __DIR__ . '/includes/init.php';

$categories = [];
try {
    $categories = db()->query('SELECT category_name FROM service_categories ORDER BY category_name')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    // Landing page still works if the database is not set up yet.
}

$recentDone = [];
$recentTeam = [];
try {
    $recentDone = db()->query(
        "SELECT r.request_id, r.description, r.price, r.date_completed, c.category_name,
                (SELECT AVG(rv.rating) FROM reviews rv WHERE rv.request_id = r.request_id) AS rating,
                (SELECT rv.remarks FROM reviews rv WHERE rv.request_id = r.request_id AND rv.remarks IS NOT NULL ORDER BY rv.review_id LIMIT 1) AS remarks
         FROM job_requests r
         JOIN service_categories c ON c.category_id = r.category_id
         WHERE r.status = 'completed'
         ORDER BY r.date_completed DESC, r.request_id DESC LIMIT 3"
    )->fetchAll();
    $recentTeam = load_request_workers(db(), array_column($recentDone, 'request_id'));
} catch (Throwable $e) {
    // Optional section: skip it if the database is not ready.
}

$icons = [
    'housekeeper' => '🧹', 'plumber' => '🔧', 'electrician' => '💡',
    'driver' => '🚗', 'cook' => '🍳', 'gardener' => '🌿',
];

$pageTitle = 'Find trusted help at home';
include __DIR__ . '/includes/header.php';
?>
<main>
  <section class="hero">
    <div class="container hero-grid">
      <div>
        <p class="eyebrow">Household job hiring</p>
        <h1>Reliable help for your home, one request away.</h1>
        <p class="lead">Post what you need done, and verified workers near you can accept the job. Or sign up as a worker and find households that need your skills.</p>
        <div class="cta-row">
          <?php if (is_logged_in()): ?>
            <a class="btn btn-primary" href="<?= e(url(dashboard_path($_SESSION['role']))) ?>">Go to your dashboard</a>
          <?php else: ?>
            <a class="btn btn-primary" href="<?= e(url('register.php?role=customer')) ?>">I need a worker</a>
            <a class="btn btn-outline" href="<?= e(url('register.php?role=worker')) ?>">I'm a worker</a>
          <?php endif; ?>
        </div>
      </div>
      <div class="hero-card" aria-hidden="true">
        <div class="mini-job">
          <span class="chip chip-pending">Pending</span>
          <strong>Leaking kitchen faucet</strong>
          <small>Plumber · requested today</small>
        </div>
        <div class="mini-job">
          <span class="chip chip-accepted">Accepted</span>
          <strong>Weekly house cleaning</strong>
          <small>Housekeeper · accepted</small>
        </div>
        <div class="mini-job">
          <span class="chip chip-done">Completed</span>
          <strong>Garden trimming</strong>
          <small>Gardener · paid</small>
        </div>
      </div>
    </div>
  </section>

  <section id="how" class="section">
    <div class="container">
      <h2>How it works</h2>
      <div class="steps">
        <div class="step">
          <span class="step-num">1</span>
          <h3>Create an account</h3>
          <p>Sign up as a customer who needs help, or as a worker who offers services.</p>
        </div>
        <div class="step">
          <span class="step-num">2</span>
          <h3>Post or accept a job</h3>
          <p>Customers describe the job and pick a service. Verified workers accept requests that match their skills.</p>
        </div>
        <div class="step">
          <span class="step-num">3</span>
          <h3>Get it done and pay</h3>
          <p>Track the request from pending to completed, then settle the payment.</p>
        </div>
      </div>
    </div>
  </section>

  <section id="services" class="section section-alt">
    <div class="container">
      <h2>Services you can hire for</h2>
      <?php if ($categories): ?>
        <div class="service-grid">
          <?php foreach ($categories as $name): ?>
            <div class="service">
              <span class="service-icon"><?= $icons[strtolower($name)] ?? '🏠' ?></span>
              <span><?= e($name) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="muted">Services will show up here once the database is set up.</p>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($recentDone): ?>
  <section class="section">
    <div class="container">
      <div class="page-head">
        <h2 style="margin:0">Recently completed jobs</h2>
        <a class="btn btn-outline btn-sm" href="<?= e(url('completed.php')) ?>">See all</a>
      </div>
      <div class="req-list">
        <?php foreach ($recentDone as $r): ?>
          <div class="req">
            <div class="req-head">
              <span class="req-title"><?= e($r['category_name']) ?></span>
              <?php if ($r['rating'] !== null): ?><?= stars((float) $r['rating']) ?><?php endif; ?>
            </div>
            <p class="req-desc"><?= e($r['description']) ?></p>
            <div class="req-meta">
              <span>Done by <strong><?= e(worker_names($recentTeam[(int) $r['request_id']] ?? [], ['done'])) ?></strong> · <span class="price-tag"><?= e(money($r['price'])) ?></span></span>
              <?php if ($r['remarks']): ?><span>“<?= e($r['remarks']) ?>”</span><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!is_logged_in()): ?>
  <section class="section">
    <div class="container cta-band">
      <div>
        <h2>Ready to get started?</h2>
        <p>It takes a minute to create an account.</p>
      </div>
      <a class="btn btn-primary" href="<?= e(url('register.php')) ?>">Create your account</a>
    </div>
  </section>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
// Public page: anyone can see completed jobs. No address, contact number or email is shown.
require_once __DIR__ . '/includes/init.php';

$perPage = 12;
$page = max(1, (int) ($_GET['page'] ?? 1));
$catId = (int) ($_GET['category'] ?? 0);

$jobs = [];
$categories = [];
$summary = ['total' => 0, 'avg' => null];
$dimAvg = [];
$hasNext = false;
$dbError = false;

try {
    $pdo = db();
    $categories = $pdo->query('SELECT category_id, category_name FROM service_categories ORDER BY category_name')->fetchAll();

    $sum = $pdo->query(
        "SELECT COUNT(*) AS total,
                (SELECT AVG(rating) FROM reviews) AS avg_rating
         FROM job_requests WHERE status = 'completed'"
    )->fetch();
    $summary = ['total' => (int) $sum['total'], 'avg' => $sum['avg_rating'] !== null ? (float) $sum['avg_rating'] : null];
    $dimAvg = $pdo->query(
        'SELECT AVG(quality_rating) AS quality_rating, AVG(punctuality_rating) AS punctuality_rating,
                AVG(professionalism_rating) AS professionalism_rating, AVG(efficiency_rating) AS efficiency_rating
         FROM reviews'
    )->fetch() ?: [];

    $sql = "SELECT r.request_id, r.description, r.price, r.date_completed,
                   c.category_name,
                   wu.first_name AS w_first, wu.last_name AS w_last,
                   cu.first_name AS c_first,
                   rv.rating, rv.quality_rating, rv.punctuality_rating, rv.professionalism_rating, rv.efficiency_rating, rv.remarks
            FROM job_requests r
            JOIN service_categories c ON c.category_id = r.category_id
            JOIN workers w ON w.worker_id = r.worker_id
            JOIN users wu ON wu.user_id = w.user_id
            JOIN customers cs ON cs.customer_id = r.customer_id
            JOIN users cu ON cu.user_id = cs.user_id
            LEFT JOIN reviews rv ON rv.request_id = r.request_id
            WHERE r.status = 'completed'";
    $params = [];
    if ($catId > 0) {
        $sql .= ' AND r.category_id = ?';
        $params[] = $catId;
    }
    // Integers are cast, so they are safe to put straight into the query.
    $sql .= ' ORDER BY r.date_completed DESC, r.request_id DESC LIMIT ' . ($perPage + 1) . ' OFFSET ' . (($page - 1) * $perPage);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $jobs = $stmt->fetchAll();

    if (count($jobs) > $perPage) {
        $hasNext = true;
        array_pop($jobs);
    }
} catch (Throwable $e) {
    $dbError = true;
}

function completed_url(int $page, int $catId): string
{
    $q = [];
    if ($catId > 0) { $q[] = 'category=' . $catId; }
    if ($page > 1)  { $q[] = 'page=' . $page; }
    return url('completed.php' . ($q ? '?' . implode('&', $q) : ''));
}

$pageTitle = 'Completed jobs';
include __DIR__ . '/includes/header.php';
?>
<main class="container panel-page">
  <div class="page-head">
    <h1>Completed jobs</h1>
  </div>
  <p class="muted">Every job finished on <?= e(APP_NAME) ?> is listed here, with the customer's rating and remarks. Addresses and contact details are never shown.</p>

  <?php if ($dbError): ?>
    <div class="empty">Completed jobs can't be shown right now.</div>
  <?php else: ?>
    <div class="stats">
      <div class="stat"><div class="stat-num"><?= (int) $summary['total'] ?></div><div class="stat-label">Jobs completed</div></div>
      <div class="stat">
        <div class="stat-num"><?= $summary['avg'] !== null ? e(number_format($summary['avg'], 1)) . ' ★' : '—' ?></div>
        <div class="stat-label">Average rating</div>
      </div>
    </div>

    <?php $dimHtml = dimension_averages($dimAvg); ?>
    <?php if ($dimHtml !== ''): ?>
      <div class="card" style="margin-bottom:20px">
        <strong>Average ratings by category</strong>
        <?= $dimHtml ?>
      </div>
    <?php endif; ?>

    <?php if ($categories): ?>
      <div class="filters">
        <a href="<?= e(completed_url(1, 0)) ?>" class="<?= $catId === 0 ? 'active' : '' ?>">All services</a>
        <?php foreach ($categories as $c): ?>
          <a href="<?= e(completed_url(1, (int) $c['category_id'])) ?>"
             class="<?= $catId === (int) $c['category_id'] ? 'active' : '' ?>"><?= e($c['category_name']) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!$jobs): ?>
      <div class="empty">No completed jobs to show yet.</div>
    <?php else: ?>
      <div class="req-list">
        <?php foreach ($jobs as $r): ?>
          <div class="req">
            <div class="req-head">
              <span class="req-title"><?= e($r['category_name']) ?></span>
              <span class="chip chip-done">Completed</span>
            </div>
            <p class="req-desc"><?= e($r['description']) ?></p>
            <div class="req-meta">
              <span>Done by <strong><?= e($r['w_first'] . ' ' . $r['w_last']) ?></strong> for <?= e($r['c_first']) ?></span>
              <span>Price: <span class="price-tag"><?= e(money($r['price'])) ?></span> · Completed <?= e(fmt_dt($r['date_completed'])) ?></span>
            </div>
            <?= review_box($r, 'Customer review') ?>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="pager">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e(completed_url($page - 1, $catId)) ?>">← Newer</a><?php else: ?><span></span><?php endif; ?>
        <?php if ($hasNext): ?><a class="btn btn-ghost btn-sm" href="<?= e(completed_url($page + 1, $catId)) ?>">Older →</a><?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/init.php';
require_role('customer');

$cid = (int) $_SESSION['profile_id'];
$pdo = db();

$statuses = ['all', 'pending', 'accepted', 'completed', 'cancelled'];
$filter = in_array($_GET['status'] ?? 'all', $statuses, true) ? ($_GET['status'] ?? 'all') : 'all';
$methods = ['Cash', 'GCash', 'Bank transfer'];

// ---- Actions (cancel / pay / review) -------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
    } elseif ($action === 'cancel') {
        $stmt = $pdo->prepare(
            "UPDATE job_requests SET status = 'cancelled'
             WHERE request_id = ? AND customer_id = ? AND status = 'pending'"
        );
        $stmt->execute([(int) ($_POST['request_id'] ?? 0), $cid]);
        $stmt->rowCount() === 1
            ? flash('success', 'Request cancelled.')
            : flash('error', 'That request can no longer be cancelled.');
    } elseif ($action === 'pay') {
        $method = (string) ($_POST['method'] ?? '');
        if (!in_array($method, $methods, true)) {
            flash('error', 'Please choose a payment method.');
        } else {
            $stmt = $pdo->prepare(
                "UPDATE payments SET payment_method = ?, payment_status = 'paid', payment_date = ?
                 WHERE payment_id = ? AND payment_status = 'unpaid'
                   AND request_id IN (SELECT request_id FROM job_requests WHERE customer_id = ?)"
            );
            $stmt->execute([$method, now_sql(), (int) ($_POST['payment_id'] ?? 0), $cid]);
            $stmt->rowCount() === 1
                ? flash('success', 'Payment recorded. Thank you!')
                : flash('error', 'That payment could not be processed.');
        }
    } elseif ($action === 'review') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $rating    = (int) ($_POST['rating'] ?? 0);   // Overall Satisfaction (required)
        $remarks   = trim((string) ($_POST['remarks'] ?? ''));

        // Detailed ratings are optional: empty means "skipped".
        $dims = [];
        $dimsValid = true;
        foreach (array_keys(RATING_DIMENSIONS) as $key) {
            $raw = trim((string) ($_POST[$key] ?? ''));
            if ($raw === '') {
                $dims[$key] = null;
                continue;
            }
            if (!ctype_digit($raw) || (int) $raw < 1 || (int) $raw > 5) {
                $dimsValid = false;
                break;
            }
            $dims[$key] = (int) $raw;
        }

        if ($rating < 1 || $rating > 5) {
            flash('error', 'Please choose your Overall Satisfaction rating from 1 to 5 stars.');
        } elseif (!$dimsValid) {
            flash('error', 'Each detailed rating must be from 1 to 5 stars, or skipped.');
        } elseif (mb_strlen($remarks) > 500) {
            flash('error', 'Remarks must be 500 characters or fewer.');
        } else {
            $find = $pdo->prepare(
                "SELECT worker_id FROM job_requests
                 WHERE request_id = ? AND customer_id = ? AND status = 'completed' AND worker_id IS NOT NULL"
            );
            $find->execute([$requestId, $cid]);
            $workerId = $find->fetchColumn();

            if (!$workerId) {
                flash('error', 'You can only review your own completed jobs.');
            } else {
                try {
                    $pdo->prepare(
                        'INSERT INTO reviews (request_id, customer_id, worker_id, rating,
                                              quality_rating, punctuality_rating, professionalism_rating, efficiency_rating,
                                              remarks, date_created)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $requestId, $cid, (int) $workerId, $rating,
                        $dims['quality_rating'], $dims['punctuality_rating'],
                        $dims['professionalism_rating'], $dims['efficiency_rating'],
                        $remarks !== '' ? $remarks : null, now_sql(),
                    ]);
                    flash('success', 'Thanks for your review! It now shows on the public completed jobs page.');
                } catch (PDOException $ex) {
                    flash('error', 'You already reviewed this job.');
                }
            }
        }
    }
    redirect('customer/requests.php' . ($filter !== 'all' ? '?status=' . $filter : ''));
}

// ---- List -----------------------------------------------------------------
$sql = "SELECT r.request_id, r.description, r.price, r.status, r.date_request, r.date_accepted, r.date_completed,
               c.category_name,
               u.first_name AS w_first, u.last_name AS w_last, w.contact_number AS w_contact,
               p.payment_id, p.amount, p.payment_status, p.payment_method, p.payment_date,
               rv.rating, rv.quality_rating, rv.punctuality_rating, rv.professionalism_rating, rv.efficiency_rating, rv.remarks
        FROM job_requests r
        JOIN service_categories c ON c.category_id = r.category_id
        LEFT JOIN workers w ON w.worker_id = r.worker_id
        LEFT JOIN users u ON u.user_id = w.user_id
        LEFT JOIN payments p ON p.request_id = r.request_id
        LEFT JOIN reviews rv ON rv.request_id = r.request_id
        WHERE r.customer_id = ?";
$params = [$cid];
if ($filter !== 'all') {
    $sql .= ' AND r.status = ?';
    $params[] = $filter;
}
$sql .= ' ORDER BY r.request_id DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pageTitle = 'My requests';
$activeNav = 'requests';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/subnav.php';
?>
<main class="container panel-page">
  <div class="page-head">
    <h1>My requests</h1>
    <a class="btn btn-primary" href="<?= e(url('customer/new_request.php')) ?>">+ New request</a>
  </div>

  <div class="filters">
    <?php foreach ($statuses as $st): ?>
      <a href="<?= e(url('customer/requests.php' . ($st === 'all' ? '' : '?status=' . $st))) ?>"
         class="<?= $filter === $st ? 'active' : '' ?>"><?= e(ucfirst($st)) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$requests): ?>
    <div class="empty">No <?= $filter === 'all' ? '' : e($filter) . ' ' ?>requests to show.</div>
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
            <span>Your price: <span class="price-tag"><?= e(money($r['price'])) ?></span></span>
            <span>Requested: <strong><?= e(fmt_dt($r['date_request'])) ?></strong></span>
            <?php if ($r['w_first']): ?>
              <span>Worker: <strong><?= e($r['w_first'] . ' ' . $r['w_last']) ?></strong> · <?= e($r['w_contact']) ?></span>
              <span>Accepted: <?= e(fmt_dt($r['date_accepted'])) ?></span>
            <?php endif; ?>
            <?php if ($r['date_completed']): ?>
              <span>Completed: <?= e(fmt_dt($r['date_completed'])) ?></span>
            <?php endif; ?>
            <?php if ($r['payment_id']): ?>
              <span>Payment: <strong><?= e(money($r['amount'])) ?></strong> <?= status_chip($r['payment_status']) ?>
                <?php if ($r['payment_status'] === 'paid'): ?>
                  · <?= e($r['payment_method']) ?>, <?= e(fmt_dt($r['payment_date'])) ?>
                <?php endif; ?>
              </span>
            <?php endif; ?>
          </div>

          <?= review_box($r, 'Your review') ?>

          <?php if ($r['status'] === 'pending'): ?>
            <div class="req-actions">
              <form method="post" onsubmit="return confirm('Cancel this request?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit">Cancel request</button>
              </form>
            </div>
          <?php else: ?>
            <?php if ($r['payment_id'] && $r['payment_status'] === 'unpaid'): ?>
              <div class="req-actions">
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="pay">
                  <input type="hidden" name="payment_id" value="<?= (int) $r['payment_id'] ?>">
                  <select name="method" required aria-label="Payment method">
                    <option value="">Payment method…</option>
                    <?php foreach ($methods as $m): ?><option><?= e($m) ?></option><?php endforeach; ?>
                  </select>
                  <button class="btn btn-primary btn-sm" type="submit">Pay <?= e(money($r['amount'])) ?></button>
                </form>
              </div>
            <?php endif; ?>
            <?php if ($r['status'] === 'completed' && $r['rating'] === null): ?>
              <div class="req-actions">
                <form method="post" class="review-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="review">
                  <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
                  <strong>Rate <?= e($r['w_first']) ?>'s work</strong>
                  <label class="rate-label">
                    <span>Overall Satisfaction <span class="required-flag">required</span></span>
                    <select name="rating" required>
                      <option value="">Choose a rating…</option>
                      <?php foreach (RATING_SCALE as $v => $t): ?><option value="<?= (int) $v ?>"><?= e($t) ?></option><?php endforeach; ?>
                    </select>
                    <span class="note">Your general feeling about the job. This is the score shown on the worker's public profile.</span>
                  </label>
                  <?php foreach (RATING_DIMENSIONS as $key => [$label, $hint]): ?>
                    <label class="rate-label">
                      <span><?= e($label) ?> <span class="muted">(optional)</span></span>
                      <select name="<?= e($key) ?>">
                        <option value="">Skip</option>
                        <?php foreach (RATING_SCALE as $v => $t): ?><option value="<?= (int) $v ?>"><?= e($t) ?></option><?php endforeach; ?>
                      </select>
                      <span class="note"><?= e($hint) ?></span>
                    </label>
                  <?php endforeach; ?>
                  <textarea name="remarks" maxlength="500" placeholder="Remarks about the job (optional)"></textarea>
                  <div><button class="btn btn-outline btn-sm" type="submit">Submit review</button></div>
                </form>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/init.php';
require_role('customer');

$cid = (int) $_SESSION['profile_id'];
$pdo = db();
$categories = $pdo->query('SELECT category_id, category_name FROM service_categories ORDER BY category_name')->fetchAll();
$validIds = array_map('intval', array_column($categories, 'category_id'));

// Verified workers the customer can pick from (no contact details are shown here).
$pickable = $pdo->query(
    "SELECT w.worker_id, u.first_name, u.last_name,
            (SELECT AVG(rv.rating) FROM reviews rv WHERE rv.worker_id = w.worker_id) AS avg_rating,
            (SELECT COUNT(*) FROM reviews rv WHERE rv.worker_id = w.worker_id) AS review_count,
            (SELECT COUNT(*) FROM request_workers rw WHERE rw.worker_id = w.worker_id AND rw.status = 'done') AS done_jobs
     FROM workers w JOIN users u ON u.user_id = w.user_id
     WHERE w.is_verified = 1 ORDER BY u.first_name, u.last_name"
)->fetchAll();
$skillMap = [];   // worker_id => [category_id, ...]
foreach ($pdo->query('SELECT worker_id, category_id FROM worker_skills')->fetchAll() as $row) {
    $skillMap[(int) $row['worker_id']][] = (int) $row['category_id'];
}

$errors = [];
$old = [
    'category_id'    => (int) ($_GET['category'] ?? 0),
    'description'    => '',
    'price'          => '',
    'workers_needed' => 1,
    'invite'         => [],
    'open_to_all'    => false,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['category_id']    = (int) ($_POST['category_id'] ?? 0);
    $old['description']    = trim((string) ($_POST['description'] ?? ''));
    $old['price']          = trim((string) ($_POST['price'] ?? ''));
    $old['workers_needed'] = (int) ($_POST['workers_needed'] ?? 1);
    $old['invite']         = array_values(array_unique(array_map('intval', (array) ($_POST['invite'] ?? []))));
    $old['open_to_all']    = !empty($_POST['open_to_all']);

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }
    if (!in_array($old['category_id'], $validIds, true)) {
        $errors[] = 'Please choose a service.';
    }
    $len = mb_strlen($old['description']);
    if ($len < 10) {
        $errors[] = 'Please describe the job in at least 10 characters.';
    } elseif ($len > 1000) {
        $errors[] = 'Description must be 1000 characters or fewer.';
    }
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $old['price']) || (float) $old['price'] <= 0) {
        $errors[] = 'Enter the price you will pay each worker, greater than zero (for example 500 or 850.50).';
    }
    if ($old['workers_needed'] < 1 || $old['workers_needed'] > MAX_WORKERS_PER_REQUEST) {
        $errors[] = 'Choose between 1 and ' . MAX_WORKERS_PER_REQUEST . ' workers.';
    }

    // Every picked worker must be verified and have the chosen skill.
    foreach ($old['invite'] as $wid) {
        $ok = false;
        foreach ($pickable as $p) {
            if ((int) $p['worker_id'] === $wid && in_array($old['category_id'], $skillMap[$wid] ?? [], true)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            $errors[] = 'One of the workers you picked is not available for this service.';
            break;
        }
    }

    if (!$errors) {
        // Nobody picked = open to every qualified worker.
        $openToAll = (!$old['invite'] || $old['open_to_all']) ? 1 : 0;
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO job_requests (customer_id, category_id, description, price, workers_needed, open_to_all, status, date_request)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)"
            )->execute([$cid, $old['category_id'], $old['description'], $old['price'], $old['workers_needed'], $openToAll, now_sql()]);
            $requestId = (int) $pdo->lastInsertId();

            $ins = $pdo->prepare("INSERT INTO request_workers (request_id, worker_id, status, date_added) VALUES (?, ?, 'invited', ?)");
            foreach ($old['invite'] as $wid) {
                $ins->execute([$requestId, $wid, now_sql()]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('New request failed: ' . $e->getMessage());
            $errors[] = 'Could not post your request. Please try again.';
            if (APP_DEBUG) {
                $errors[] = 'DEBUG: ' . $e->getMessage();
            }
        }
        if (!$errors) {
            flash('success', $old['invite']
                ? 'Request posted! The workers you picked can now accept it.' . ($openToAll ? ' Other qualified workers can apply too.' : '')
                : 'Request posted! Workers with the right skills can now apply. You choose who to accept.');
            redirect('customer/requests.php');
        }
    }
}

$pageTitle = 'New request';
$activeNav = 'new';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/subnav.php';
?>
<main class="container panel-page">
  <div class="card form-card">
    <h1>Post a job request</h1>
    <p class="muted">Tell workers what you need done and what you will pay. Your address is shared only with the workers you accept.</p>

    <?php if ($errors): ?>
      <div class="alert alert-error" role="alert">
        <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="field">
        <label for="category_id">Service needed</label>
        <select id="category_id" name="category_id" required>
          <option value="">Choose a service…</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c['category_id'] ?>" <?= (int) $c['category_id'] === $old['category_id'] ? 'selected' : '' ?>>
              <?= e($c['category_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="description">What needs to be done?</label>
        <textarea id="description" name="description" maxlength="1000" required
                  placeholder="e.g. Kitchen faucet is leaking and needs replacing. Available weekdays after 3 PM."><?= e($old['description']) ?></textarea>
        <p class="note">Completed jobs are shown publicly (without your address or contact number), so don't put personal details here.</p>
      </div>

      <div class="grid-2">
        <div class="field">
          <label for="workers_needed">How many workers?</label>
          <input id="workers_needed" type="number" name="workers_needed" min="1" max="<?= MAX_WORKERS_PER_REQUEST ?>" required
                 inputmode="numeric" value="<?= (int) $old['workers_needed'] ?>">
          <p class="note">1 is fine. Ask for more if the job needs a team.</p>
        </div>
        <div class="field">
          <label for="price">Price for each worker (₱)</label>
          <input id="price" type="number" name="price" min="1" max="99999999" step="0.01" required
                 inputmode="decimal" placeholder="e.g. 500" value="<?= e($old['price']) ?>">
          <p class="note">You decide the price. Each worker you accept is paid this amount once the job is completed.</p>
        </div>
      </div>

      <div class="field" id="pick-field">
        <label>Pick workers <span class="muted">(optional)</span></label>
        <p class="note" style="margin:0 0 8px">Choose workers you'd like for this job. They get your request first. Skip this to let any qualified worker apply, then accept or reject them yourself.</p>
        <p class="muted" id="pick-empty" hidden>No verified worker offers this service yet. Anyone who applies later will wait for your approval.</p>
        <p class="muted" id="pick-choose">Choose a service above to see its workers.</p>
        <div class="pick-list">
          <?php foreach ($pickable as $p): $wid = (int) $p['worker_id']; ?>
            <label class="pick" data-cats="<?= e(implode(',', $skillMap[$wid] ?? [])) ?>" hidden>
              <input type="checkbox" name="invite[]" value="<?= $wid ?>" <?= in_array($wid, $old['invite'], true) ? 'checked' : '' ?>>
              <span>
                <strong><?= e($p['first_name'] . ' ' . $p['last_name']) ?></strong>
                <small class="muted">
                  <?= (int) $p['review_count'] > 0 ? '★ ' . e(number_format((float) $p['avg_rating'], 1)) . ' (' . (int) $p['review_count'] . ')' : 'No ratings yet' ?>
                  · <?= (int) $p['done_jobs'] ?> job<?= (int) $p['done_jobs'] === 1 ? '' : 's' ?> done
                </small>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
        <label class="check" id="open-wrap" style="margin-top:10px" hidden>
          <input type="checkbox" name="open_to_all" value="1" <?= $old['open_to_all'] ? 'checked' : '' ?>>
          <span>Also let other qualified workers apply (otherwise only the workers I picked can see this request)</span>
        </label>
        <p class="note">If you pick more workers than you need, the first ones to accept get the places.</p>
      </div>

      <button type="submit" class="btn btn-primary">Post request</button>
    </form>
  </div>
</main>
<script>
  // Show only the workers who offer the chosen service.
  (function () {
    var sel = document.getElementById('category_id');
    var picks = document.querySelectorAll('.pick');
    function sync() {
      var cat = sel.value, shown = 0, checked = 0;
      picks.forEach(function (p) {
        var ok = cat !== '' && p.getAttribute('data-cats').split(',').indexOf(cat) !== -1;
        p.hidden = !ok;
        if (!ok) { p.querySelector('input').checked = false; } else { shown++; }
        if (ok && p.querySelector('input').checked) { checked++; }
      });
      document.getElementById('pick-choose').hidden = cat !== '';
      document.getElementById('pick-empty').hidden = !(cat !== '' && shown === 0);
      document.getElementById('open-wrap').hidden = checked === 0;
    }
    sel.addEventListener('change', sync);
    picks.forEach(function (p) { p.querySelector('input').addEventListener('change', sync); });
    sync();
  })();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>

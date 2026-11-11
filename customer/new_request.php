<?php
require_once __DIR__ . '/../includes/init.php';
require_role('customer');

$cid = (int) $_SESSION['profile_id'];
$categories = db()->query('SELECT category_id, category_name FROM service_categories ORDER BY category_name')->fetchAll();
$validIds = array_map('intval', array_column($categories, 'category_id'));

$errors = [];
$old = ['category_id' => (int) ($_GET['category'] ?? 0), 'description' => '', 'price' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['category_id'] = (int) ($_POST['category_id'] ?? 0);
    $old['description'] = trim((string) ($_POST['description'] ?? ''));
    $old['price']       = trim((string) ($_POST['price'] ?? ''));

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
        $errors[] = 'Enter the price you will pay, greater than zero (for example 500 or 850.50).';
    }

    if (!$errors) {
        db()->prepare(
            "INSERT INTO job_requests (customer_id, category_id, description, price, status, date_request)
             VALUES (?, ?, ?, ?, 'pending', ?)"
        )->execute([$cid, $old['category_id'], $old['description'], $old['price'], now_sql()]);

        flash('success', 'Request posted! Workers with the right skills can now accept it at your price.');
        redirect('customer/requests.php');
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
    <p class="muted">Tell workers what you need done and what you will pay. Your address is shared only with the worker who accepts.</p>

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
      <div class="field">
        <label for="price">Your price (₱)</label>
        <input id="price" type="number" name="price" min="1" max="99999999" step="0.01" required
               inputmode="decimal" placeholder="e.g. 500" value="<?= e($old['price']) ?>">
        <p class="note">You decide the price. A worker accepts the job at this amount, and you pay it once the job is completed.</p>
      </div>
      <button type="submit" class="btn btn-primary">Post request</button>
    </form>
  </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

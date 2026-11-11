<?php
require_once __DIR__ . '/includes/init.php';

if (is_logged_in()) {
    redirect_to_dashboard();
}

$categories = [];
try {
    $categories = db()->query('SELECT category_id, category_name FROM service_categories ORDER BY category_name')->fetchAll();
} catch (Throwable $e) {
    // handled below if the form is submitted
}

$errors = [];
$old = [
    'role'           => in_array($_GET['role'] ?? '', ['customer', 'worker'], true) ? $_GET['role'] : 'customer',
    'first_name'     => '',
    'last_name'      => '',
    'username'       => '',
    'email'          => '',
    'address'        => '',
    'contact_number' => '',
    'skills'         => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // When the files are bigger than PHP allows, PHP drops the whole form. Say so instead of "session expired".
    $tooBig = empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;

    if ($tooBig) {
        $errors[] = 'The files you selected are too large to send together (the server accepts up to '
            . ini_get('post_max_size') . ' per form). Please choose smaller files and fill in the form again.';
    } else {
        foreach (['role', 'first_name', 'last_name', 'username', 'email', 'address', 'contact_number'] as $f) {
            $old[$f] = trim((string) ($_POST[$f] ?? ''));
        }
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['confirm_password'] ?? '');
        $old['skills'] = array_values(array_unique(array_map('intval', (array) ($_POST['skills'] ?? []))));

        if (!csrf_check($_POST['csrf'] ?? null)) {
            $errors[] = 'Your session expired. Please try again.';
        }
        if (!in_array($old['role'], ['customer', 'worker'], true)) {
            $errors[] = 'Please choose whether you are a customer or a worker.';
        }
        if ($old['first_name'] === '' || mb_strlen($old['first_name']) > 50) {
            $errors[] = 'First name is required (max 50 characters).';
        }
        if ($old['last_name'] === '' || mb_strlen($old['last_name']) > 50) {
            $errors[] = 'Last name is required (max 50 characters).';
        }
        if (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $old['username'])) {
            $errors[] = 'Username must be 3–50 characters: letters, numbers and underscores only.';
        }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || strlen($old['email']) > 100) {
            $errors[] = 'Please enter a valid email address.';
        }
        if ($old['address'] === '' || mb_strlen($old['address']) > 255) {
            $errors[] = 'Address is required (max 255 characters).';
        }
        if (!preg_match('/^[0-9+\-\s()]{7,20}$/', $old['contact_number'])) {
            $errors[] = 'Please enter a valid contact number.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif (strlen($password) > 72) {
            $errors[] = 'Password must be 72 characters or fewer.';
        } elseif ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        $docItems = [];
        if ($old['role'] === 'worker') {
            $validIds = array_map('intval', array_column($categories, 'category_id'));
            if (!$old['skills']) {
                $errors[] = 'Please select at least one skill.';
            } elseif (array_diff($old['skills'], $validIds)) {
                $errors[] = 'One of the selected skills is not valid.';
            }

            // Verification documents are required for workers.
            [$docErrors, $docItems] = validate_documents(uploaded_documents(), (array) ($_POST['doc_type'] ?? []), true);
            $errors = array_merge($errors, $docErrors);
        }

        if (!$errors) {
            $savedFiles = [];
            try {
                $pdo = db();

                $chk = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
                $chk->execute([$old['username']]);
                if ($chk->fetchColumn() > 0) {
                    $errors[] = 'That username is already taken.';
                }
                $chk = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
                $chk->execute([$old['email']]);
                if ($chk->fetchColumn() > 0) {
                    $errors[] = 'An account with that email already exists.';
                }

                if (!$errors) {
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare(
                            'INSERT INTO users (username, password_hash, first_name, last_name, email, role)
                             VALUES (?, ?, ?, ?, ?, ?)'
                        )->execute([
                            $old['username'],
                            password_hash($password, PASSWORD_DEFAULT),
                            $old['first_name'],
                            $old['last_name'],
                            $old['email'],
                            $old['role'],
                        ]);
                        $userId = (int) $pdo->lastInsertId();

                        if ($old['role'] === 'customer') {
                            $pdo->prepare('INSERT INTO customers (user_id, address, contact_number) VALUES (?, ?, ?)')
                                ->execute([$userId, $old['address'], $old['contact_number']]);
                        } else {
                            $pdo->prepare(
                                'INSERT INTO workers (user_id, address, contact_number, date_registered) VALUES (?, ?, ?, ?)'
                            )->execute([$userId, $old['address'], $old['contact_number'], now_sql()]);
                            $workerId = (int) $pdo->lastInsertId();

                            $ins = $pdo->prepare('INSERT INTO worker_skills (worker_id, category_id) VALUES (?, ?)');
                            foreach ($old['skills'] as $categoryId) {
                                $ins->execute([$workerId, $categoryId]);
                            }

                            $savedFiles = store_documents($pdo, $workerId, $docItems);
                        }

                        $pdo->commit();
                    } catch (Throwable $inner) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        delete_files($savedFiles);
                        throw $inner;
                    }

                    flash('success', $old['role'] === 'worker'
                        ? 'Account created! An admin will review your documents before verifying your profile. You can log in now.'
                        : 'Account created! You can log in now.');
                    redirect('login.php');
                }
            } catch (Throwable $ex) {
                // 1062 = duplicate entry (race condition between the check and the insert)
                error_log('Registration failed: ' . $ex->getMessage());
                if ($ex instanceof PDOException && ($ex->errorInfo[1] ?? 0) === 1062) {
                    $errors[] = 'That username or email is already taken.';
                } else {
                    $errors[] = 'Could not create your account. Check that the database is set up and try again.';
                    if (APP_DEBUG) {
                        $errors[] = 'DEBUG: ' . $ex->getMessage();
                    }
                }
            }
        }
    }
}

$pageTitle = 'Create an account';
$bodyClass = 'page-auth';
include __DIR__ . '/includes/header.php';
?>
<main class="auth-wrap">
  <div class="auth-card auth-card-wide">
    <h1>Create your account</h1>
    <p class="muted">Already registered? <a href="<?= e(url('login.php')) ?>">Log in</a></p>

    <?php if ($errors): ?>
      <div class="alert alert-error" role="alert">
        <ul>
          <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && $old['role'] === 'worker'): ?>
          <p class="note" style="margin-top:8px">Browsers don't keep attached files after an error, so please attach your documents again.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>

      <fieldset class="role-pick">
        <legend>I want to…</legend>
        <label class="role-option">
          <input type="radio" name="role" value="customer" <?= $old['role'] === 'customer' ? 'checked' : '' ?>>
          <span><strong>Hire help</strong><small>Customer</small></span>
        </label>
        <label class="role-option">
          <input type="radio" name="role" value="worker" <?= $old['role'] === 'worker' ? 'checked' : '' ?>>
          <span><strong>Offer my services</strong><small>Worker</small></span>
        </label>
      </fieldset>

      <div class="grid-2">
        <div class="field">
          <label for="first_name">First name</label>
          <input id="first_name" name="first_name" maxlength="50" required value="<?= e($old['first_name']) ?>" autocomplete="given-name">
        </div>
        <div class="field">
          <label for="last_name">Last name</label>
          <input id="last_name" name="last_name" maxlength="50" required value="<?= e($old['last_name']) ?>" autocomplete="family-name">
        </div>
      </div>

      <div class="grid-2">
        <div class="field">
          <label for="username">Username</label>
          <input id="username" name="username" maxlength="50" required value="<?= e($old['username']) ?>" autocomplete="username">
        </div>
        <div class="field">
          <label for="email">Email</label>
          <input id="email" type="email" name="email" maxlength="100" required value="<?= e($old['email']) ?>" autocomplete="email">
        </div>
      </div>

      <div class="field">
        <label for="address">Address</label>
        <input id="address" name="address" maxlength="255" required value="<?= e($old['address']) ?>" autocomplete="street-address">
      </div>

      <div class="field">
        <label for="contact_number">Contact number</label>
        <input id="contact_number" name="contact_number" maxlength="20" required value="<?= e($old['contact_number']) ?>" autocomplete="tel" inputmode="tel">
      </div>

      <div class="field" id="skills-field">
        <label>Your skills <small class="muted">(select at least one)</small></label>
        <?php if ($categories): ?>
          <div class="check-grid">
            <?php foreach ($categories as $c): ?>
              <label class="check">
                <input type="checkbox" name="skills[]" value="<?= (int) $c['category_id'] ?>"
                  <?= in_array((int) $c['category_id'], $old['skills'], true) ? 'checked' : '' ?>>
                <span><?= e($c['category_name']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="muted">No service categories found. Run schema.sql first.</p>
        <?php endif; ?>
      </div>

      <div class="field" id="docs-field">
        <label>Verification documents <span class="required-flag">required</span></label>
        <p class="note" style="margin:0 0 10px">
          Attach proof that you are a legitimate worker, such as your <strong>NBI Clearance</strong>,
          <strong>Police Clearance</strong> or <strong>Barangay Clearance</strong>. You can add as many documents as you like.
          Only the admin can see them, and an admin reviews them before verifying your profile.
        </p>
        <?php render_document_fields(); ?>
      </div>

      <div class="grid-2">
        <div class="field">
          <label for="password">Password</label>
          <input id="password" type="password" name="password" minlength="8" maxlength="72" required autocomplete="new-password">
          <small class="muted">At least 8 characters.</small>
        </div>
        <div class="field">
          <label for="confirm_password">Confirm password</label>
          <input id="confirm_password" type="password" name="confirm_password" minlength="8" maxlength="72" required autocomplete="new-password">
        </div>
      </div>

      <button type="submit" class="btn btn-primary btn-block">Create account</button>
    </form>
  </div>
</main>
<script>
  // Show the skills picker and the documents only for workers.
  (function () {
    var fields = [document.getElementById('skills-field'), document.getElementById('docs-field')];
    var radios = document.querySelectorAll('input[name="role"]');
    function sync() {
      var worker = document.querySelector('input[name="role"]:checked').value === 'worker';
      fields.forEach(function (f) { f.hidden = !worker; });
    }
    radios.forEach(function (r) { r.addEventListener('change', sync); });
    sync();
  })();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>

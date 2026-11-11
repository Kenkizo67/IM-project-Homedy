<?php
require_once __DIR__ . '/includes/init.php';

// Logged-in users can change their password from the profile page instead.
if (is_logged_in()) {
    redirect('profile.php');
}

const FP_MAX_ATTEMPTS = 5;
const FP_LOCK_SECONDS = 60;

/** Last 10 digits of a phone number, so "+63 917 123 4567" matches "0917 123 4567". */
function phone_tail(string $number): string
{
    return substr(preg_replace('/\D+/', '', $number), -10);
}

$errors = [];
$old = ['username' => '', 'email' => '', 'contact_number' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $k => $_) {
        $old[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $lockedUntil = (int) ($_SESSION['fp_locked_until'] ?? 0);

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($lockedUntil > time()) {
        $errors[] = 'Too many failed attempts. Please wait ' . ($lockedUntil - time()) . ' seconds and try again.';
    } elseif ($old['username'] === '' || $old['email'] === '' || $old['contact_number'] === '') {
        $errors[] = 'Please fill in your username, email and contact number.';
    } elseif (strlen($new) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    } elseif (strlen($new) > 72) {
        $errors[] = 'New password must be 72 characters or fewer.';
    } elseif ($new !== $confirm) {
        $errors[] = 'New passwords do not match.';
    } else {
        try {
            // Customers and workers only. Admin accounts cannot be reset from this page.
            $stmt = db()->prepare(
                "SELECT u.user_id, c.contact_number AS cust_contact, w.contact_number AS work_contact
                 FROM users u
                 LEFT JOIN customers c ON c.user_id = u.user_id
                 LEFT JOIN workers w ON w.user_id = u.user_id
                 WHERE u.username = ? AND u.email = ? AND u.role IN ('customer', 'worker')"
            );
            $stmt->execute([$old['username'], $old['email']]);
            $row = $stmt->fetch();

            $stored = $row ? phone_tail((string) ($row['cust_contact'] ?? $row['work_contact'] ?? '')) : '';
            $given  = phone_tail($old['contact_number']);
            $match  = $row && strlen($stored) >= 7 && strlen($given) >= 7 && hash_equals($stored, $given);

            if ($match) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), (int) $row['user_id']]);
                $_SESSION['fp_attempts'] = 0;
                flash('success', 'Your password has been reset. You can log in with your new password.');
                redirect('login.php');
            }

            $_SESSION['fp_attempts'] = (int) ($_SESSION['fp_attempts'] ?? 0) + 1;
            if ($_SESSION['fp_attempts'] >= FP_MAX_ATTEMPTS) {
                $_SESSION['fp_attempts'] = 0;
                $_SESSION['fp_locked_until'] = time() + FP_LOCK_SECONDS;
            }
            // Same message for every kind of mismatch, so it never reveals which detail was wrong.
            $errors[] = 'We could not match those details. Check your username, email and contact number.';
        } catch (PDOException $ex) {
            error_log('Forgot password failed: ' . $ex->getMessage());
            $errors[] = 'Could not reach the database. Please try again.';
            if (APP_DEBUG) {
                $errors[] = 'DEBUG: ' . $ex->getMessage();
            }
        }
    }
}

$pageTitle = 'Forgot password';
$bodyClass = 'page-auth';
include __DIR__ . '/includes/header.php';
?>
<main class="auth-wrap">
  <div class="auth-card">
    <h1>Forgot your password?</h1>
    <p class="muted">Enter the details you registered with to prove it's you, then choose a new password.</p>

    <?php if ($errors): ?>
      <div class="alert alert-error" role="alert">
        <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="field">
        <label for="username">Username</label>
        <input id="username" name="username" required value="<?= e($old['username']) ?>" autocomplete="username">
      </div>
      <div class="field">
        <label for="email">Email address</label>
        <input id="email" type="email" name="email" required value="<?= e($old['email']) ?>" autocomplete="email">
      </div>
      <div class="field">
        <label for="contact_number">Contact number</label>
        <input id="contact_number" name="contact_number" required value="<?= e($old['contact_number']) ?>" autocomplete="tel" inputmode="tel">
      </div>
      <div class="field">
        <label for="new_password">New password</label>
        <input id="new_password" type="password" name="new_password" minlength="8" maxlength="72" required autocomplete="new-password">
        <small class="muted">At least 8 characters.</small>
      </div>
      <div class="field">
        <label for="confirm_password">Confirm new password</label>
        <input id="confirm_password" type="password" name="confirm_password" minlength="8" maxlength="72" required autocomplete="new-password">
      </div>
      <button type="submit" class="btn btn-primary btn-block">Reset password</button>
    </form>

    <p class="note" style="margin-top:14px">Back to <a href="<?= e(url('login.php')) ?>">log in</a>. Admin accounts can't be reset here.</p>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>

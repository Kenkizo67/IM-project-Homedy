<?php
require_once __DIR__ . '/includes/init.php';

if (is_logged_in()) {
    redirect_to_dashboard();
}

const MAX_ATTEMPTS = 5;
const LOCK_SECONDS = 60;

$errors = [];
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $password   = (string) ($_POST['password'] ?? '');

    $lockedUntil = (int) ($_SESSION['login_locked_until'] ?? 0);

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($lockedUntil > time()) {
        $errors[] = 'Too many failed attempts. Please wait ' . ($lockedUntil - time()) . ' seconds and try again.';
    } elseif ($identifier === '' || $password === '') {
        $errors[] = 'Please enter your username (or email) and password.';
    } else {
        try {
            $stmt = db()->prepare(
                'SELECT user_id, username, password_hash, first_name, last_name, role
                 FROM users WHERE username = :u1 OR email = :u2 LIMIT 1'
            );
            $stmt->execute([':u1' => $identifier, ':u2' => $identifier]);
            $user = $stmt->fetch();

            // Always run password_verify so response time doesn't reveal whether the user exists.
            $hash = $user['password_hash'] ?? password_hash('not-a-real-password', PASSWORD_DEFAULT);
            $ok = password_verify($password, $hash) && $user;

            if ($ok) {
                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    db()->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $user['user_id']]);
                }

                session_regenerate_id(true);
                $_SESSION = [
                    'user_id'    => (int) $user['user_id'],
                    'username'   => $user['username'],
                    'first_name' => $user['first_name'],
                    'last_name'  => $user['last_name'],
                    'role'       => $user['role'],
                ];

                // Load the role-specific id (customer_id / worker_id / admin_id).
                $table = ['customer' => 'customers', 'worker' => 'workers', 'admin' => 'admins'][$user['role']];
                $col   = ['customer' => 'customer_id', 'worker' => 'worker_id', 'admin' => 'admin_id'][$user['role']];
                $idStmt = db()->prepare("SELECT $col FROM $table WHERE user_id = ?");
                $idStmt->execute([$user['user_id']]);
                $_SESSION['profile_id'] = (int) $idStmt->fetchColumn();

                redirect(dashboard_path($user['role']));
            }

            $_SESSION['login_attempts'] = (int) ($_SESSION['login_attempts'] ?? 0) + 1;
            if ($_SESSION['login_attempts'] >= MAX_ATTEMPTS) {
                $_SESSION['login_attempts'] = 0;
                $_SESSION['login_locked_until'] = time() + LOCK_SECONDS;
            }
            $errors[] = 'Incorrect username or password.';
        } catch (PDOException $ex) {
            error_log('Login failed: ' . $ex->getMessage());
            $errors[] = 'Could not reach the database. Check config.php and that schema.sql has been run.';
            if (APP_DEBUG) {
                $errors[] = 'DEBUG: ' . $ex->getMessage();
            }
        }
    }
}

$pageTitle = 'Log in';
$bodyClass = 'page-auth';
include __DIR__ . '/includes/header.php';
?>
<main class="auth-wrap">
  <div class="auth-card">
    <h1>Welcome back</h1>
    <p class="muted">New here? <a href="<?= e(url('register.php')) ?>">Create an account</a></p>

    <?php if ($errors): ?>
      <div class="alert alert-error" role="alert">
        <ul>
          <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="field">
        <label for="identifier">Username or email</label>
        <input id="identifier" name="identifier" required autofocus value="<?= e($identifier) ?>" autocomplete="username">
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">
      </div>
      <p class="note" style="text-align:right;margin:-6px 0 14px"><a href="<?= e(url('forgot_password.php')) ?>">Forgot password?</a></p>
      <button type="submit" class="btn btn-primary btn-block">Log in</button>
    </form>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>

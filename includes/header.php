<?php
// Expects: $pageTitle (string). Optional: $bodyClass (string).
$pageTitle = $pageTitle ?? APP_NAME;
$bodyClass = $bodyClass ?? '';
$flash     = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
</head>
<body class="<?= e($bodyClass) ?>">
<header class="site-header">
  <div class="container nav">
    <a class="brand" href="<?= e(url('index.php')) ?>"><span class="brand-mark">⌂</span><?= e(APP_NAME) ?></a>
    <nav class="nav-links">
      <a href="<?= e(url('index.php')) ?>#how">How it works</a>
      <a href="<?= e(url('index.php')) ?>#services">Services</a>
      <a href="<?= e(url('completed.php')) ?>">Completed jobs</a>
      <?php if (is_logged_in()): ?>
        <a href="<?= e(url(dashboard_path($_SESSION['role']))) ?>">Dashboard</a>
        <form method="post" action="<?= e(url('logout.php')) ?>" class="inline-form">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-ghost btn-sm">Log out (<?= e($_SESSION['first_name']) ?>)</button>
        </form>
      <?php else: ?>
        <a href="<?= e(url('login.php')) ?>">Log in</a>
        <a class="btn btn-primary btn-sm" href="<?= e(url('register.php')) ?>">Sign up</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<?php if ($flash): ?>
  <div class="container">
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
  </div>
<?php endif; ?>

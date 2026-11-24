<?php
// Expects: $activeNav (string key). Uses the logged-in role.
if ($_SESSION['role'] === 'admin') {
    $navLinks = [
        'dashboard' => ['Overview',  'admin/dashboard.php'],
        'workers'   => ['Workers',   'admin/workers.php'],
        'customers' => ['Customers', 'admin/customers.php'],
        'profile'   => ['Profile',   'profile.php'],
    ];
} elseif ($_SESSION['role'] === 'worker') {
    $navLinks = [
        'dashboard' => ['Overview',  'worker/dashboard.php'],
        'open'      => ['Open jobs', 'worker/open_jobs.php'],
        'jobs'      => ['My jobs',   'worker/my_jobs.php'],
        'profile'   => ['Profile',   'profile.php'],
    ];
} else {
    $navLinks = [
        'dashboard' => ['Overview',    'customer/dashboard.php'],
        'new'       => ['New request', 'customer/new_request.php'],
        'requests'  => ['My requests', 'customer/requests.php'],
        'profile'   => ['Profile',     'profile.php'],
    ];
}
?>
<div class="subnav">
  <div class="container subnav-inner">
    <?php foreach ($navLinks as $key => [$label, $path]): ?>
      <a href="<?= e(url($path)) ?>" class="<?= ($activeNav ?? '') === $key ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</div>

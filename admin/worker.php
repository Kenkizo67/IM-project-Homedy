<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin');

$pdo = db();
$id  = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $wid = (int) ($_POST['worker_id'] ?? 0);
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
    } elseif ($action === 'verify' || $action === 'unverify') {
        $pdo->prepare('UPDATE workers SET is_verified = ? WHERE worker_id = ?')
            ->execute([$action === 'verify' ? 1 : 0, $wid]);
        flash('success', $action === 'verify' ? 'Worker verified. They can now accept jobs.' : 'Worker verification removed.');
    }
    redirect('admin/worker.php?id=' . $wid);
}

$stmt = $pdo->prepare(
    "SELECT w.worker_id, w.address, w.contact_number, w.is_verified, w.date_registered,
            u.username, u.first_name, u.last_name, u.email,
            (SELECT COUNT(*) FROM job_requests r WHERE r.worker_id = w.worker_id AND r.status = 'accepted')  AS active_jobs,
            (SELECT COUNT(*) FROM job_requests r WHERE r.worker_id = w.worker_id AND r.status = 'completed') AS done_jobs,
            (SELECT AVG(rv.rating) FROM reviews rv WHERE rv.worker_id = w.worker_id) AS avg_rating,
            (SELECT COUNT(*) FROM reviews rv WHERE rv.worker_id = w.worker_id) AS review_count,
            (SELECT AVG(rv.quality_rating) FROM reviews rv WHERE rv.worker_id = w.worker_id) AS quality_rating,
            (SELECT AVG(rv.punctuality_rating) FROM reviews rv WHERE rv.worker_id = w.worker_id) AS punctuality_rating,
            (SELECT AVG(rv.professionalism_rating) FROM reviews rv WHERE rv.worker_id = w.worker_id) AS professionalism_rating,
            (SELECT AVG(rv.efficiency_rating) FROM reviews rv WHERE rv.worker_id = w.worker_id) AS efficiency_rating
     FROM workers w JOIN users u ON u.user_id = w.user_id WHERE w.worker_id = ?"
);
$stmt->execute([$id]);
$w = $stmt->fetch();
if (!$w) {
    flash('error', 'Worker not found.');
    redirect('admin/workers.php');
}

$k = $pdo->prepare('SELECT c.category_name FROM worker_skills ws JOIN service_categories c ON c.category_id = ws.category_id WHERE ws.worker_id = ? ORDER BY c.category_name');
$k->execute([$id]);
$skills = $k->fetchAll(PDO::FETCH_COLUMN);

$d = $pdo->prepare('SELECT document_id, doc_type, original_name, file_size, date_uploaded FROM worker_documents WHERE worker_id = ? ORDER BY document_id');
$d->execute([$id]);
$docs = $d->fetchAll();

$pageTitle = 'Worker details';
$activeNav = 'workers';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/subnav.php';
?>
<main class="container panel-page">
  <div class="page-head">
    <h1><?= e($w['first_name'] . ' ' . $w['last_name']) ?></h1>
    <a class="btn btn-outline btn-sm" href="<?= e(url('admin/workers.php')) ?>">← Back to workers</a>
  </div>

  <div class="card form-card">
    <p><?= $w['is_verified'] ? '<span class="chip chip-accepted">Verified</span>' : '<span class="chip chip-pending">Pending verification</span>' ?></p>
    <dl class="details">
      <dt>Username</dt><dd>@<?= e($w['username']) ?></dd>
      <dt>Email</dt><dd><?= e($w['email']) ?></dd>
      <dt>Contact number</dt><dd><?= e($w['contact_number']) ?></dd>
      <dt>Address</dt><dd><?= e($w['address']) ?></dd>
      <dt>Registered</dt><dd><?= e(fmt_dt($w['date_registered'])) ?></dd>
      <dt>Skills</dt>
      <dd><?php if ($skills): ?><span class="tags" style="margin:0"><?php foreach ($skills as $s): ?><span class="tag"><?= e($s) ?></span><?php endforeach; ?></span><?php else: ?>—<?php endif; ?></dd>
      <dt>Jobs</dt><dd>Active: <?= (int) $w['active_jobs'] ?> · Completed: <?= (int) $w['done_jobs'] ?></dd>
      <dt>Rating</dt>
      <dd><?= stars((int) $w['review_count'] > 0 ? (float) $w['avg_rating'] : null) ?>
        <?php if ((int) $w['review_count'] > 0): ?>(<?= e(number_format((float) $w['avg_rating'], 1)) ?> from <?= (int) $w['review_count'] ?>)<?php endif; ?>
        <?= dimension_averages($w) ?></dd>
    </dl>
  </div>

  <div class="card form-card" style="margin-top:20px">
    <h2 style="font-size:1.25rem">Submitted documents (<?= count($docs) ?>)</h2>
    <?php if (!$docs): ?>
      <p class="muted">This worker has not submitted any documents.</p>
    <?php else: ?>
      <?php foreach ($docs as $doc): ?>
        <div class="doc-item">
          <div>
            <strong><?= e($doc['doc_type']) ?></strong><br>
            <span class="muted"><?= e($doc['original_name']) ?> · <?= e(format_bytes((int) $doc['file_size'])) ?> · <?= e(fmt_dt($doc['date_uploaded'])) ?></span>
          </div>
          <div class="doc-actions">
            <a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="<?= e(url('document.php?id=' . (int) $doc['document_id'])) ?>">Open</a>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('document.php?id=' . (int) $doc['document_id'] . '&download=1')) ?>">Download</a>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <form method="post" style="margin-top:16px">
      <?= csrf_field() ?>
      <input type="hidden" name="worker_id" value="<?= (int) $w['worker_id'] ?>">
      <?php if ($w['is_verified']): ?>
        <input type="hidden" name="action" value="unverify">
        <button class="btn btn-danger" type="submit" onclick="return confirm('Remove verification?');">Remove verification</button>
      <?php else: ?>
        <input type="hidden" name="action" value="verify">
        <button class="btn btn-primary" type="submit" <?= $docs ? '' : "onclick=\"return confirm('This worker submitted no documents. Verify anyway?');\"" ?>>Verify worker</button>
      <?php endif; ?>
    </form>
  </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

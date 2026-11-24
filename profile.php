<?php
require_once __DIR__ . '/includes/init.php';

if (!is_logged_in()) {
    redirect('login.php');
}

const PW_MAX_ATTEMPTS = 5;
const PW_LOCK_SECONDS = 60;

$pdo  = db();
$uid  = (int) $_SESSION['user_id'];
$role = $_SESSION['role'];

// ---- Change password ------------------------------------------------------
$pwErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $lockedUntil = (int) ($_SESSION['pw_locked_until'] ?? 0);

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $pwErrors[] = 'Your session expired. Please try again.';
    } elseif ($lockedUntil > time()) {
        $pwErrors[] = 'Too many wrong attempts. Please wait ' . ($lockedUntil - time()) . ' seconds.';
    } elseif (strlen($new) < 8) {
        $pwErrors[] = 'New password must be at least 8 characters.';
    } elseif (strlen($new) > 72) {
        $pwErrors[] = 'New password must be 72 characters or fewer.';
    } elseif ($new !== $confirm) {
        $pwErrors[] = 'New passwords do not match.';
    } else {
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ?');
        $stmt->execute([$uid]);
        $hash = (string) $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            $_SESSION['pw_attempts'] = (int) ($_SESSION['pw_attempts'] ?? 0) + 1;
            if ($_SESSION['pw_attempts'] >= PW_MAX_ATTEMPTS) {
                $_SESSION['pw_attempts'] = 0;
                $_SESSION['pw_locked_until'] = time() + PW_LOCK_SECONDS;
            }
            $pwErrors[] = 'Your current password is incorrect.';
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            $_SESSION['pw_attempts'] = 0;
            session_regenerate_id(true);
            flash('success', 'Your password has been changed.');
            redirect('profile.php');
        }
    }
}

// ---- Upload more documents (workers) --------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'worker' && ($_POST['action'] ?? '') === 'upload_documents') {
    $tooBig = empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
    $wk = $pdo->prepare('SELECT worker_id FROM workers WHERE user_id = ?');
    $wk->execute([$uid]);
    $wid = (int) $wk->fetchColumn();
    if ($tooBig) {
        $docErrors[] = 'The files are too large to send together (limit ' . ini_get('post_max_size') . ').';
    } elseif (!csrf_check($_POST['csrf'] ?? null)) {
        $docErrors[] = 'Your session expired. Please try again.';
    } elseif ($wid) {
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM worker_documents WHERE worker_id = ?');
        $cnt->execute([$wid]);
        [$docErrors, $items] = validate_documents(uploaded_documents(), (array) ($_POST['doc_type'] ?? []), true, (int) $cnt->fetchColumn());
        if (!$docErrors) {
            $moved = [];
            try {
                $pdo->beginTransaction();
                $moved = store_documents($pdo, $wid, $items);
                $pdo->commit();
                flash('success', 'Documents uploaded. An admin will review them.');
                redirect('profile.php');
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                delete_files($moved);
                error_log('Document upload failed: ' . $ex->getMessage());
                $docErrors[] = 'Could not save your documents. Please try again.';
            }
        }
    }
}

// ---- Load profile ---------------------------------------------------------
$stmt = $pdo->prepare('SELECT username, first_name, last_name, email, role FROM users WHERE user_id = ?');
$stmt->execute([$uid]);
$user = $stmt->fetch();

$contact = null;
$address = null;
$verified = null;
$skills = [];
$docs = [];
$docErrors = [];

if ($role === 'customer') {
    $s = $pdo->prepare('SELECT address, contact_number FROM customers WHERE user_id = ?');
    $s->execute([$uid]);
    $p = $s->fetch() ?: [];
    $address = $p['address'] ?? null;
    $contact = $p['contact_number'] ?? null;
} elseif ($role === 'worker') {
    $s = $pdo->prepare('SELECT worker_id, address, contact_number, is_verified FROM workers WHERE user_id = ?');
    $s->execute([$uid]);
    $p = $s->fetch() ?: [];
    $address  = $p['address'] ?? null;
    $contact  = $p['contact_number'] ?? null;
    $verified = !empty($p['is_verified']);
    if (!empty($p['worker_id'])) {
        $k = $pdo->prepare(
            'SELECT c.category_name FROM worker_skills ws
             JOIN service_categories c ON c.category_id = ws.category_id
             WHERE ws.worker_id = ? ORDER BY c.category_name'
        );
        $k->execute([(int) $p['worker_id']]);
        $skills = $k->fetchAll(PDO::FETCH_COLUMN);
        $dq = $pdo->prepare('SELECT document_id, doc_type, original_name, file_size, date_uploaded FROM worker_documents WHERE worker_id = ? ORDER BY document_id');
        $dq->execute([(int) $p['worker_id']]);
        $docs = $dq->fetchAll();
    }
} else {
    $s = $pdo->prepare('SELECT contact_number FROM admins WHERE user_id = ?');
    $s->execute([$uid]);
    $contact = $s->fetchColumn() ?: null;
}

$initials = strtoupper(mb_substr($user['first_name'], 0, 1) . mb_substr($user['last_name'], 0, 1));

$pageTitle = 'My profile';
$activeNav = 'profile';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/subnav.php';
?>
<main class="container panel-page">
  <div class="card form-card">
    <div class="profile-head">
      <div class="avatar" aria-hidden="true"><?= e($initials) ?></div>
      <div>
        <h1 style="margin:0;font-size:1.6rem"><?= e($user['first_name'] . ' ' . $user['last_name']) ?></h1>
        <span class="chip chip-accepted"><?= e(ucfirst($user['role'])) ?></span>
        <?php if ($verified !== null): ?>
          <?= $verified ? '<span class="chip chip-accepted">Verified</span>' : '<span class="chip chip-pending">Pending verification</span>' ?>
        <?php endif; ?>
      </div>
    </div>

    <dl class="details">
      <dt>Full name</dt><dd><?= e($user['first_name'] . ' ' . $user['last_name']) ?></dd>
      <dt>Username</dt><dd>@<?= e($user['username']) ?></dd>
      <dt>Email address</dt><dd><?= e($user['email']) ?></dd>
      <dt>Contact number</dt><dd><?= $contact ? e($contact) : '—' ?></dd>
      <dt>Address</dt><dd><?= $role === 'admin' ? '<span class="muted">Not applicable</span>' : ($address ? e($address) : '—') ?></dd>
      <?php if ($role === 'worker'): ?>
        <dt>Skills</dt>
        <dd>
          <?php if ($skills): ?>
            <span class="tags" style="margin:0"><?php foreach ($skills as $sk): ?><span class="tag"><?= e($sk) ?></span><?php endforeach; ?></span>
          <?php else: ?>—<?php endif; ?>
        </dd>
      <?php endif; ?>
    </dl>
  </div>

  <?php if ($role === 'worker'): ?>
  <div class="card form-card" style="margin-top:20px">
    <h2 style="font-size:1.25rem">My verification documents</h2>
    <?php if ($docErrors): ?>
      <div class="alert alert-error" role="alert"><ul><?php foreach ($docErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if (!$docs): ?><p class="muted">No documents yet.</p><?php endif; ?>
    <?php foreach ($docs as $doc): ?>
      <div class="doc-item">
        <div><strong><?= e($doc['doc_type']) ?></strong><br>
          <span class="muted"><?= e($doc['original_name']) ?> · <?= e(format_bytes((int) $doc['file_size'])) ?> · <?= e(fmt_dt($doc['date_uploaded'])) ?></span></div>
        <div class="doc-actions"><a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="<?= e(url('document.php?id=' . (int) $doc['document_id'])) ?>">Open</a></div>
      </div>
    <?php endforeach; ?>
    <form method="post" enctype="multipart/form-data" style="margin-top:16px" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload_documents">
      <div class="field"><label>Add more documents</label><?php render_document_fields(); ?></div>
      <button type="submit" class="btn btn-primary">Upload</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="card form-card" style="margin-top:20px">
    <h2 style="font-size:1.25rem">Change password</h2>

    <?php if ($pwErrors): ?>
      <div class="alert alert-error" role="alert">
        <ul><?php foreach ($pwErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="change_password">
      <div class="field">
        <label for="current_password">Current password</label>
        <input id="current_password" type="password" name="current_password" required autocomplete="current-password">
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
      <button type="submit" class="btn btn-primary">Update password</button>
    </form>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>

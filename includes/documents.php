<?php
declare(strict_types=1);

/*
 * Worker verification documents (NBI / Police / Barangay clearance, IDs, certificates...).
 *
 * Safety rules:
 *  - Files are saved under a random 32-character name with NO extension, so the web server can never run them.
 *  - Script/executable types are refused, and every file has a size limit.
 *  - Nobody can open a file by URL: document.php checks the login and shows it only to the admin
 *    or to the worker who uploaded it.
 */

const DOC_TYPES = [
    'NBI Clearance',
    'Police Clearance',
    'Barangay Clearance',
    'Valid ID',
    'Certificate / License',
    'Other',
];

// Never accepted, because these can run code.
const DOC_BLOCKED_EXTENSIONS = [
    'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'exe', 'msi', 'bat', 'cmd', 'com',
    'scr', 'sh', 'bash', 'js', 'jar', 'vbs', 'ps1', 'dll', 'apk',
];

function ini_bytes(string $key): int
{
    $v = trim((string) ini_get($key));
    if ($v === '') {
        return 0;
    }
    $n = (int) $v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
}

/** Biggest single file we accept: our own limit, or PHP's upload_max_filesize if that is smaller. */
function effective_max_upload(): int
{
    $limit = MAX_UPLOAD_BYTES;
    $php = ini_bytes('upload_max_filesize');
    return $php > 0 ? min($limit, $php) : $limit;
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB';
    }
    return max(1, (int) round($bytes / 1024)) . ' KB';
}

/** Turns $_FILES['documents'] into a simple list. */
function uploaded_documents(string $field = 'documents'): array
{
    $out = [];
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'] ?? null)) {
        return $out;
    }
    $f = $_FILES[$field];
    foreach ($f['name'] as $i => $name) {
        $out[] = [
            'index' => (int) $i,
            'name'  => (string) $name,
            'tmp'   => (string) ($f['tmp_name'][$i] ?? ''),
            'size'  => (int) ($f['size'][$i] ?? 0),
            'error' => (int) ($f['error'][$i] ?? UPLOAD_ERR_NO_FILE),
        ];
    }
    return $out;
}

function clean_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
    $name = trim($name);
    return mb_substr($name !== '' ? $name : 'document', 0, 120);
}

/**
 * Checks every chosen file. Rows without a file are ignored.
 * @return array{0: list<string>, 1: list<array>}  [errors, files that are OK to save]
 */
function validate_documents(array $files, array $types, bool $required, int $alreadyStored = 0): array
{
    $errors = [];
    $items = [];
    $attempted = 0;
    $max = effective_max_upload();

    foreach ($files as $f) {
        if ($f['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $attempted++;
        $name = clean_filename($f['name']);

        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            $errors[] = '"' . $name . '" is too large. The limit is ' . format_bytes($max) . ' per file.';
            continue;
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp'])) {
            $errors[] = '"' . $name . '" could not be uploaded. Please try again.';
            continue;
        }
        if ($f['size'] <= 0) {
            $errors[] = '"' . $name . '" is empty.';
            continue;
        }
        if ($f['size'] > $max) {
            $errors[] = '"' . $name . '" is too large. The limit is ' . format_bytes($max) . ' per file.';
            continue;
        }
        // Check every dot-separated part, so "scan.php.jpg" is refused too.
        $parts = array_slice(explode('.', strtolower($name)), 1);
        if (array_intersect($parts, DOC_BLOCKED_EXTENSIONS)) {
            $errors[] = '"' . $name . '" is a type of file that is not allowed. Please attach a PDF, Word document or image.';
            continue;
        }
        $type = trim((string) ($types[$f['index']] ?? ''));
        if (!in_array($type, DOC_TYPES, true)) {
            $errors[] = 'Please choose a document type for "' . $name . '".';
            continue;
        }
        $items[] = ['name' => $name, 'tmp' => $f['tmp'], 'size' => $f['size'], 'type' => $type];
    }

    if ($required && $attempted === 0) {
        $errors[] = 'Please attach at least one verification document, such as your NBI, Police or Barangay clearance.';
    }
    if (count($items) > MAX_DOCUMENTS) {
        $errors[] = 'You can attach up to ' . MAX_DOCUMENTS . ' documents at a time.';
    }
    if ($alreadyStored + count($items) > MAX_DOCUMENTS_TOTAL) {
        $errors[] = 'A worker can have up to ' . MAX_DOCUMENTS_TOTAL . ' documents in total.';
    }
    return [$errors, $items];
}

function ensure_storage_dir(): void
{
    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
        throw new RuntimeException('Could not create the documents folder.');
    }
    $htaccess = UPLOAD_DIR . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    $index = UPLOAD_DIR . '/index.html';
    if (!is_file($index)) {
        @file_put_contents($index, "<!doctype html><title>Forbidden</title>\n");
    }
}

/**
 * Moves the files into storage and records them. Call inside a DB transaction.
 * @return list<string> paths of the saved files (delete them if the transaction later fails)
 */
function store_documents(PDO $pdo, int $workerId, array $items): array
{
    ensure_storage_dir();
    $saved = [];
    try {
        $ins = $pdo->prepare(
            'INSERT INTO worker_documents (worker_id, doc_type, original_name, stored_name, file_size, date_uploaded)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $it) {
            $stored = bin2hex(random_bytes(16));
            $dest = UPLOAD_DIR . '/' . $stored;
            if (!move_uploaded_file($it['tmp'], $dest)) {
                throw new RuntimeException('Could not save "' . $it['name'] . '".');
            }
            @chmod($dest, 0640);
            $saved[] = $dest;
            $ins->execute([$workerId, $it['type'], $it['name'], $stored, $it['size'], now_sql()]);
        }
    } catch (Throwable $e) {
        foreach ($saved as $path) {
            @unlink($path);
        }
        throw $e;
    }
    return $saved;
}

function delete_files(array $paths): void
{
    foreach ($paths as $p) {
        @unlink($p);
    }
}

/** Prints the "type + file" rows with an "Add another document" button. */
function render_document_fields(): void
{
    $options = '<option value="">Choose type…</option>';
    foreach (DOC_TYPES as $t) {
        $options .= '<option>' . e($t) . '</option>';
    }
    ?>
    <div id="doc-rows" class="doc-rows" data-max="<?= (int) MAX_DOCUMENTS ?>">
      <div class="doc-row">
        <select name="doc_type[]" aria-label="Document type"><?= $options ?></select>
        <input type="file" name="documents[]" aria-label="Choose file">
      </div>
    </div>
    <template id="doc-row-tpl">
      <div class="doc-row">
        <select name="doc_type[]" aria-label="Document type"><?= $options ?></select>
        <input type="file" name="documents[]" aria-label="Choose file">
        <button type="button" class="btn btn-ghost btn-sm doc-remove">Remove</button>
      </div>
    </template>
    <button type="button" id="doc-add" class="btn btn-outline btn-sm">+ Add another document</button>
    <p class="note">PDF, Word, images or any other document. Up to <?= (int) MAX_DOCUMENTS ?> files at a time,
      <?= e(format_bytes(effective_max_upload())) ?> each.</p>
    <script src="<?= e(url('assets/documents.js')) ?>"></script>
    <?php
}

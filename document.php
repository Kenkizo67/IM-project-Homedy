<?php
// Serves a worker's verification document. Only the admin, or the worker who uploaded it, can open it.
require_once __DIR__ . '/includes/init.php';

if (!is_logged_in()) {
    redirect('login.php');
}

function stop(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT d.document_id, d.original_name, d.stored_name, w.user_id
     FROM worker_documents d JOIN workers w ON w.worker_id = d.worker_id
     WHERE d.document_id = ?'
);
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc) {
    stop(404, 'Document not found.');
}

$isAdmin = $_SESSION['role'] === 'admin';
$isOwner = $_SESSION['role'] === 'worker' && (int) $doc['user_id'] === (int) $_SESSION['user_id'];
if (!$isAdmin && !$isOwner) {
    stop(403, 'You are not allowed to open this document.');
}

if (!preg_match('/^[a-f0-9]{32}$/', (string) $doc['stored_name'])) {
    stop(404, 'Document not found.');
}
$path = UPLOAD_DIR . '/' . $doc['stored_name'];
if (!is_file($path)) {
    stop(404, 'The file is missing from the server.');
}

// Show PDFs and photos in the browser (checked by their first bytes, not by the file name).
// Everything else is always downloaded, so it can never run inside the website.
$head = (string) file_get_contents($path, false, null, 0, 8);
$mime = 'application/octet-stream';
$inline = false;
if (str_starts_with($head, '%PDF')) {
    $mime = 'application/pdf';
    $inline = true;
} elseif (str_starts_with($head, "\xFF\xD8\xFF")) {
    $mime = 'image/jpeg';
    $inline = true;
} elseif (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
    $mime = 'image/png';
    $inline = true;
}
if (isset($_GET['download'])) {
    $inline = false;
}

$name = (string) $doc['original_name'];
$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'document';

session_write_close();
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;

<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

// ---- BASE_URL auto-detect -------------------------------------------------
if (!defined('BASE_URL')) {
    $docRoot  = rtrim(str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $projRoot = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
    define('BASE_URL', ($docRoot !== '' && str_starts_with($projRoot, $docRoot))
        ? substr($projRoot, strlen($docRoot))
        : '');
}

// ---- Database -------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

// ---- Helpers --------------------------------------------------------------
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(?string $token): bool
{
    return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ---- Auth -----------------------------------------------------------------
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function dashboard_path(string $role): string
{
    return match ($role) {
        'admin'  => 'admin/dashboard.php',
        'worker' => 'worker/dashboard.php',
        default  => 'customer/dashboard.php',
    };
}

function redirect_to_dashboard(): never
{
    redirect(dashboard_path($_SESSION['role'] ?? 'customer'));
}

function require_role(string $role): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
    if ($_SESSION['role'] !== $role) {
        redirect_to_dashboard();
    }
}

// ---- Display helpers ------------------------------------------------------
function status_chip(string $status): string
{
    $map = [
        'pending'   => 'chip-pending',
        'accepted'  => 'chip-accepted',
        'completed' => 'chip-done',
        'cancelled' => 'chip-cancelled',
        'paid'      => 'chip-accepted',
        'unpaid'    => 'chip-pending',
    ];
    return '<span class="chip ' . ($map[$status] ?? '') . '">' . e(ucfirst($status)) . '</span>';
}

function money($amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

function fmt_dt(?string $dt): string
{
    return $dt ? date('M j, Y g:i A', strtotime($dt)) : '—';
}

function now_sql(): string
{
    return date('Y-m-d H:i:s');
}

// ---- Admin helpers --------------------------------------------------------
function random_password(int $length = 10): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

/** Sets a new random password for a user of the given role. Returns the plain password once, or null. */
function admin_reset_password(PDO $pdo, int $userId, string $role): ?string
{
    $new = random_password();
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ? AND role = ?');
    $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $userId, $role]);
    return $stmt->rowCount() === 1 ? $new : null;
}

// ---- Ratings --------------------------------------------------------------
function stars(?float $rating): string
{
    if ($rating === null) {
        return '<span class="muted">No ratings yet</span>';
    }
    $full = (int) round($rating);
    return '<span class="stars" aria-label="' . e(number_format($rating, 1)) . ' out of 5">'
        . str_repeat('★', $full) . '<span class="off">' . str_repeat('★', 5 - $full) . '</span></span>';
}

// ---- Rating dimensions ----------------------------------------------------
// "rating" itself is the required Overall Satisfaction score. These four are optional.
const RATING_DIMENSIONS = [
    'quality_rating'         => ['Work Quality',            'Cleanliness, thoroughness or correctness of repairs'],
    'punctuality_rating'     => ['Punctuality / Timeliness', 'Arrived on time and finished within the agreed timeframe'],
    'professionalism_rating' => ['Professionalism',         'Behavior, politeness, communication and respect in your home'],
    'efficiency_rating'      => ['Efficiency / Speed',      'Managed their time well and finished in a reasonable duration'],
];

const RATING_SCALE = [5 => '★★★★★ Excellent', 4 => '★★★★ Good', 3 => '★★★ Okay', 2 => '★★ Poor', 1 => '★ Very poor'];

/** One review: overall stars, any detailed ratings that were given, and the remarks. */
function review_box(array $r, string $title = 'Customer review'): string
{
    if (!isset($r['rating'])) {
        return '';
    }
    $html = '<div class="review-box"><strong>' . e($title) . '</strong> ' . stars((float) $r['rating'])
          . ' <span class="muted">Overall satisfaction</span>';
    $items = '';
    foreach (RATING_DIMENSIONS as $key => [$label]) {
        if (isset($r[$key])) {
            $items .= '<li><span>' . e($label) . '</span>' . stars((float) $r[$key]) . '</li>';
        }
    }
    if ($items !== '') {
        $html .= '<ul class="dims">' . $items . '</ul>';
    }
    if (!empty($r['remarks'])) {
        $html .= '<p>' . e($r['remarks']) . '</p>';
    }
    return $html . '</div>';
}

/** Average per detailed rating. $avg holds the averages under the same keys as RATING_DIMENSIONS. */
function dimension_averages(array $avg): string
{
    $items = '';
    foreach (RATING_DIMENSIONS as $key => [$label]) {
        if (isset($avg[$key])) {
            $items .= '<li><span>' . e($label) . '</span><span>' . stars((float) $avg[$key]) . ' '
                    . e(number_format((float) $avg[$key], 1)) . '</span></li>';
        }
    }
    return $items !== '' ? '<ul class="dims">' . $items . '</ul>' : '';
}

// ---- Document uploads (override any of these in config.php) --------------
if (!defined('UPLOAD_DIR'))            { define('UPLOAD_DIR', dirname(__DIR__) . '/storage/documents'); }
if (!defined('MAX_UPLOAD_BYTES'))      { define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024); }  // per file
if (!defined('MAX_DOCUMENTS'))         { define('MAX_DOCUMENTS', 10); }                  // per upload
if (!defined('MAX_DOCUMENTS_TOTAL'))   { define('MAX_DOCUMENTS_TOTAL', 25); }            // per worker
require_once __DIR__ . '/documents.php';

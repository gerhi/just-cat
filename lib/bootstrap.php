<?php
/**
 * Common bootstrap for all pages (except install.php).
 */

declare(strict_types=1);

define('CAT', true);
define('CAT_VERSION', '1.2.0');

$__cfgFile = __DIR__ . '/config.php';
if (!is_file($__cfgFile)) {
    // Not installed yet: point to the installer.
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if (basename($dir) === 'admin') {
        $dir = rtrim(dirname($dir), '/');
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Not installed</title>'
        . '<p style="font-family:sans-serif">This application is not installed yet. '
        . '<a href="' . htmlspecialchars($dir . '/install.php') . '">Open the installer</a>.</p>';
    exit;
}
$GLOBALS['CAT_CONFIG'] = require $__cfgFile;
unset($__cfgFile);

if (!empty($GLOBALS['CAT_CONFIG']['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}
date_default_timezone_set('UTC');

require __DIR__ . '/i18n.php';
require __DIR__ . '/cat.php';
require __DIR__ . '/schema.php';
require __DIR__ . '/texts.php';
require __DIR__ . '/layout.php';

// ─── Config & URLs ────────────────────────────────────────────────────

function cfg(string $key, $default = null)
{
    return $GLOBALS['CAT_CONFIG'][$key] ?? $default;
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    return strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function base_path(): string
{
    return rtrim((string)cfg('base_path', ''), '/');
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function abs_url(string $path = ''): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (is_https() ? 'https://' : 'http://') . $host . url($path);
}

function test_path(string $slug): string
{
    return cfg('pretty_urls', true)
        ? url('t/' . rawurlencode($slug))
        : url('t.php?slug=' . rawurlencode($slug));
}

function test_abs_url(string $slug): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (is_https() ? 'https://' : 'http://') . $host . test_path($slug);
}

function redirect(string $to, int $code = 303): void
{
    header('Location: ' . $to, true, $code);
    exit;
}

// ─── Security headers / HTTPS ─────────────────────────────────────────

if (cfg('force_https', false) && !is_https() && PHP_SAPI !== 'cli') {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== '') {
        redirect('https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), 301);
    }
}

if (PHP_SAPI !== 'cli') {
    // No referrer leaves this site (tokens in URLs never reach third parties).
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'self'");
    // Pages must not be cached (shared devices, back button after answering).
    header('Cache-Control: no-store, max-age=0');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

// ─── Database ─────────────────────────────────────────────────────────

const CAT_TABLES = ['users', 'item_banks', 'items', 'tests', 'sessions', 'responses'];

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if (cfg('db_driver', 'mysql') === 'sqlite') {
            $pdo = new PDO('sqlite:' . cfg('sqlite_path'), null, null, $opts);
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                cfg('db_host'), (int)cfg('db_port', 3306), cfg('db_name')
            );
            $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), $opts);
            $pdo->exec("SET time_zone = '+00:00'");
        }
    }
    return $pdo;
}

/** Replace {table} placeholders with prefixed table names. */
function sql(string $query): string
{
    $prefix = (string)cfg('db_prefix', '');
    return preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($prefix) {
        return in_array($m[1], CAT_TABLES, true) ? $prefix . $m[1] : $m[0];
    }, $query);
}

function q(string $query, array $params = []): PDOStatement
{
    $st = db()->prepare(sql($query));
    $st->execute($params);
    return $st;
}

function q_one(string $query, array $params = []): ?array
{
    $row = q($query, $params)->fetch();
    return $row === false ? null : $row;
}

function q_all(string $query, array $params = []): array
{
    return q($query, $params)->fetchAll();
}

function q_val(string $query, array $params = [])
{
    $v = q($query, $params)->fetchColumn();
    return $v === false ? null : $v;
}

// ─── Formatting / parsing ─────────────────────────────────────────────

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** UTC 'Y-m-d H:i:s' → local display time (config timezone). */
function fmt_dt(?string $utc): string
{
    if (!$utc) {
        return '–';
    }
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone((string)cfg('timezone', 'Europe/Berlin')));
    $formats = ['de' => 'd.m.Y H:i', 'fr' => 'd/m/Y H:i', 'es' => 'd/m/Y H:i', 'ja' => 'Y/m/d H:i'];
    return $d->format($formats[i18n_lang()] ?? 'Y-m-d H:i');
}

function fmt_num($x, int $dec = 3): string
{
    return $x === null || $x === '' ? '–' : number_format((float)$x, $dec, '.', '');
}

/** Parse "1.5", "1,5", "-0,25", "2e-3". Returns null if not a number. */
function parse_number($s): ?float
{
    $s = trim((string)$s);
    $s = str_replace(["\u{2212}", ' '], ['-', ''], $s); // unicode minus, spaces
    if (!preg_match('/^[+-]?(\d+([.,]\d*)?|[.,]\d+)([eE][+-]?\d+)?$/', $s)) {
        return null;
    }
    $v = (float)str_replace(',', '.', $s);
    return is_finite($v) ? $v : null;
}

/** Status shown to the admin: in_progress sessions idle too long count as abandoned. */
function effective_status(array $s): string
{
    if ($s['status'] === 'in_progress') {
        $idle = (int)cfg('abandon_after_minutes', 120) * 60;
        if (strtotime($s['last_activity'] . ' UTC') < time() - $idle) {
            return 'abandoned';
        }
    }
    return $s['status'];
}

// ─── Items ────────────────────────────────────────────────────────────

/** DB row → engine item array. */
function engine_item(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'model' => $row['model'],
        'a' => (float)$row['a'],
        'b' => json_decode($row['b'], true),
        'c' => (float)$row['c'],
    ];
}

/** Answer labels shown to the participant (1-based raw values map to index+1). */
function item_labels(array $row, array $L): array
{
    $opts = $row['options'] ? json_decode($row['options'], true) : null;
    switch ($row['response_type']) {
        case 'dichotomous':
            return $opts ?: [$L['yes'], $L['no']];
        case 'multiple_choice':
            return $opts;
        case 'likert':
            $n = (int)$row['likert_levels'];
            return $opts ?: array_map('strval', range(1, $n));
    }
    return [];
}

/**
 * Raw answer (1-based option number as shown) → scored value for the model.
 * Returns null if the raw value is out of range.
 */
function score_response(array $row, int $raw): ?int
{
    switch ($row['response_type']) {
        case 'likert':
            $n = (int)$row['likert_levels'];
            if ($raw < 1 || $raw > $n) {
                return null;
            }
            $cat = $raw - 1;
            return $row['reverse_scored'] ? ($n - 1 - $cat) : $cat;
        case 'dichotomous':
            if ($raw < 1 || $raw > 2) {
                return null;
            }
            return $raw === (int)($row['correct_option'] ?? 1) ? 1 : 0;
        case 'multiple_choice':
            $n = count(json_decode((string)$row['options'], true) ?: []);
            if ($raw < 1 || $raw > $n) {
                return null;
            }
            return $raw === (int)$row['correct_option'] ? 1 : 0;
    }
    return null;
}

// ─── Admin session, users, permissions, CSRF, flash ───────────────────

function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('CATADMIN');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => base_path() . '/admin/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/** Interface language before login: cookie set by the language switch, else browser. */
function ui_language_init(): void
{
    $lang = (string)($_COOKIE['cat_lang'] ?? '');
    i18n_set(isset(CAT_LANGS[$lang]) ? $lang : i18n_detect());
}

/** The logged-in user (row from the users table) or null. Sets the interface language. */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    admin_session_start();
    $user = null;
    $uid = (int)($_SESSION['uid'] ?? 0);
    if ($uid) {
        $idle = (int)cfg('admin_idle_minutes', 240) * 60;
        if (($_SESSION['last_seen'] ?? 0) < time() - $idle) {
            $_SESSION = [];
            return $user;
        }
        $row = q_one('SELECT * FROM {users} WHERE id = ? AND is_active = 1', [$uid]);
        if ($row) {
            $_SESSION['last_seen'] = time();
            $user = $row;
            i18n_set($row['language']);
        } else {
            $_SESSION = [];
        }
    }
    return $user;
}

function uid(): int
{
    return (int)(current_user()['id'] ?? 0);
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function role_label(string $role): string
{
    return $role === 'admin' ? t('Administrator') : t('Project leader');
}

/** Any logged-in account. Forces a password change when one is pending. */
function require_login(): array
{
    ui_language_init();
    ensure_schema();
    $u = current_user();
    if (!$u) {
        redirect(url('admin/login.php'));
    }
    if ($u['must_change_password'] && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'password.php') {
        redirect(url('admin/password.php'));
    }
    return $u;
}

function require_admin_role(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        forbidden();
    }
    return $u;
}

function forbidden(): void
{
    http_response_code(403);
    admin_start(t('No access'), '');
    echo '<div class="empty"><p>' . te('This page belongs to another account.') . '</p><p><a class="btn" href="'
        . h(url('admin/index.php')) . '">' . te('Back to the overview') . '</a></p></div>';
    admin_end();
    exit;
}

/** SQL fragment + parameters limiting tests to the user's own (admins: all). */
function scope_tests(string $alias = 't'): array
{
    return is_admin() ? ['', []] : [" AND {$alias}.owner_id = ?", [uid()]];
}

/** Item banks a user may see and use: own banks and the shared examples (admins: all). */
function scope_banks(string $alias = 'b'): array
{
    return is_admin() ? ['', []] : [" AND ({$alias}.owner_id = ? OR {$alias}.is_example = 1)", [uid()]];
}

function can_edit(array $row): bool
{
    return is_admin() || (int)($row['owner_id'] ?? 0) === uid();
}

function can_use_bank(array $bank): bool
{
    return can_edit($bank) || (int)($bank['is_example'] ?? 0) === 1;
}

/** Test by id, or a 403 page if it belongs to someone else. */
function load_test(int $id): ?array
{
    $test = q_one('SELECT * FROM {tests} WHERE id = ?', [$id]);
    if ($test && !can_edit($test)) {
        forbidden();
    }
    return $test;
}

function load_bank(int $id): ?array
{
    $bank = q_one('SELECT * FROM {item_banks} WHERE id = ?', [$id]);
    if ($bank && !can_use_bank($bank)) {
        forbidden();
    }
    return $bank;
}

function users_for_select(): array
{
    return q_all('SELECT id, name, username, role FROM {users} WHERE is_active = 1 ORDER BY name');
}

/** Bring an older database up to date (adds tables and columns once). */
function ensure_schema(): void
{
    static $done = false;
    if (!$done) {
        $done = true;
        cat_migrate(db(), (string)cfg('db_driver', 'mysql'), (string)cfg('db_prefix', ''), cfg('admin_password_hash'));
    }
}

function csrf_token(): string
{
    admin_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    admin_session_start();
    $sent = (string)($_POST['csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(400);
        exit(te('The form has expired. Go back, reload the page and try again.'));
    }
}

function flash(string $type, string $msg): void
{
    admin_session_start();
    $_SESSION['flash'][] = [$type, $msg];
}

function flash_html(): string
{
    admin_session_start();
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        $out .= '<div class="alert alert-' . h($type) . '">' . nl2br(h($msg)) . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

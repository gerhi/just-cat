<?php
/** Switch the interface language (saved with the account and in a cookie), then go back. */

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

$lang = (string)($_GET['set'] ?? '');
if (isset(CAT_LANGS[$lang])) {
    setcookie('cat_lang', $lang, [
        'expires' => time() + 365 * 24 * 3600,
        'path' => base_path() . '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ensure_schema();
    if ($u = current_user()) {
        q('UPDATE {users} SET language = ? WHERE id = ?', [$lang, $u['id']]);
    }
}

// Only return to pages of this application.
$back = (string)($_GET['back'] ?? '');
$prefix = base_path() . '/';
if ($back === '' || strncmp($back, $prefix, strlen($prefix)) !== 0 || str_contains($back, '//') || preg_match('/[\r\n]/', $back)) {
    $back = url('admin/index.php');
}
redirect($back);

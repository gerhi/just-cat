<?php
declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

ui_language_init();
ensure_schema();
if (current_user()) {
    redirect(url('admin/index.php'));
}

$error = '';
$login = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $login = trim((string)($_POST['login'] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    $fails = (int)($_SESSION['login_fails'] ?? 0);
    if ($fails >= 3) {
        sleep(min($fails, 10)); // slow down guessing
    }
    $user = $login === '' ? null : q_one(
        'SELECT * FROM {users} WHERE (username = ? OR (email IS NOT NULL AND email = ?)) AND is_active = 1',
        [$login, $login]
    );
    if ($user && password_verify($pw, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION = ['uid' => (int)$user['id'], 'last_seen' => time()];
        q('UPDATE {users} SET last_login_at = ? WHERE id = ?', [now_utc(), $user['id']]);
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            q('UPDATE {users} SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $user['id']]);
        }
        redirect(url($user['must_change_password'] ? 'admin/password.php' : 'admin/index.php'));
    }
    $_SESSION['login_fails'] = $fails + 1;
    $error = t('User name or password is wrong.');
}
?><!doctype html>
<html lang="<?= h(html_lang()) ?>">
<head>
<?php head_tags(t('Log in') . ' – ' . t('CAT admin')); ?>
</head>
<body class="plain">
<main class="wrap narrow">
  <form method="post" class="panel form">
    <h1 class="brand"><span class="bubble" aria-hidden="true">θ</span><?= te('CAT admin') ?></h1>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <label><?= te('User name or e-mail') ?>
      <input name="login" value="<?= h($login) ?>" required autofocus autocomplete="username">
    </label>
    <label><?= te('Password') ?>
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button class="btn btn-primary btn-block" type="submit"><?= te('Log in') ?></button>
  </form>
  <nav class="lang-row" aria-label="<?= te('Language') ?>">
    <?php foreach (CAT_LANGS as $code => $name): ?>
      <a href="<?= h(url('admin/lang.php?set=' . $code . '&back=' . rawurlencode(url('admin/login.php')))) ?>" lang="<?= h(html_lang($code)) ?>"<?= $code === i18n_lang() ? ' aria-current="true"' : '' ?>><?= h($name) ?></a>
    <?php endforeach; ?>
  </nav>
</main>
</body>
</html>

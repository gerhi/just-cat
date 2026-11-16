<?php
declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
$user = require_login();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cur = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    $new2 = (string)($_POST['new2'] ?? '');
    if (!password_verify($cur, $user['password_hash'])) {
        $errors[] = t('The current password is wrong.');
    } elseif (strlen($new) < 10) {
        $errors[] = t('The new password must have at least 10 characters.');
    } elseif ($new !== $new2) {
        $errors[] = t('The two new passwords do not match.');
    } elseif ($new === $cur) {
        $errors[] = t('Choose a password that differs from the current one.');
    }
    if (!$errors) {
        q('UPDATE {users} SET password_hash = ?, must_change_password = 0 WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);
        flash('success', t('Password changed.'));
        redirect(url($user['must_change_password'] ? 'admin/index.php' : 'admin/password.php'));
    }
}

admin_start(t('Change password'), 'password');
if ($user['must_change_password']) {
    echo '<div class="alert alert-warning">' . te('You are using a starting password. Choose your own password to continue.') . '</div>';
}
foreach ($errors as $e) {
    echo '<div class="alert alert-error">' . h($e) . '</div>';
}
?>
<form method="post" class="form panel narrow-form">
  <?= csrf_field() ?>
  <label><?= te('Current password') ?> <input type="password" name="current" required autocomplete="current-password"></label>
  <label><?= te('New password') ?> <small><?= te('At least 10 characters.') ?></small>
    <input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
  <label><?= te('Repeat new password') ?> <input type="password" name="new2" required minlength="10" autocomplete="new-password"></label>
  <button class="btn btn-primary" type="submit"><?= te('Change password') ?></button>
</form>
<?php
admin_end();

<?php
/**
 * Accounts (administrators only): create project leaders and administrators,
 * reset passwords, deactivate, delete with transfer of their tests and banks.
 */

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
$me = require_admin_role();

$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId ? q_one('SELECT * FROM {users} WHERE id = ?', [$editId]) : null;
$errors = [];
$new = ['name' => '', 'username' => '', 'email' => '', 'role' => 'leader', 'language' => i18n_lang()];

function active_admins(): int
{
    return (int)q_val("SELECT COUNT(*) FROM {users} WHERE role = 'admin' AND is_active = 1");
}

/** Validate name/username/email/role/language; returns error list. */
function validate_user(array $f, int $exceptId = 0): array
{
    $err = [];
    if ($f['name'] === '') $err[] = t('Name is required.');
    if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $f['username'])) {
        $err[] = t('User name: 3 to 64 characters, letters, digits, dot, hyphen and underscore.');
    } elseif (q_val('SELECT id FROM {users} WHERE username = ? AND id <> ?', [$f['username'], $exceptId])) {
        $err[] = t('This user name is already taken.');
    }
    if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $err[] = t('The e-mail address is not valid.');
    if ($f['email'] !== '' && q_val('SELECT id FROM {users} WHERE email = ? AND id <> ?', [$f['email'], $exceptId])) {
        $err[] = t('This e-mail address is already used by another account.');
    }
    if (!in_array($f['role'], ['admin', 'leader'], true)) $err[] = t('Unknown role.');
    if (!isset(CAT_LANGS[$f['language']])) $err[] = t('Unknown language.');
    return $err;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $f = [
        'name' => trim((string)($_POST['name'] ?? '')),
        'username' => trim((string)($_POST['username'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')),
        'role' => (string)($_POST['role'] ?? 'leader'),
        'language' => (string)($_POST['language'] ?? 'en'),
    ];
    $pw = (string)($_POST['password'] ?? '');

    if ($action === 'create') {
        $new = $f;
        $errors = validate_user($f);
        if (strlen($pw) < 10) $errors[] = t('The starting password must have at least 10 characters.');
        if (!$errors) {
            q('INSERT INTO {users} (username, name, email, password_hash, role, language, must_change_password, is_active, created_at)
               VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?)',
                [$f['username'], $f['name'], $f['email'] ?: null, password_hash($pw, PASSWORD_DEFAULT), $f['role'], $f['language'], now_utc()]);
            flash('success', t('Account "%s" created. Give the user name and starting password to the person; they choose their own password at the first login.', $f['username']));
            redirect(url('admin/users.php'));
        }
    }

    if ($edit && $action === 'update') {
        $errors = validate_user($f, (int)$edit['id']);
        $active = !empty($_POST['is_active']) ? 1 : 0;
        $isSelf = (int)$edit['id'] === (int)$me['id'];
        if ($isSelf && ($f['role'] !== 'admin' || !$active)) {
            $errors[] = t('You cannot remove your own administrator rights or deactivate yourself.');
        }
        if ($edit['role'] === 'admin' && ($f['role'] !== 'admin' || !$active) && active_admins() <= 1) {
            $errors[] = t('At least one active administrator is needed.');
        }
        if (!$errors) {
            q('UPDATE {users} SET name = ?, username = ?, email = ?, role = ?, language = ?, is_active = ? WHERE id = ?',
                [$f['name'], $f['username'], $f['email'] ?: null, $f['role'], $f['language'], $active, $edit['id']]);
            flash('success', t('Account saved.'));
            redirect(url('admin/users.php'));
        }
        $edit = array_merge($edit, $f, ['is_active' => $active]);
    }

    if ($edit && $action === 'reset') {
        if (strlen($pw) < 10) {
            $errors[] = t('The starting password must have at least 10 characters.');
        } else {
            q('UPDATE {users} SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $edit['id']]);
            flash('success', t('New starting password set for "%s". They choose their own password at the next login.', $edit['username']));
            redirect(url('admin/users.php'));
        }
    }

    if ($edit && $action === 'delete') {
        $to = (int)($_POST['transfer_to'] ?? 0);
        $owned = (int)q_val('SELECT COUNT(*) FROM {tests} WHERE owner_id = ?', [$edit['id']])
               + (int)q_val('SELECT COUNT(*) FROM {item_banks} WHERE owner_id = ?', [$edit['id']]);
        if ((int)$edit['id'] === (int)$me['id']) {
            $errors[] = t('You cannot delete your own account.');
        } elseif ($edit['role'] === 'admin' && $edit['is_active'] && active_admins() <= 1) {
            $errors[] = t('At least one active administrator is needed.');
        } elseif (empty($_POST['confirm'])) {
            $errors[] = t('Tick the confirmation box to delete the account.');
        } elseif ($owned && (!$to || $to === (int)$edit['id'] || !q_val('SELECT id FROM {users} WHERE id = ? AND is_active = 1', [$to]))) {
            $errors[] = t('Choose the account that takes over the tests and item banks.');
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            if ($owned) {
                q('UPDATE {tests} SET owner_id = ? WHERE owner_id = ?', [$to, $edit['id']]);
                q('UPDATE {item_banks} SET owner_id = ? WHERE owner_id = ?', [$to, $edit['id']]);
            }
            q('DELETE FROM {users} WHERE id = ?', [$edit['id']]);
            $pdo->commit();
            flash('success', t('Account "%s" deleted.', $edit['username']));
            redirect(url('admin/users.php'));
        }
    }
}

$users = q_all('SELECT u.*,
    (SELECT COUNT(*) FROM {tests} t WHERE t.owner_id = u.id) AS n_tests,
    (SELECT COUNT(*) FROM {item_banks} b WHERE b.owner_id = u.id) AS n_banks
    FROM {users} u ORDER BY u.is_active DESC, u.role, u.name');

admin_start($edit ? t('Account: %s', $edit['name']) : t('Accounts'), 'users');
foreach ($errors as $e) {
    echo '<div class="alert alert-error">' . h($e) . '</div>';
}

$roleSelect = function (string $current): string {
    $out = '';
    foreach (['leader', 'admin'] as $r) {
        $out .= '<option value="' . $r . '"' . ($r === $current ? ' selected' : '') . '>' . h(role_label($r)) . '</option>';
    }
    return $out;
};
$langSelect = function (string $current): string {
    $out = '';
    foreach (CAT_LANGS as $code => $name) {
        $out .= '<option value="' . $code . '"' . ($code === $current ? ' selected' : '') . '>' . h($name) . '</option>';
    }
    return $out;
};

if ($edit):
    $owned = (int)q_val('SELECT COUNT(*) FROM {tests} WHERE owner_id = ?', [$edit['id']])
           + (int)q_val('SELECT COUNT(*) FROM {item_banks} WHERE owner_id = ?', [$edit['id']]);
?>
<form method="post" class="form panel">
  <?= csrf_field() ?><input type="hidden" name="action" value="update">
  <div class="row2">
    <label><?= te('Name') ?><input name="name" value="<?= h($edit['name']) ?>" required maxlength="255"></label>
    <label><?= te('User name') ?><input name="username" value="<?= h($edit['username']) ?>" required maxlength="64"></label>
  </div>
  <div class="row3">
    <label><?= te('E-mail (optional)') ?><input type="email" name="email" value="<?= h((string)$edit['email']) ?>"></label>
    <label><?= te('Role') ?><select name="role"><?= $roleSelect($edit['role']) ?></select></label>
    <label><?= te('Interface language') ?><select name="language"><?= $langSelect($edit['language']) ?></select></label>
  </div>
  <label class="check"><input type="checkbox" name="is_active" value="1"<?= $edit['is_active'] ? ' checked' : '' ?>> <?= te('Active (can log in)') ?></label>
  <div class="actions">
    <button class="btn btn-primary" type="submit"><?= te('Save') ?></button>
    <a class="btn" href="<?= h(url('admin/users.php')) ?>"><?= te('Back') ?></a>
  </div>
</form>

<section class="section">
  <h2><?= te('Reset password') ?></h2>
  <form method="post" class="form panel inline-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="reset">
    <label><?= te('New starting password') ?> <input type="text" name="password" minlength="10" required autocomplete="off"></label>
    <button class="btn" type="submit"><?= te('Set starting password') ?></button>
  </form>
</section>

<?php if ((int)$edit['id'] !== (int)$me['id']): ?>
<div class="panel danger-zone">
  <h2><?= te('Delete account') ?></h2>
  <form method="post" class="inline-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete">
    <?php if ($owned): ?>
      <label><?= te('Transfer tests and item banks to') ?>
        <select name="transfer_to">
          <?php foreach (users_for_select() as $u): if ((int)$u['id'] === (int)$edit['id']) continue; ?>
            <option value="<?= (int)$u['id'] ?>"<?= (int)$u['id'] === (int)$me['id'] ? ' selected' : '' ?>><?= h($u['name']) ?> (<?= h($u['username']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>
    <label class="check"><input type="checkbox" name="confirm" value="1"> <?= te('Delete this account') ?></label>
    <button class="btn btn-danger" type="submit"><?= te('Delete') ?></button>
  </form>
  <p class="muted small"><?= te('Results stay with the tests. To keep the account but block logins, untick “Active” instead.') ?></p>
</div>
<?php endif; ?>

<?php else: ?>
<div class="table-wrap">
<table>
  <thead><tr><th><?= te('Name') ?></th><th><?= te('User name') ?></th><th><?= te('Role') ?></th><th><?= te('Owns') ?></th><th><?= te('Last login') ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr<?= $u['is_active'] ? '' : ' class="inactive"' ?>>
      <td><strong><?= h($u['name']) ?></strong><?php if ($u['email']): ?><div class="muted small"><?= h($u['email']) ?></div><?php endif; ?></td>
      <td><code><?= h($u['username']) ?></code></td>
      <td><?= h(role_label($u['role'])) ?><?= $u['is_active'] ? '' : '<div><span class="pill off">' . te('Inactive') . '</span></div>' ?><?= $u['must_change_password'] ? '<div class="muted small">' . te('Starting password') . '</div>' : '' ?></td>
      <td class="small"><?= te('%1$d tests, %2$d item banks', (int)$u['n_tests'], (int)$u['n_banks']) ?></td>
      <td class="small nowrap"><?= $u['last_login_at'] ? h(fmt_dt($u['last_login_at'])) : '<span class="muted">' . te('Never') . '</span>' ?></td>
      <td><a class="btn btn-sm" href="<?= h(url('admin/users.php?edit=' . $u['id'])) ?>"><?= te('Edit') ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<section class="section">
  <h2><?= te('New account') ?></h2>
  <p class="muted"><?= te('Project leaders can upload item banks, create tests and download the results of their own tests. Administrators can see and manage everything, including accounts.') ?></p>
  <form method="post" class="form panel" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="row2">
      <label><?= te('Name') ?><input name="name" value="<?= h($new['name']) ?>" required maxlength="255"></label>
      <label><?= te('User name') ?><input name="username" value="<?= h($new['username']) ?>" required maxlength="64" autocomplete="off"></label>
    </div>
    <div class="row3">
      <label><?= te('E-mail (optional)') ?><input type="email" name="email" value="<?= h($new['email']) ?>"></label>
      <label><?= te('Role') ?><select name="role"><?= $roleSelect($new['role']) ?></select></label>
      <label><?= te('Interface language') ?><select name="language"><?= $langSelect($new['language']) ?></select></label>
    </div>
    <label><?= te('Starting password') ?> <small><?= te('At least 10 characters. The person must change it at the first login.') ?></small>
      <input type="text" name="password" minlength="10" required autocomplete="off"></label>
    <button class="btn btn-primary" type="submit"><?= te('Create account') ?></button>
  </form>
</section>
<?php endif;
admin_end();

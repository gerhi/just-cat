<?php
declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    csrf_check();
    if ($test = load_test((int)($_POST['id'] ?? 0))) {
        q('UPDATE {tests} SET is_active = 1 - is_active WHERE id = ?', [$test['id']]);
    }
    redirect(url('admin/tests.php'));
}

[$scope, $params] = scope_tests('t');
$tests = q_all(
    "SELECT t.*, b.name AS bank_name, u.name AS owner_name,
        (SELECT COUNT(*) FROM {sessions} s WHERE s.test_id = t.id) AS n_sessions,
        (SELECT COUNT(*) FROM {sessions} s WHERE s.test_id = t.id AND s.status = 'completed') AS n_completed
     FROM {tests} t JOIN {item_banks} b ON b.id = t.bank_id LEFT JOIN {users} u ON u.id = t.owner_id
     WHERE 1 = 1 $scope ORDER BY t.created_at DESC",
    $params
);
[$bScope, $bParams] = scope_banks('b');
$nBanks = (int)q_val("SELECT COUNT(*) FROM {item_banks} b WHERE 1 = 1 $bScope", $bParams);

$actions = $nBanks ? '<a class="btn btn-primary" href="' . h(url('admin/test_edit.php')) . '">' . te('New test') . '</a>' : '';
admin_start(t('Tests'), 'tests', $actions);
?>
<?php if (!$nBanks): ?>
  <div class="empty">
    <p><?= te('A test needs an item bank. Upload your own CSV file or add one of the examples, then create the test here.') ?></p>
    <p><a class="btn btn-primary" href="<?= h(url('admin/banks.php')) ?>"><?= te('Go to item banks') ?></a></p>
  </div>
<?php elseif (!$tests): ?>
  <div class="empty">
    <p><?= te('No tests yet. Create a test to get a link for participants.') ?></p>
    <p><a class="btn btn-primary" href="<?= h(url('admin/test_edit.php')) ?>"><?= te('New test') ?></a></p>
  </div>
<?php else: ?>
<div class="tests">
  <?php foreach ($tests as $t): ?>
  <article class="test-row">
    <div>
      <h2><a href="<?= h(url('admin/test_edit.php?id=' . $t['id'])) ?>"><?= h($t['name']) ?></a></h2>
      <div class="meta">
        <?php if (is_admin()): ?><span><?= te('Owner') ?> <b><?= h($t['owner_name'] ?? '–') ?></b></span><?php endif; ?>
        <span><?= te('Item bank') ?> <b><?= h($t['bank_name']) ?></b></span>
        <span><?= (float)$t['sem_threshold'] > 0
            ? tm('Stops after <b>%1$d items</b> or at <b>SEM ≤ %2$s</b>', (int)$t['max_items'], fmt_num($t['sem_threshold'], 2))
            : tm('Stops after <b>%d items</b>', (int)$t['max_items']) ?></span>
        <span><?= te('Selection') ?> <b><?= h(method_label($t['selection_method'] ?? 'mfi', true)) ?><?= (int)($t['randomesque_k'] ?? 1) > 1 ? ', k = ' . (int)$t['randomesque_k'] : '' ?></b></span>
        <span><?= te('Language') ?> <b><?= h(CAT_LANGS[$t['language']] ?? $t['language']) ?></b></span>
      </div>
      <div class="copyrow">
        <input class="copy-src" readonly value="<?= h(test_abs_url($t['slug'])) ?>" aria-label="<?= te('Link for participants') ?>">
        <button type="button" class="btn btn-sm" data-copy data-copied="<?= te('Copied') ?>"><?= te('Copy link') ?></button>
      </div>
      <?php if ($t['require_ref']): ?><span class="tag"><?= te('Starts only with a reference token') ?></span><?php endif; ?>
      <?php if ($t['callback_url']): ?><span class="tag"><?= te('Returns to the survey') ?></span><?php endif; ?>
    </div>
    <div class="side">
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <button type="submit" class="pill <?= $t['is_active'] ? 'on' : 'off' ?>" title="<?= $t['is_active'] ? te('Click to deactivate') : te('Click to activate') ?>"><?= $t['is_active'] ? te('Active') : te('Inactive') ?></button>
      </form>
      <div class="counts"><strong><?= (int)$t['n_completed'] ?></strong> <?= te('completed of %d started', (int)$t['n_sessions']) ?></div>
      <div class="actions">
        <a class="btn btn-sm" href="<?= h(url('admin/test_edit.php?id=' . $t['id'])) ?>"><?= te('Edit') ?></a>
        <a class="btn btn-sm" href="<?= h(url('admin/sessions.php?test_id=' . $t['id'])) ?>"><?= te('Sessions') ?></a>
        <a class="btn btn-sm" href="<?= h(url('admin/export.php?test_id=' . $t['id'])) ?>"><?= te('Export') ?></a>
      </div>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php
admin_end();

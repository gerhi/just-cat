<?php
/**
 * Overview: what exists and what is happening — tests, item banks, recent
 * sessions and (for administrators) accounts. Project leaders see their own
 * tests and the item banks available to them.
 */

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
$user = require_login();

[$tScope, $tParams] = scope_tests('t');
[$bScope, $bParams] = scope_banks('b');
$week = gmdate('Y-m-d H:i:s', time() - 7 * 24 * 3600);

$tests = q_all(
    "SELECT t.*, b.name AS bank_name, u.name AS owner_name,
        (SELECT COUNT(*) FROM {sessions} s WHERE s.test_id = t.id) AS n_started,
        (SELECT COUNT(*) FROM {sessions} s WHERE s.test_id = t.id AND s.status = 'completed') AS n_completed,
        (SELECT MAX(s.last_activity) FROM {sessions} s WHERE s.test_id = t.id) AS last_activity
     FROM {tests} t JOIN {item_banks} b ON b.id = t.bank_id LEFT JOIN {users} u ON u.id = t.owner_id
     WHERE 1 = 1 $tScope ORDER BY t.is_active DESC, t.created_at DESC",
    $tParams
);
$banks = q_all(
    "SELECT b.*, u.name AS owner_name,
        (SELECT COUNT(*) FROM {items} i WHERE i.bank_id = b.id) AS n_items,
        (SELECT COUNT(*) FROM {tests} t WHERE t.bank_id = b.id) AS n_tests
     FROM {item_banks} b LEFT JOIN {users} u ON u.id = b.owner_id
     WHERE 1 = 1 $bScope ORDER BY b.created_at DESC",
    $bParams
);
$models = [];
foreach (q_all('SELECT bank_id, model, COUNT(*) AS n FROM {items} GROUP BY bank_id, model') as $r) {
    $models[$r['bank_id']][] = $r['n'] . ' ' . $r['model'];
}
$completed = (int)q_val("SELECT COUNT(*) FROM {sessions} s JOIN {tests} t ON t.id = s.test_id WHERE s.status = 'completed' $tScope", $tParams);
$completedWeek = (int)q_val("SELECT COUNT(*) FROM {sessions} s JOIN {tests} t ON t.id = s.test_id WHERE s.status = 'completed' AND s.completed_at >= ? $tScope", array_merge([$week], $tParams));
$recent = q_all(
    "SELECT s.*, t.name AS test_name FROM {sessions} s JOIN {tests} t ON t.id = s.test_id
     WHERE 1 = 1 $tScope ORDER BY s.last_activity DESC LIMIT 8",
    $tParams
);
$accounts = is_admin() ? q_all(
    'SELECT u.*, (SELECT COUNT(*) FROM {tests} t WHERE t.owner_id = u.id) AS n_tests,
            (SELECT COUNT(*) FROM {item_banks} b WHERE b.owner_id = u.id) AS n_banks
     FROM {users} u WHERE u.is_active = 1 ORDER BY u.role, u.name'
) : [];
$nActive = count(array_filter($tests, fn($t) => (int)$t['is_active'] === 1));

admin_start(t('Overview'), 'overview');
?>
<p class="lead"><?= is_admin()
    ? te('You are signed in as an administrator and see all tests, item banks and accounts.')
    : te('You are signed in as a project leader. You see your own tests and the item banks you can use.') ?></p>

<dl class="figures panel">
  <div><dt><?= te('Active tests') ?></dt><dd><?= $nActive ?><span class="of"> / <?= count($tests) ?></span></dd></div>
  <div><dt><?= te('Completed sessions') ?></dt><dd><?= $completed ?></dd></div>
  <div><dt><?= te('Completed in the last 7 days') ?></dt><dd><?= $completedWeek ?></dd></div>
  <div><dt><?= te('Item banks') ?></dt><dd><?= count($banks) ?></dd></div>
  <?php if (is_admin()): ?><div><dt><?= te('Accounts') ?></dt><dd><?= count($accounts) ?></dd></div><?php endif; ?>
</dl>

<?php if (!$tests && !$banks): ?>
<section class="section">
  <h2><?= te('Getting started') ?></h2>
  <ol class="steps">
    <li><?= tm('Add an item bank: upload a CSV file or use one of the examples under <a href="%s">Item banks</a>.', url('admin/banks.php')) ?></li>
    <li><?= tm('Create a test under <a href="%s">Tests</a> and choose the item bank, the stopping rule and the item selection.', url('admin/tests.php')) ?></li>
    <li><?= te('Activate the test and send the link to the participants, or put it into your survey.') ?></li>
    <li><?= tm('Download the results under <a href="%s">Export</a>.', url('admin/export.php')) ?></li>
  </ol>
</section>
<?php endif; ?>

<section class="section">
  <div class="section-head">
    <h2><?= te('Tests') ?></h2>
    <a href="<?= h(url('admin/tests.php')) ?>"><?= te('All tests') ?></a>
  </div>
  <?php if (!$tests): ?>
    <p class="muted"><?= te('No tests yet.') ?></p>
  <?php else: ?>
  <div class="table-wrap">
  <table>
    <thead><tr>
      <th><?= te('Test') ?></th><?php if (is_admin()): ?><th><?= te('Owner') ?></th><?php endif; ?>
      <th><?= te('Item bank') ?></th><th><?= te('Status') ?></th><th class="num"><?= te('Completed') ?></th><th class="num"><?= te('Started') ?></th><th><?= te('Last activity') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($tests as $t): ?>
      <tr>
        <td><a class="rowlink" href="<?= h(url('admin/test_edit.php?id=' . $t['id'])) ?>"><?= h($t['name']) ?></a>
          <div class="muted small"><?= h(method_label($t['selection_method'] ?? 'mfi', true)) ?><?= (int)($t['randomesque_k'] ?? 1) > 1 ? ', k = ' . (int)$t['randomesque_k'] : '' ?>, <?= h(CAT_LANGS[$t['language']] ?? $t['language']) ?></div></td>
        <?php if (is_admin()): ?><td><?= h($t['owner_name'] ?? '–') ?></td><?php endif; ?>
        <td><?= h($t['bank_name']) ?></td>
        <td><span class="pill <?= $t['is_active'] ? 'on' : 'off' ?>"><?= $t['is_active'] ? te('Active') : te('Inactive') ?></span></td>
        <td class="num"><a href="<?= h(url('admin/sessions.php?test_id=' . $t['id'] . '&status=completed')) ?>"><?= (int)$t['n_completed'] ?></a></td>
        <td class="num"><?= (int)$t['n_started'] ?></td>
        <td class="small nowrap"><?= $t['last_activity'] ? h(fmt_dt($t['last_activity'])) : '<span class="muted">' . te('None yet') . '</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<section class="section">
  <div class="section-head">
    <h2><?= te('Item banks') ?></h2>
    <a href="<?= h(url('admin/banks.php')) ?>"><?= te('All item banks') ?></a>
  </div>
  <?php if (!$banks): ?>
    <p class="muted"><?= te('No item banks yet.') ?></p>
  <?php else: ?>
  <div class="table-wrap">
  <table>
    <thead><tr><th><?= te('Item bank') ?></th><th><?= te('Owner') ?></th><th class="num"><?= te('Items') ?></th><th><?= te('Models') ?></th><th class="num"><?= te('Used by tests') ?></th></tr></thead>
    <tbody>
    <?php foreach ($banks as $b): ?>
      <tr>
        <td><a class="rowlink" href="<?= h(url('admin/bank.php?id=' . $b['id'])) ?>"><?= h($b['name']) ?></a></td>
        <td><?= $b['is_example'] ? '<span class="tag">' . te('Shared') . '</span>' : h($b['owner_name'] ?? '–') ?></td>
        <td class="num"><?= (int)$b['n_items'] ?></td>
        <td class="small"><?= h(implode(', ', $models[$b['id']] ?? [])) ?></td>
        <td class="num"><?= (int)$b['n_tests'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<?php if ($recent): ?>
<section class="section">
  <div class="section-head">
    <h2><?= te('Recent sessions') ?></h2>
    <a href="<?= h(url('admin/sessions.php')) ?>"><?= te('All sessions') ?></a>
  </div>
  <div class="table-wrap">
  <table>
    <thead><tr><th><?= te('Test') ?></th><th><?= te('Reference') ?></th><th><?= te('Status') ?></th><th class="num"><?= te('Items') ?></th><th><?= te('Last activity') ?></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $s): $st = effective_status($s); ?>
      <tr>
        <td><a class="rowlink" href="<?= h(url('admin/session.php?id=' . $s['id'])) ?>"><?= h($s['test_name']) ?></a></td>
        <td><code><?= h($s['reference_token'] ?? '–') ?></code></td>
        <td><span class="pill <?= h($st) ?>"><?= h(status_label($st)) ?></span></td>
        <td class="num"><?= (int)$s['items_administered'] ?></td>
        <td class="small nowrap"><?= h(fmt_dt($s['last_activity'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>

<?php if (is_admin()): ?>
<section class="section">
  <div class="section-head">
    <h2><?= te('Accounts') ?></h2>
    <a href="<?= h(url('admin/users.php')) ?>"><?= te('Manage accounts') ?></a>
  </div>
  <div class="table-wrap">
  <table>
    <thead><tr><th><?= te('Name') ?></th><th><?= te('Role') ?></th><th class="num"><?= te('Tests') ?></th><th class="num"><?= te('Item banks') ?></th><th><?= te('Last login') ?></th></tr></thead>
    <tbody>
    <?php foreach ($accounts as $a): ?>
      <tr>
        <td><?= h($a['name']) ?> <span class="muted small"><?= h($a['username']) ?></span></td>
        <td><?= h(role_label($a['role'])) ?></td>
        <td class="num"><?= (int)$a['n_tests'] ?></td>
        <td class="num"><?= (int)$a['n_banks'] ?></td>
        <td class="small nowrap"><?= $a['last_login_at'] ? h(fmt_dt($a['last_login_at'])) : '<span class="muted">' . te('Never') . '</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif;
admin_end();

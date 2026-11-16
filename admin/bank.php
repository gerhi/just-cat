<?php
declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/charts.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$bank = load_bank($id);
if (!$bank) {
    flash('error', t('Item bank not found.'));
    redirect(url('admin/banks.php'));
}
$editable = can_edit($bank);
$nTestsAll = (int)q_val('SELECT COUNT(*) FROM {tests} WHERE bank_id = ?', [$id]);
[$tScope, $tParams] = scope_tests('t');
$myTests = q_all("SELECT t.id, t.name FROM {tests} t WHERE t.bank_id = ? $tScope ORDER BY t.name", array_merge([$id], $tParams));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$editable) {
        forbidden();
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'update') {
        $name = trim((string)($_POST['name'] ?? ''));
        $owner = is_admin() ? (int)($_POST['owner_id'] ?? 0) : (int)$bank['owner_id'];
        $shared = is_admin() ? (!empty($_POST['is_example']) ? 1 : 0) : (int)$bank['is_example'];
        if ($name === '') {
            flash('error', t('Name is required.'));
        } elseif (is_admin() && !q_val('SELECT id FROM {users} WHERE id = ?', [$owner])) {
            flash('error', t('Choose an owner.'));
        } else {
            q('UPDATE {item_banks} SET name = ?, owner_id = ?, is_example = ? WHERE id = ?', [$name, $owner, $shared, $id]);
            flash('success', t('Item bank saved.'));
        }
    } elseif ($action === 'delete') {
        if ($nTestsAll) {
            flash('error', t('This item bank is used by tests. Delete those tests first.'));
        } elseif (empty($_POST['confirm'])) {
            flash('error', t('Tick the confirmation box to delete the item bank.'));
        } else {
            q('DELETE FROM {item_banks} WHERE id = ?', [$id]);
            flash('success', t('Item bank “%s” deleted.', $bank['name']));
            redirect(url('admin/banks.php'));
        }
    }
    redirect(url('admin/bank.php?id=' . $id));
}

$items = q_all('SELECT * FROM {items} WHERE bank_id = ? ORDER BY position', [$id]);
$maxInfo = 0.0;
for ($t = -4.0; $t <= 4.0001; $t += 0.05) {
    $maxInfo = max($maxInfo, array_sum(array_map(fn($it) => cat_info($t, engine_item($it)), $items)));
}
$L = texts(i18n_lang());
$owner = $bank['owner_id'] ? q_one('SELECT name FROM {users} WHERE id = ?', [$bank['owner_id']]) : null;
$typeLabel = fn(string $t) => match ($t) { 'dichotomous' => t('true/false'), 'multiple_choice' => t('multiple choice'), default => t('Likert') };

admin_start($bank['name'], 'banks', '<a class="btn" href="' . h(url('admin/test_edit.php?bank_id=' . $id)) . '">' . te('Create a test with this bank') . '</a>');
?>
<p class="muted">
  <?= te('%1$d items, added %2$s from %3$s.', count($items), fmt_dt($bank['created_at']), (string)$bank['source_filename']) ?>
  <?= $bank['is_example'] ? te('Shared, available to all accounts.') : te('Owner: %s.', $owner['name'] ?? '–') ?>
  <?php if ($myTests): ?>
    <?= te('Used by:') ?> <?php foreach ($myTests as $i => $t): ?><?= $i ? ', ' : '' ?><a href="<?= h(url('admin/test_edit.php?id=' . $t['id'])) ?>"><?= h($t['name']) ?></a><?php endforeach; ?>.
  <?php endif; ?>
  <?php if ($nTestsAll > count($myTests)): ?><?= te('Tests of other accounts using it: %d.', $nTestsAll - count($myTests)) ?><?php endif; ?>
</p>

<figure class="panel figure">
  <?= info_curve_svg(array_map('engine_item', $items)) ?>
  <figcaption><?= te('Test information if all %d items were given (SE = 1/√information).', count($items)) ?>
    <?= $maxInfo >= 1 / 0.16
        ? te('Above a dashed line, the full bank reaches that SEM; a CAT gives fewer items, so its SEM is somewhat higher.')
        : te('Even with all items this bank does not reach SEM 0.40 anywhere (lowest possible SEM about %s). Set the SEM threshold above that or stop by the number of items.', number_format(1 / sqrt(max($maxInfo, 1e-9)), 2)) ?>
  </figcaption>
</figure>

<div class="table-wrap">
<table class="items">
  <thead><tr><th>#</th><th><?= te('ID') ?></th><th><?= te('Text') ?></th><th><?= te('Type') ?></th><th><?= te('Model') ?></th><th>a</th><th><?= te('b / thresholds') ?></th><th>c</th><th><?= te('Answers') ?></th></tr></thead>
  <tbody>
  <?php foreach ($items as $it):
      $b = json_decode($it['b'], true);
      $labels = item_labels($it, $L);
  ?>
    <tr>
      <td class="muted"><?= (int)$it['position'] ?></td>
      <td><code><?= h($it['item_ref']) ?></code></td>
      <td><?= h($it['question_text']) ?></td>
      <td class="small"><?= h($typeLabel($it['response_type'])) ?><?= $it['reverse_scored'] ? '<br><span class="tag">' . te('reversed') . '</span>' : '' ?></td>
      <td><?= h($it['model']) ?></td>
      <td><?= h(fmt_num($it['a'], 2)) ?></td>
      <td class="nowrap"><?= h(is_array($b) ? implode(' | ', array_map(fn($x) => fmt_num($x, 2), $b)) : fmt_num($b, 2)) ?></td>
      <td><?= $it['model'] === '3PL' ? h(fmt_num($it['c'], 2)) : '' ?></td>
      <td class="small">
        <?php foreach ($labels as $i => $lab): ?>
          <span class="<?= $it['response_type'] !== 'likert' && $i + 1 === (int)$it['correct_option'] ? 'correct' : '' ?>"><?= $i + 1 ?>: <?= h($lab) ?></span><br>
        <?php endforeach; ?>
        <?php if ($it['response_type'] === 'likert' && ($it['likert_min_label'] || $it['likert_max_label'])): ?>
          <span class="muted"><?= h($it['likert_min_label']) ?> … <?= h($it['likert_max_label']) ?></span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="muted small"><?= te('Highlighted answers are scored 1. Items cannot be edited here: fix the CSV file and upload it as a new item bank.') ?></p>

<?php if ($editable): ?>
<section class="section">
  <h2><?= te('Settings') ?></h2>
  <form method="post" class="form panel">
    <?= csrf_field() ?><input type="hidden" name="action" value="update">
    <div class="row2">
      <label><?= te('Name') ?> <input name="name" value="<?= h($bank['name']) ?>" required></label>
      <?php if (is_admin()): ?>
        <label><?= te('Owner') ?>
          <select name="owner_id">
            <?php foreach (users_for_select() as $u): ?>
              <option value="<?= (int)$u['id'] ?>"<?= (int)$u['id'] === (int)$bank['owner_id'] ? ' selected' : '' ?>><?= h($u['name']) ?> (<?= h(role_label($u['role'])) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
    </div>
    <?php if (is_admin()): ?>
      <label class="check"><input type="checkbox" name="is_example" value="1"<?= $bank['is_example'] ? ' checked' : '' ?>> <?= te('Shared: all project leaders can use this item bank for their tests') ?></label>
    <?php endif; ?>
    <button class="btn" type="submit"><?= te('Save') ?></button>
  </form>
</section>

<div class="panel danger-zone">
  <h2><?= te('Delete item bank') ?></h2>
  <?php if ($nTestsAll): ?>
    <p class="muted"><?= te('This item bank is used by %d tests and cannot be deleted.', $nTestsAll) ?></p>
  <?php else: ?>
    <form method="post" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete">
      <label class="check"><input type="checkbox" name="confirm" value="1"> <?= te('Delete this item bank') ?></label>
      <button class="btn btn-danger" type="submit"><?= te('Delete') ?></button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php
admin_end();

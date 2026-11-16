<?php
declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_login();

const PER_PAGE = 100;

$testId = (int)($_GET['test_id'] ?? 0);
$status = (string)($_GET['status'] ?? '');
$ref = trim((string)($_GET['ref'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));

[$scope, $where] = scope_tests('t');
$cond = "1 = 1 $scope";
$params = $where;
if ($testId) {
    $cond .= ' AND s.test_id = ?';
    $params[] = $testId;
}
$cutoff = gmdate('Y-m-d H:i:s', time() - (int)cfg('abandon_after_minutes', 120) * 60);
if ($status === 'completed') {
    $cond .= " AND s.status = 'completed'";
} elseif ($status === 'in_progress') {
    $cond .= " AND s.status = 'in_progress' AND s.last_activity >= ?";
    $params[] = $cutoff;
} elseif ($status === 'abandoned') {
    $cond .= " AND s.status = 'in_progress' AND s.last_activity < ?";
    $params[] = $cutoff;
}
if ($ref !== '') {
    $cond .= ' AND s.reference_token = ?';
    $params[] = $ref;
}

$total = (int)q_val("SELECT COUNT(*) FROM {sessions} s JOIN {tests} t ON t.id = s.test_id WHERE $cond", $params);
$offset = ($page - 1) * PER_PAGE;
$rows = q_all(
    "SELECT s.*, t.name AS test_name FROM {sessions} s JOIN {tests} t ON t.id = s.test_id
     WHERE $cond ORDER BY s.started_at DESC, s.id DESC LIMIT " . PER_PAGE . " OFFSET $offset",
    $params
);
$tests = q_all("SELECT t.id, t.name FROM {tests} t WHERE 1 = 1 $scope ORDER BY t.name", $where);

function page_link(int $p): string
{
    $qs = $_GET;
    $qs['page'] = $p;
    return url('admin/sessions.php?' . http_build_query($qs));
}

admin_start(t('Sessions'), 'sessions');
?>
<form method="get" class="filters">
  <select name="test_id" aria-label="<?= te('Test') ?>">
    <option value=""><?= te('All tests') ?></option>
    <?php foreach ($tests as $t): ?>
      <option value="<?= (int)$t['id'] ?>"<?= (int)$t['id'] === $testId ? ' selected' : '' ?>><?= h($t['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="status" aria-label="<?= te('Status') ?>">
    <option value=""><?= te('Any status') ?></option>
    <?php foreach (['completed', 'in_progress', 'abandoned'] as $k): ?>
      <option value="<?= $k ?>"<?= $k === $status ? ' selected' : '' ?>><?= h(status_label($k)) ?></option>
    <?php endforeach; ?>
  </select>
  <input name="ref" value="<?= h($ref) ?>" placeholder="<?= te('Reference token (exact)') ?>" aria-label="<?= te('Reference token') ?>">
  <button class="btn" type="submit"><?= te('Filter') ?></button>
  <?php if ($testId || $status || $ref): ?><a class="btn" href="<?= h(url('admin/sessions.php')) ?>"><?= te('Reset') ?></a><?php endif; ?>
</form>

<p class="muted"><?= te('%1$d sessions. “Abandoned” means no answer for %2$d minutes.', $total, (int)cfg('abandon_after_minutes', 120)) ?></p>

<?php if ($rows): ?>
<div class="table-wrap">
<table>
  <thead><tr><th><?= te('ID') ?></th><th><?= te('Test') ?></th><th><?= te('Reference') ?></th><th><?= te('Status') ?></th><th class="num"><?= te('Items') ?></th><th class="num">θ</th><th class="num">SEM</th><th><?= te('Stopped by') ?></th><th><?= te('Started') ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $s): $st = effective_status($s); ?>
    <tr>
      <td><?= (int)$s['id'] ?></td>
      <td><?= h($s['test_name']) ?></td>
      <td><code><?= h($s['reference_token'] ?? '–') ?></code></td>
      <td><span class="pill <?= h($st) ?>"><?= h(status_label($st)) ?></span></td>
      <td class="num"><?= (int)$s['items_administered'] ?></td>
      <td class="num"><?= h(fmt_num($s['theta'])) ?></td>
      <td class="num"><?= h(fmt_num($s['sem'])) ?></td>
      <td class="small"><?= h(reason_label($s['stopping_reason'])) ?></td>
      <td class="small nowrap"><?= h(fmt_dt($s['started_at'])) ?></td>
      <td><a class="btn btn-sm" href="<?= h(url('admin/session.php?id=' . $s['id'])) ?>"><?= te('Details') ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($total > PER_PAGE): ?>
  <p class="pager">
    <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= h(page_link($page - 1)) ?>"><?= te('Newer') ?></a><?php endif; ?>
    <?= te('Page %1$d of %2$d', $page, (int)ceil($total / PER_PAGE)) ?>
    <?php if ($offset + PER_PAGE < $total): ?><a class="btn btn-sm" href="<?= h(page_link($page + 1)) ?>"><?= te('Older') ?></a><?php endif; ?>
  </p>
<?php endif; ?>
<?php endif; ?>
<?php
admin_end();

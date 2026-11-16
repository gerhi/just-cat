<?php
declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$s = q_one('SELECT s.*, t.name AS test_name, t.slug, t.owner_id FROM {sessions} s JOIN {tests} t ON t.id = s.test_id WHERE s.id = ?', [$id]);
if (!$s) {
    flash('error', t('Session not found.'));
    redirect(url('admin/sessions.php'));
}
if (!can_edit($s)) {
    forbidden();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_check();
    if (empty($_POST['confirm'])) {
        flash('error', t('Tick the confirmation box to delete this session.'));
        redirect(url('admin/session.php?id=' . $id));
    }
    q('DELETE FROM {sessions} WHERE id = ?', [$id]);
    flash('success', t('Session %d and its answers were deleted.', $id));
    redirect(url('admin/sessions.php?test_id=' . $s['test_id']));
}

$responses = q_all(
    'SELECT r.*, i.item_ref, i.question_text, i.model
     FROM {responses} r JOIN {items} i ON i.id = r.item_id
     WHERE r.session_id = ? ORDER BY r.position',
    [$id]
);
$st = effective_status($s);

admin_start(t('Session %d', $id), 'sessions');
?>
<div class="panel">
  <dl class="facts">
    <dt><?= te('Test') ?></dt><dd><?= h($s['test_name']) ?></dd>
    <dt><?= te('Reference token') ?></dt><dd><code><?= h($s['reference_token'] ?? '–') ?></code></dd>
    <dt><?= te('Status') ?></dt><dd><span class="pill <?= h($st) ?>"><?= h(status_label($st)) ?></span>
      <?php if ($s['stopping_reason']): ?> <?= te('Stopped by: %s', reason_label($s['stopping_reason'])) ?><?php endif; ?></dd>
    <dt>θ (EAP)</dt><dd><?= h(fmt_num($s['theta'], 4)) ?></dd>
    <dt>SEM</dt><dd><?= h(fmt_num($s['sem'], 4)) ?></dd>
    <dt><?= te('Items answered') ?></dt><dd><?= (int)$s['items_administered'] ?></dd>
    <dt><?= te('Item selection') ?></dt><dd><?= h(method_label($s['selection_method'] ?? 'mfi')) ?>, k = <?= (int)($s['randomesque_k'] ?? 1) ?></dd>
    <dt><?= te('Started') ?></dt><dd><?= h(fmt_dt($s['started_at'])) ?></dd>
    <dt><?= te('Last activity') ?></dt><dd><?= h(fmt_dt($s['last_activity'])) ?></dd>
    <dt><?= te('Completed') ?></dt><dd><?= h(fmt_dt($s['completed_at'])) ?></dd>
  </dl>
</div>

<?php if ($responses): ?>
<div class="table-wrap">
<table>
  <thead><tr><th>#</th><th><?= te('Item') ?></th><th><?= te('Text') ?></th><th class="num"><?= te('Answer') ?></th><th class="num"><?= te('Scored') ?></th><th class="num"><?= te('θ after') ?></th><th class="num"><?= te('SEM after') ?></th><th><?= te('Time') ?></th></tr></thead>
  <tbody>
  <?php foreach ($responses as $r): ?>
    <tr>
      <td><?= (int)$r['position'] ?></td>
      <td><code><?= h($r['item_ref']) ?></code><div class="muted small"><?= h($r['model']) ?></div></td>
      <td><?= h($r['question_text']) ?></td>
      <td class="num"><?= (int)$r['raw_response'] ?></td>
      <td class="num"><?= (int)$r['scored_response'] ?></td>
      <td class="num"><?= h(fmt_num($r['theta_after'])) ?></td>
      <td class="num"><?= h(fmt_num($r['sem_after'])) ?></td>
      <td class="small nowrap"><?= h(fmt_dt($r['answered_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="muted small"><?= te('Answer = option number as shown (1 = first option). Scored = value used by the IRT model (0/1, or GRM category 0 to K after reverse scoring).') ?></p>
<?php else: ?>
  <p class="muted"><?= te('No answers yet.') ?></p>
<?php endif; ?>

<div class="panel danger-zone">
  <h2><?= te('Delete (e.g. on a deletion request)') ?></h2>
  <form method="post" class="inline-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete">
    <label class="check"><input type="checkbox" name="confirm" value="1"> <?= te('Permanently delete this session and all its answers') ?></label>
    <button class="btn btn-danger" type="submit"><?= te('Delete') ?></button>
  </form>
</div>
<?php
admin_end();

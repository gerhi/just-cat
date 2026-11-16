<?php
declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$test = $id ? load_test($id) : null;
if ($id && !$test) {
    flash('error', t('Test not found.'));
    redirect(url('admin/tests.php'));
}

// Item banks this user may use (own + shared examples; admins: all). Keep the
// test's current bank in the list even if it would otherwise not be visible.
[$bScope, $bParams] = scope_banks('b');
$banks = q_all("SELECT b.id, b.name, b.is_example, (SELECT COUNT(*) FROM {items} i WHERE i.bank_id = b.id) AS n
                FROM {item_banks} b WHERE 1 = 1 $bScope ORDER BY b.created_at DESC", $bParams);
if ($test && !in_array((int)$test['bank_id'], array_map(fn($b) => (int)$b['id'], $banks), true)) {
    $banks[] = q_one('SELECT b.id, b.name, b.is_example, (SELECT COUNT(*) FROM {items} i WHERE i.bank_id = b.id) AS n
                      FROM {item_banks} b WHERE b.id = ?', [$test['bank_id']]);
}
if (!$banks) {
    flash('warning', t('Add an item bank before creating a test.'));
    redirect(url('admin/banks.php'));
}
$nSessions = $test ? (int)q_val('SELECT COUNT(*) FROM {sessions} WHERE test_id = ?', [$id]) : 0;

$f = $test ?? [
    'name' => '', 'slug' => '', 'bank_id' => (int)($_GET['bank_id'] ?? $banks[0]['id']), 'intro_text' => '', 'language' => i18n_lang(),
    'max_items' => 30, 'sem_threshold' => 0.3, 'callback_url' => '', 'require_ref' => 0, 'is_active' => 0,
    'selection_method' => 'mfi', 'randomesque_k' => 1, 'owner_id' => uid(),
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete' && $test) {
        if (empty($_POST['confirm'])) {
            flash('error', t('Tick the confirmation box to delete the test.'));
            redirect(url('admin/test_edit.php?id=' . $id));
        }
        q('DELETE FROM {tests} WHERE id = ?', [$id]); // sessions + responses cascade
        flash('success', t('Test "%1$s" and its %2$d sessions were deleted.', $test['name'], $nSessions));
        redirect(url('admin/tests.php'));
    }

    if ($action === 'delete_sessions' && $test) {
        if (empty($_POST['confirm_sessions'])) {
            flash('error', t('Tick the confirmation box to delete the sessions.'));
            redirect(url('admin/test_edit.php?id=' . $id));
        }
        q('DELETE FROM {sessions} WHERE test_id = ?', [$id]);
        flash('success', t('%d sessions with all answers were deleted.', $nSessions));
        redirect(url('admin/test_edit.php?id=' . $id));
    }

    $f = [
        'name' => trim((string)($_POST['name'] ?? '')),
        'slug' => strtolower(trim((string)($_POST['slug'] ?? ''))),
        'bank_id' => (int)($_POST['bank_id'] ?? 0),
        'intro_text' => trim(str_replace("\r\n", "\n", (string)($_POST['intro_text'] ?? ''))),
        'language' => (string)($_POST['language'] ?? 'de'),
        'max_items' => trim((string)($_POST['max_items'] ?? '')),
        'sem_threshold' => trim((string)($_POST['sem_threshold'] ?? '')),
        'callback_url' => trim((string)($_POST['callback_url'] ?? '')),
        'require_ref' => !empty($_POST['require_ref']) ? 1 : 0,
        'is_active' => !empty($_POST['is_active']) ? 1 : 0,
        'selection_method' => (string)($_POST['selection_method'] ?? 'mfi'),
        'randomesque_k' => trim((string)($_POST['randomesque_k'] ?? '1')),
        'owner_id' => is_admin() ? (int)($_POST['owner_id'] ?? uid()) : (int)($test['owner_id'] ?? uid()),
    ];

    if ($f['name'] === '') $errors[] = t('Name is required.');
    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $f['slug'])) {
        $errors[] = t('Link name: lowercase letters, digits and hyphens only (e.g. "self-control-2026").');
    } elseif (q_val('SELECT id FROM {tests} WHERE slug = ? AND id <> ?', [$f['slug'], $id])) {
        $errors[] = t('This link name is already used by another test.');
    }
    if (!in_array($f['bank_id'], array_map(fn($b) => (int)$b['id'], $banks), true)) $errors[] = t('Choose an item bank.');
    if ($test && $nSessions > 0 && (int)$test['bank_id'] !== $f['bank_id']) {
        $errors[] = t('The item bank cannot be changed after sessions exist. Create a new test instead.');
    }
    if (!isset(CAT_LANGS[$f['language']])) $errors[] = t('Unknown language.');
    $maxItems = parse_number($f['max_items']);
    if ($maxItems === null || $maxItems != (int)$maxItems || $maxItems < 1 || $maxItems > 1000) {
        $errors[] = t('Maximum number of items must be a whole number between 1 and 1000.');
    }
    $semThr = parse_number($f['sem_threshold']);
    if ($semThr === null || $semThr < 0 || $semThr > 5) {
        $errors[] = t('SEM threshold must be a number between 0 and 5 (0 = stop only by number of items).');
    }
    if (!isset(CAT_METHODS[$f['selection_method']])) $errors[] = t('Unknown item selection method.');
    if (!ctype_digit($f['randomesque_k']) || (int)$f['randomesque_k'] < 1 || (int)$f['randomesque_k'] > 10) {
        $errors[] = t('Randomesque k must be a whole number between 1 and 10.');
    }
    if ($f['callback_url'] !== '') {
        $probe = str_replace('{ref}', 'x', $f['callback_url']);
        $scheme = strtolower((string)parse_url($probe, PHP_URL_SCHEME));
        if (!filter_var($probe, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            $errors[] = t('The return URL must be a full address starting with https:// (the placeholder {ref} is allowed).');
        } elseif (strlen($f['callback_url']) > 1000) {
            $errors[] = t('The return URL is too long.');
        }
    }
    if (is_admin() && !q_val('SELECT id FROM {users} WHERE id = ?', [$f['owner_id']])) $errors[] = t('Choose an owner.');

    if (!$errors) {
        $vals = [$f['name'], $f['slug'], $f['bank_id'], $f['intro_text'] ?: null, $f['language'], (int)$maxItems,
                 $semThr, $f['callback_url'] ?: null, $f['require_ref'], $f['is_active'],
                 $f['selection_method'], (int)$f['randomesque_k'], $f['owner_id']];
        if ($test) {
            q('UPDATE {tests} SET name=?, slug=?, bank_id=?, intro_text=?, language=?, max_items=?, sem_threshold=?,
                  callback_url=?, require_ref=?, is_active=?, selection_method=?, randomesque_k=?, owner_id=? WHERE id = ?',
                array_merge($vals, [$id]));
            flash('success', t('Test saved.'));
        } else {
            q('INSERT INTO {tests} (name, slug, bank_id, intro_text, language, max_items, sem_threshold,
                  callback_url, require_ref, is_active, selection_method, randomesque_k, owner_id, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge($vals, [now_utc()]));
            $id = (int)db()->lastInsertId();
            flash('success', $f['is_active'] ? t('Test created.') : t('Test created. It stays inactive until you tick “Active”.'));
        }
        redirect(url('admin/test_edit.php?id=' . $id));
    }
}

$link = $test ? test_abs_url($test['slug']) : null;
admin_start($test ? $test['name'] : t('New test'), 'tests',
    $test ? '<a class="btn" href="' . h(test_path($test['slug'])) . '" target="_blank" rel="noopener">' . te('Open participant page') . '</a>' : '');
foreach ($errors as $e) {
    echo '<div class="alert alert-error">' . h($e) . '</div>';
}
?>
<form method="post" class="form panel">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <label><?= te('Name') ?> <small><?= te('Shown to participants.') ?></small>
    <input name="name" value="<?= h($f['name']) ?>" required maxlength="255">
  </label>
  <label><?= te('Link name') ?>
    <div class="prefix-input"><span><?= h(rtrim(test_abs_url('x'), 'x')) ?></span><input name="slug" value="<?= h($f['slug']) ?>" required pattern="[a-z0-9-]+" maxlength="64"></div>
    <small><?= te('Lowercase letters, digits and hyphens. Changing it breaks links that were already sent out.') ?></small>
  </label>
  <div class="row2">
    <label><?= te('Item bank') ?>
      <select name="bank_id"<?= $test && $nSessions ? ' disabled' : '' ?>>
        <?php foreach ($banks as $b): ?>
          <option value="<?= (int)$b['id'] ?>"<?= (int)$b['id'] === (int)$f['bank_id'] ? ' selected' : '' ?>><?= h($b['name']) ?> (<?= te('%d items', (int)$b['n']) ?>)<?= $b['is_example'] ? ' – ' . te('example') : '' ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($test && $nSessions): ?><input type="hidden" name="bank_id" value="<?= (int)$f['bank_id'] ?>"><small><?= te('Fixed because sessions exist.') ?></small><?php endif; ?>
    </label>
    <label><?= te('Language of the participant pages') ?>
      <select name="language">
        <?php foreach (CAT_LANGS as $code => $label): ?>
          <option value="<?= h($code) ?>"<?= $code === $f['language'] ? ' selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <small><?= te('Buttons and messages. The questions are shown as written in the item bank.') ?></small>
    </label>
  </div>
  <?php if (is_admin()): ?>
    <label><?= te('Owner') ?> <small><?= te('The project leader who manages this test and may download its results.') ?></small>
      <select name="owner_id">
        <?php foreach (users_for_select() as $u): ?>
          <option value="<?= (int)$u['id'] ?>"<?= (int)$u['id'] === (int)($f['owner_id'] ?? 0) ? ' selected' : '' ?>><?= h($u['name']) ?> (<?= h(role_label($u['role'])) ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php endif; ?>
  <label><?= te('Introduction') ?> <small><?= te('Shown before the first question. Plain text; line breaks are kept.') ?></small>
    <textarea name="intro_text" rows="5"><?= h($f['intro_text']) ?></textarea>
  </label>

  <fieldset>
    <legend><?= te('Stopping rule (whichever comes first)') ?></legend>
    <div class="row2">
      <label><?= te('Maximum number of items') ?>
        <input name="max_items" value="<?= h($f['max_items']) ?>" inputmode="numeric" required>
      </label>
      <label><?= te('Stop when SEM ≤') ?>
        <input name="sem_threshold" value="<?= h($f['sem_threshold']) ?>" inputmode="decimal" required>
        <small><?= te('SEM 0.30 ≈ reliability .91, 0.40 ≈ .84. Enter 0 for no SEM rule.') ?></small>
      </label>
    </div>
  </fieldset>

  <fieldset>
    <legend><?= te('Item selection') ?></legend>
    <div class="row2">
      <label><?= te('Criterion') ?>
        <select name="selection_method">
          <?php foreach (array_keys(CAT_METHODS) as $key): ?>
            <option value="<?= h($key) ?>"<?= $key === ($f['selection_method'] ?? 'mfi') ? ' selected' : '' ?>><?= h(method_label($key)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><?= te('Randomesque k') ?>
        <input name="randomesque_k" value="<?= h($f['randomesque_k'] ?? 1) ?>" inputmode="numeric" required>
      </label>
    </div>
    <details class="help">
      <summary><?= te('Which settings should I use?') ?></summary>
      <p><?= tm('<strong>Maximum Fisher information</strong> takes the item that is most informative at the current θ estimate. It is the classic choice, but early in the test the estimate is still rough.') ?></p>
      <p><?= tm('<strong>Posterior-weighted information</strong> averages the information over the whole posterior, so it accounts for the uncertainty in θ. <strong>Minimum expected posterior variance</strong> takes the item that is expected to lower the SEM the most, which fits the SEM stopping rule. Both tend to help most in short tests.') ?></p>
      <p><?= tm('<strong>Randomesque k</strong> picks at random among the k best items, the first item included. With k = 1 everyone who gives the same answers sees the same items; k = 3 to 5 spreads the items over the bank and costs little precision.') ?></p>
      <p><?= te('Changes apply to sessions started afterwards.') ?></p>
    </details>
  </fieldset>

  <fieldset>
    <legend><?= te('Survey integration (e.g. LimeSurvey)') ?></legend>
    <label><?= te('Return URL after the test') ?> <small><?= te('Optional.') ?></small>
      <input name="callback_url" value="<?= h($f['callback_url']) ?>" placeholder="https://umfrage.example.de/index.php/123456?token={ref}&amp;lang=de">
      <small><?= tm('<code>{ref}</code> is replaced by the participant’s reference token, and <code>cat_complete=1</code> is appended. Scores are never sent.') ?></small>
    </label>
    <label class="check"><input type="checkbox" name="require_ref" value="1"<?= $f['require_ref'] ? ' checked' : '' ?>>
      <?= tm('Only allow starts with a reference token (<code>?ref=…</code>). This prevents sessions that cannot be linked to the survey.') ?></label>
  </fieldset>

  <label class="check"><input type="checkbox" name="is_active" value="1"<?= $f['is_active'] ? ' checked' : '' ?>> <?= te('Active (participants can start the test)') ?></label>

  <div class="actions">
    <button class="btn btn-primary" type="submit"><?= te('Save') ?></button>
    <a class="btn" href="<?= h(url('admin/tests.php')) ?>"><?= te('Back') ?></a>
  </div>
</form>

<?php if ($test): ?>
<div class="panel">
  <h2><?= te('Links') ?></h2>
  <p><?= te('Direct link:') ?></p>
  <div class="copyrow"><input class="copy-src" readonly value="<?= h($link) ?>"><button type="button" class="btn btn-sm" data-copy data-copied="<?= te('Copied') ?>"><?= te('Copy') ?></button></div>
  <p class="mt"><?= tm('Link for LimeSurvey (end URL or a link in a question). LimeSurvey replaces <code>{TOKEN}</code> with the participant’s token:') ?></p>
  <div class="copyrow"><input class="copy-src" readonly value="<?= h($link . '?ref={TOKEN}') ?>"><button type="button" class="btn btn-sm" data-copy data-copied="<?= te('Copied') ?>"><?= te('Copy') ?></button></div>
</div>

<div class="panel danger-zone">
  <h2><?= te('Delete data') ?></h2>
  <p><?= te('Sessions for this test: %d', $nSessions) ?></p>
  <?php if ($nSessions): ?>
  <form method="post" class="inline-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete_sessions">
    <label class="check"><input type="checkbox" name="confirm_sessions" value="1"> <?= te('Delete all sessions and answers, keep the test') ?></label>
    <button class="btn btn-danger" type="submit"><?= te('Delete sessions') ?></button>
  </form>
  <?php endif; ?>
  <form method="post" class="inline-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete">
    <label class="check"><input type="checkbox" name="confirm" value="1"> <?= $nSessions ? te('Delete this test and all its sessions') : te('Delete this test') ?></label>
    <button class="btn btn-danger" type="submit"><?= te('Delete test') ?></button>
  </form>
</div>
<?php endif; ?>
<?php
admin_end();

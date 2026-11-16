<?php
declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/csv_import.php';
require_login();

/** Translated description of an example bank. */
function example_description(string $key): string
{
    return match ($key) {
        'example_ts' => t('20 multiple-choice items with one correct answer each, 3PL, English. Illustrative, uncalibrated parameters.'),
        'example_knowledge' => t('20 reasoning and arithmetic items: multiple choice (3PL) and true/false (2PL, 1PL), German.'),
        default => t('15 Likert items with five categories, GRM, three of them reverse-scored, German.'),
    };
}

$importErrors = [];
$name = '';

/** Validate and store CSV text as a new item bank; returns [bankId|null, errors]. */
function import_bank(string $name, string $filename, string $raw, bool $isExample = false): array
{
    [$header, $rows] = csv_rows(csv_to_utf8($raw));
    [$items, $errors] = csv_parse_items($header, $rows);
    if ($errors) {
        return [null, $errors];
    }
    return [csv_store_bank($name, $filename, $items, uid(), $isExample), []];
}

$examples = cat_example_banks();
$sharedExamples = [];
foreach (q_all('SELECT id, source_filename FROM {item_banks} WHERE is_example = 1') as $r) {
    $sharedExamples[$r['source_filename']] = (int)$r['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'example') {
        $file = (string)($_POST['file'] ?? '');
        if (!isset($examples[$file]) || !is_file(__DIR__ . '/../examples/' . $file)) {
            flash('error', t('This example file is not available.'));
            redirect(url('admin/banks.php'));
        }
        if (isset($sharedExamples[$file])) {
            redirect(url('admin/bank.php?id=' . $sharedExamples[$file]));
        }
        try {
            [$bankId, $errs] = import_bank($examples[$file][0], $file, (string)file_get_contents(__DIR__ . '/../examples/' . $file), true);
        } catch (Throwable $e) {
            $errs = [$e->getMessage()];
        }
        if ($errs) {
            flash('error', t('The example could not be added: %s', implode(' ', $errs)));
            redirect(url('admin/banks.php'));
        }
        flash('success', t('Example item bank “%s” added. It is available to all accounts.', $examples[$file][0]));
        redirect(url('admin/bank.php?id=' . $bankId));
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $file = $_FILES['csv'] ?? null;
    if ($name === '') {
        $importErrors[] = t('Give the item bank a name.');
    }
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $importErrors[] = t('Choose a CSV file.');
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $importErrors[] = t('The upload failed (error code %1$d). The file may be larger than this server allows (%2$s).', (int)$file['error'], ini_get('upload_max_filesize'));
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $importErrors[] = t('The file is larger than 5 MB.');
    }
    if (!$importErrors) {
        try {
            [$bankId, $importErrors] = import_bank($name, basename((string)$file['name']), (string)file_get_contents($file['tmp_name']));
            if ($bankId) {
                flash('success', t('Item bank “%s” imported.', $name));
                redirect(url('admin/bank.php?id=' . $bankId));
            }
        } catch (Throwable $e) {
            $importErrors[] = t('Import failed: %s', $e->getMessage());
        }
    }
}

[$scope, $params] = scope_banks('b');
$banks = q_all(
    "SELECT b.*, u.name AS owner_name,
        (SELECT COUNT(*) FROM {items} i WHERE i.bank_id = b.id) AS n_items,
        (SELECT COUNT(*) FROM {tests} t WHERE t.bank_id = b.id) AS n_tests
     FROM {item_banks} b LEFT JOIN {users} u ON u.id = b.owner_id
     WHERE 1 = 1 $scope ORDER BY b.is_example, b.created_at DESC",
    $params
);
$models = [];
foreach (q_all('SELECT bank_id, model, COUNT(*) AS n FROM {items} GROUP BY bank_id, model') as $r) {
    $models[$r['bank_id']][] = $r['n'] . ' ' . $r['model'];
}

admin_start(t('Item banks'), 'banks');
?>
<?php if ($banks): ?>
<div class="table-wrap">
<table>
  <thead><tr><th><?= te('Name') ?></th><th><?= te('Owner') ?></th><th class="num"><?= te('Items') ?></th><th class="num"><?= te('Used by tests') ?></th><th><?= te('Added') ?></th></tr></thead>
  <tbody>
  <?php foreach ($banks as $b): ?>
    <tr>
      <td><a class="rowlink" href="<?= h(url('admin/bank.php?id=' . $b['id'])) ?>"><?= h($b['name']) ?></a>
        <div class="muted small"><?= h($b['source_filename']) ?></div></td>
      <td><?= $b['is_example'] ? '<span class="tag">' . te('Shared') . '</span>' : h($b['owner_name'] ?? '–') ?></td>
      <td class="num"><?= (int)$b['n_items'] ?><div class="muted small"><?= h(implode(', ', $models[$b['id']] ?? [])) ?></div></td>
      <td class="num"><?= (int)$b['n_tests'] ?></td>
      <td class="nowrap small"><?= h(fmt_dt($b['created_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php else: ?>
  <div class="empty">
    <p><?= te('There are no item banks yet. Upload your own CSV file below, or start with one of the examples.') ?></p>
  </div>
<?php endif; ?>

<section class="section">
  <h2><?= te('Upload an item bank') ?></h2>
  <?php if ($importErrors): ?>
    <div class="alert alert-error">
      <strong><?= te('Nothing was imported.') ?></strong> <?= te('Fix these rows in the file and upload it again.') ?>
      <ul><?php foreach ($importErrors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="form panel">
    <?= csrf_field() ?>
    <div class="row2">
      <label><?= te('Name') ?>
        <input name="name" value="<?= h($name) ?>" required maxlength="255" placeholder="<?= te('e.g. Self-control, 15 items') ?>">
      </label>
      <label><?= te('CSV file') ?>
        <input type="file" name="csv" accept=".csv,.txt,text/csv" required>
      </label>
    </div>
    <div class="actions"><button class="btn btn-primary" type="submit"><?= te('Upload and check') ?></button></div>
    <details class="help">
      <summary><?= te('CSV format') ?></summary>
      <p><?= tm('One row per item. In Excel use <em>Save as → CSV UTF-8</em>; semicolons and decimal commas are fine.') ?></p>
      <table class="compact">
        <tr><th><?= te('Column') ?></th><th><?= te('Content') ?></th></tr>
        <tr><td><code>item_id</code></td><td><?= tm('Unique item name, e.g. <code>K01</code>') ?></td></tr>
        <tr><td><code>question_text</code></td><td><?= te('Item text (plain text)') ?></td></tr>
        <tr><td><code>response_type</code></td><td><?= tm('<code>dichotomous</code>, <code>multiple_choice</code> or <code>likert</code>') ?></td></tr>
        <tr><td><code>model</code></td><td><?= tm('<code>1PL</code>, <code>2PL</code>, <code>3PL</code> (dichotomous, multiple choice) or <code>GRM</code> (likert)') ?></td></tr>
        <tr><td><code>a</code></td><td><?= te('Discrimination, greater than 0. Optional for 1PL (default 1)') ?></td></tr>
        <tr><td><code>b</code></td><td><?= te('Difficulty (1PL, 2PL, 3PL)') ?></td></tr>
        <tr><td><code>c</code></td><td><?= te('Guessing parameter (3PL only)') ?></td></tr>
        <tr><td><code>b1</code>, <code>b2</code>, …</td><td><?= te('GRM thresholds, one per column, increasing; k thresholds give k + 1 categories') ?></td></tr>
        <tr><td><code>option_1</code>, <code>option_2</code>, …</td><td><?= te('Answer texts. Required for multiple choice; optional for dichotomous (default yes/no) and likert (one label per category)') ?></td></tr>
        <tr><td><code>correct_option</code></td><td><?= te('Number of the option scored 1 (multiple choice: required; dichotomous: default 1)') ?></td></tr>
        <tr><td><code>likert_min_label</code>, <code>likert_max_label</code></td><td><?= te('Labels for the two scale ends (likert, optional)') ?></td></tr>
        <tr><td><code>reverse</code></td><td><?= tm('<code>1</code> reverse-scores a likert item (optional)') ?></td></tr>
      </table>
      <p><?= tm('Parameters use the logistic metric without D = 1.7, as in <code>mirt::coef(model, IRTpars = TRUE)</code>.') ?></p>
    </details>
  </form>
</section>

<section class="section">
  <h2><?= te('Example item banks') ?></h2>
  <p class="muted"><?= tm('Ready-made banks for trying out the software. Once added, an example is available to all accounts. The CSV files are in the <code>examples</code> folder of the download.') ?></p>
  <ul class="examples">
  <?php foreach ($examples as $file => [$exName, $exKey]): ?>
    <li>
      <div>
        <strong><?= h($exName) ?></strong>
        <p class="muted small"><?= h(example_description($exKey)) ?></p>
      </div>
      <?php if (isset($sharedExamples[$file])): ?>
        <a class="btn btn-sm" href="<?= h(url('admin/bank.php?id=' . $sharedExamples[$file])) ?>"><?= te('Open') ?></a>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="example"><input type="hidden" name="file" value="<?= h($file) ?>">
          <button class="btn btn-sm" type="submit"><?= te('Add') ?></button>
        </form>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
</section>
<?php
admin_end();

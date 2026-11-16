<?php
/**
 * Rescore: upload responses in long format, get θ (EAP) and SE per person,
 * computed with the same engine as the live test. Nothing is stored.
 *
 * Input: one row per answer with a person column, an item column and a response
 * column. The CAT long export can be uploaded as it is.
 */

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/csv_import.php';
require_login();

const SCORE_PERSON_COLS = ['person_id', 'session_id', 'person', 'participant', 'pid', 'respondent', 'reference_token'];
const SCORE_SCORED_COLS = ['scored_response', 'score', 'response', 'resp', 'value'];
const SCORE_RAW_COLS = ['raw_response', 'response', 'resp', 'answer', 'value'];
const SCORE_APP_THETA_COLS = ['final_theta', 'theta'];
const SCORE_MAX_BYTES = 50 * 1024 * 1024;
const SCORE_SHOW_ROWS = 500;

[$bScope, $bParams] = scope_banks('b');
$banks = q_all("SELECT b.id, b.name, (SELECT COUNT(*) FROM {items} i WHERE i.bank_id = b.id) AS n
                FROM {item_banks} b WHERE 1 = 1 $bScope ORDER BY b.created_at DESC", $bParams);
$bankId = (int)($_POST['bank_id'] ?? ($_GET['bank_id'] ?? ($banks[0]['id'] ?? 0)));
$coding = ($_POST['coding'] ?? 'scored') === 'raw' ? 'raw' : 'scored';
$dialect = ($_POST['dialect'] ?? 'standard') === 'excel' ? 'excel' : 'standard';
$errors = [];
$result = null;

/**
 * Read the upload and compute θ/SE. Returns ['rows' => [...], 'info' => [...]]
 * or ['errors' => [...]] (nothing is computed if any row has a problem).
 */
function score_upload(string $text, int $bankId, string $coding): array
{
    $items = [];
    foreach (q_all('SELECT * FROM {items} WHERE bank_id = ?', [$bankId]) as $row) {
        $items[$row['item_ref']] = $row;
    }
    if (!$items) {
        return ['errors' => [t('The selected item bank has no items.')]];
    }

    [$fh, $delim] = csv_open($text);
    $header = fgetcsv($fh, null, $delim, '"', '');
    if (!$header) {
        return ['errors' => [t('The file is empty.')]];
    }
    $header = array_map('csv_norm_header', $header);
    $find = function (array $candidates) use ($header): ?int {
        foreach ($candidates as $c) {
            $i = array_search($c, $header, true);
            if ($i !== false) {
                return $i;
            }
        }
        return null;
    };
    $pCol = $find(SCORE_PERSON_COLS);
    $iCol = $find(['item_id']);
    $rCol = $find($coding === 'raw' ? SCORE_RAW_COLS : SCORE_SCORED_COLS);
    $tCol = $find(SCORE_APP_THETA_COLS);
    $missing = [];
    if ($pCol === null) $missing[] = t('person (one of: %s)', implode(', ', SCORE_PERSON_COLS));
    if ($iCol === null) $missing[] = t('item (item_id)');
    if ($rCol === null) $missing[] = t('response (one of: %s)', implode(', ', $coding === 'raw' ? SCORE_RAW_COLS : SCORE_SCORED_COLS));
    if ($missing) {
        return ['errors' => [t('Missing required columns: %1$s. Found: %2$s', implode('; ', $missing), implode(', ', array_filter($header)))]];
    }

    $errors = [];
    $persons = [];      // person => ['resp' => [itemRef => score], 'app' => ?float]
    $line = 1;
    $nUsed = 0;
    $nEmpty = 0;
    $unknownItems = [];
    while (($cells = fgetcsv($fh, null, $delim, '"', '')) !== false) {
        $line++;
        if (count($errors) >= 50) {
            break;
        }
        if (count(array_filter($cells, fn($c) => trim((string)$c) !== '')) === 0) {
            continue;
        }
        $person = trim((string)($cells[$pCol] ?? ''));
        $ref = trim((string)($cells[$iCol] ?? ''));
        $val = trim((string)($cells[$rCol] ?? ''));
        if ($person === '') { $errors[] = t('Row %1$d: %2$s', $line, t('person is empty')); continue; }
        if (!isset($items[$ref])) { $unknownItems[$ref] = $unknownItems[$ref] ?? $line; continue; }
        if (!isset($persons[$person])) {
            $persons[$person] = ['resp' => [], 'app' => null];
        }
        if ($tCol !== null && $persons[$person]['app'] === null) {
            $persons[$person]['app'] = parse_number($cells[$tCol] ?? '');
        }
        if ($val === '' || in_array(strtoupper($val), ['NA', '.', '-99', '-9', 'NAN'], true)) {
            $nEmpty++;
            continue;
        }
        $x = parse_number($val);
        if ($x === null || $x != (int)$x) { $errors[] = t('Row %1$d: %2$s', $line, t('response “%s” is not a whole number', $val)); continue; }
        $x = (int)$x;
        $row = $items[$ref];
        if ($coding === 'raw') {
            $score = score_response($row, $x);
            if ($score === null) { $errors[] = t('Row %1$d: %2$s', $line, t('answer %1$d is out of range for item %2$s', $x, $ref)); continue; }
        } else {
            $maxScore = $row['model'] === 'GRM' ? (int)$row['likert_levels'] - 1 : 1;
            if ($x < 0 || $x > $maxScore) { $errors[] = t('Row %1$d: %2$s', $line, t('scored response %1$d is out of range for item %2$s (0 to %3$d)', $x, $ref, $maxScore)); continue; }
            $score = $x;
        }
        if (isset($persons[$person]['resp'][$ref])) { $errors[] = t('Row %1$d: %2$s', $line, t('person %1$s answered item %2$s twice', $person, $ref)); continue; }
        $persons[$person]['resp'][$ref] = $score;
        $nUsed++;
    }
    fclose($fh);

    foreach (array_slice($unknownItems, 0, 10, true) as $ref => $l) {
        $errors[] = t('Row %1$d: %2$s', $l, t('item “%s” is not in the selected item bank', (string)$ref));
    }
    if (count($unknownItems) > 10) {
        $errors[] = t('… and %d more unknown items', count($unknownItems) - 10);
    }
    if ($errors) {
        return ['errors' => $errors];
    }
    if (!$persons) {
        return ['errors' => [t('The file contains no responses.')]];
    }

    // Log-likelihood vectors per item × score, computed once.
    $cache = [];
    $rows = [];
    foreach ($persons as $person => $p) {
        if (!$p['resp']) {
            $rows[] = [$person, 0, null, null, $p['app']];
            continue;
        }
        $sum = array_fill(0, CAT_GRID_N, 0.0);
        foreach ($p['resp'] as $ref => $score) {
            $key = $ref . "\0" . $score;
            if (!isset($cache[$key])) {
                $cache[$key] = cat_loglik_vector(engine_item($items[$ref]), $score);
            }
            foreach ($cache[$key] as $i => $v) {
                $sum[$i] += $v;
            }
        }
        [$theta, $se] = cat_eap_from_loglik($sum);
        $rows[] = [$person, count($p['resp']), $theta, $se, $p['app']];
    }

    $hasApp = $tCol !== null;
    $diffs = [];
    if ($hasApp) {
        foreach ($rows as $r) {
            if ($r[2] !== null && $r[4] !== null) {
                $diffs[] = abs($r[2] - $r[4]);
            }
        }
    }
    return [
        'rows' => $rows,
        'info' => [
            'persons' => count($rows), 'responses' => $nUsed, 'empty' => $nEmpty, 'has_app' => $hasApp,
            'max_diff' => $diffs ? max($diffs) : null, 'n_compared' => count($diffs),
        ],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    @set_time_limit(300);
    $file = $_FILES['csv'] ?? null;
    if (!$bankId || !in_array($bankId, array_map(fn($b) => (int)$b['id'], $banks), true)) {
        $errors[] = t('Choose an item bank.');
    } elseif (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors[] = t('Choose a CSV file.');
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = t('The upload failed (error code %1$d). The file may be larger than this server allows (%2$s).', (int)$file['error'], ini_get('upload_max_filesize'));
    } elseif ($file['size'] > SCORE_MAX_BYTES) {
        $errors[] = t('The file is larger than 50 MB.');
    } else {
        try {
            $result = score_upload(csv_to_utf8((string)file_get_contents($file['tmp_name'])), $bankId, $coding);
            if (isset($result['errors'])) {
                $errors = $result['errors'];
                $result = null;
            }
        } catch (Throwable $e) {
            $errors[] = t('Scoring failed: %s', $e->getMessage());
        }
    }

    if ($result && ($_POST['output'] ?? '') === 'download') {
        $delim = $dialect === 'excel' ? ';' : ',';
        $num = function ($x) use ($dialect) {
            if ($x === null) {
                return '';
            }
            $s = number_format((float)$x, 6, '.', '');
            return $dialect === 'excel' ? str_replace('.', ',', $s) : $s;
        };
        $base = pathinfo((string)$file['name'], PATHINFO_FILENAME);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $base) . '_scored.csv"');
        $out = fopen('php://output', 'w');
        if ($dialect === 'excel') {
            fwrite($out, "\xEF\xBB\xBF");
        }
        $head = ['person_id', 'n_items', 'theta', 'se'];
        if ($result['info']['has_app']) {
            $head = array_merge($head, ['theta_app', 'diff']);
        }
        fputcsv($out, $head, $delim, '"', '');
        foreach ($result['rows'] as [$person, $n, $theta, $se, $app]) {
            $line = [$person, $n, $num($theta), $num($se)];
            if ($result['info']['has_app']) {
                $line[] = $num($app);
                $line[] = $theta !== null && $app !== null ? $num($theta - $app) : '';
            }
            fputcsv($out, $line, $delim, '"', '');
        }
        fclose($out);
        exit;
    }
}

admin_start(t('Rescore'), 'score');
foreach ($errors as $e) {
    echo '<div class="alert alert-error">' . h($e) . '</div>';
}
if (!$banks) {
    echo '<p class="muted">' . te('Add an item bank first.') . '</p>';
    admin_end();
    exit;
}
?>
<p class="lead"><?= tm('Upload answers in long format and get θ (EAP) and its standard error per person, computed exactly as in the live test (N(0, 1) prior, 901 quadrature points from −4.5 to 4.5). Useful for checking results against R. <strong>Nothing is stored.</strong>') ?></p>

<form method="post" enctype="multipart/form-data" class="form panel">
  <?= csrf_field() ?>
  <div class="row2">
    <label><?= te('Item bank (item parameters)') ?>
      <select name="bank_id">
        <?php foreach ($banks as $b): ?>
          <option value="<?= (int)$b['id'] ?>"<?= (int)$b['id'] === $bankId ? ' selected' : '' ?>><?= h($b['name']) ?> (<?= te('%d items', (int)$b['n']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('CSV file (long format)') ?>
      <input type="file" name="csv" accept=".csv,.txt,text/csv" required>
      <small><?= te('At most %s on this server.', (string)ini_get('upload_max_filesize')) ?></small>
    </label>
  </div>
  <div class="row2">
    <fieldset>
      <legend><?= te('Responses are coded as') ?></legend>
      <label class="check"><input type="radio" name="coding" value="scored"<?= $coding === 'scored' ? ' checked' : '' ?>>
        <?= tm('Scored values: 0/1 for dichotomous items, categories 0 to K for GRM (column <code>scored_response</code> or <code>response</code>)') ?></label>
      <label class="check"><input type="radio" name="coding" value="raw"<?= $coding === 'raw' ? ' checked' : '' ?>>
        <?= tm('Option numbers as shown (1 to n), scored with <code>correct_option</code> and <code>reverse</code> (column <code>raw_response</code> or <code>response</code>)') ?></label>
    </fieldset>
    <fieldset>
      <legend><?= te('File style') ?></legend>
      <label class="check"><input type="radio" name="dialect" value="standard"<?= $dialect === 'standard' ? ' checked' : '' ?>> <?= te('R / SPSS / Python (comma, decimal point)') ?></label>
      <label class="check"><input type="radio" name="dialect" value="excel"<?= $dialect === 'excel' ? ' checked' : '' ?>> <?= te('Excel with semicolons and decimal commas (e.g. German, French, Spanish)') ?></label>
    </fieldset>
  </div>
  <div class="actions">
    <button class="btn btn-primary" type="submit" name="output" value="show"><?= te('Calculate') ?></button>
    <button class="btn" type="submit" name="output" value="download"><?= te('Calculate and download CSV') ?></button>
  </div>
  <details class="help">
    <summary><?= te('Expected columns') ?></summary>
    <table class="compact">
      <tr><th><?= te('Column') ?></th><th><?= te('Accepted names') ?></th></tr>
      <tr><td><?= te('Person') ?></td><td><?= h(implode(', ', SCORE_PERSON_COLS)) ?></td></tr>
      <tr><td><?= te('Item') ?></td><td><code>item_id</code></td></tr>
      <tr><td><?= te('Response') ?></td><td><?= h(implode(', ', array_unique(array_merge(SCORE_SCORED_COLS, SCORE_RAW_COLS)))) ?></td></tr>
      <tr><td><?= te('Optional') ?></td><td><code>final_theta</code>, <code>theta</code></td></tr>
    </table>
    <p><?= tm('Empty cells and <code>NA</code> count as not answered. The long export of this application can be uploaded unchanged; if it contains <code>final_theta</code>, the difference to the recalculated θ is shown.') ?></p>
    <p><?= tm('To check against R and mirt, use <code>examples/score_with_mirt.R</code> from the download.') ?></p>
  </details>
</form>

<?php if ($result): $info = $result['info']; ?>
<section class="section">
  <h2><?= te('Result') ?></h2>
  <p><?= te('%1$d persons, %2$d responses used, %3$d empty or NA skipped.', (int)$info['persons'], (int)$info['responses'], (int)$info['empty']) ?></p>
  <?php if ($info['has_app'] && $info['max_diff'] !== null): ?>
    <div class="alert <?= $info['max_diff'] < 1e-5 ? 'alert-success' : 'alert-warning' ?>">
      <?= te('Compared with the θ in the file for %1$d persons: largest absolute difference %2$s. The export rounds θ to 6 decimals, so differences up to 5e-7 are rounding.', (int)$info['n_compared'], sprintf('%.2e', $info['max_diff'])) ?>
    </div>
  <?php endif; ?>
  <div class="table-wrap">
  <table>
    <thead><tr><th><?= te('Person') ?></th><th class="num"><?= te('Items') ?></th><th class="num">θ</th><th class="num">SE</th><?php if ($info['has_app']): ?><th class="num"><?= te('θ in file') ?></th><th class="num"><?= te('Difference') ?></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach (array_slice($result['rows'], 0, SCORE_SHOW_ROWS) as [$person, $n, $theta, $se, $app]): ?>
      <tr>
        <td><code><?= h($person) ?></code></td><td class="num"><?= (int)$n ?></td>
        <td class="num"><?= h(fmt_num($theta, 4)) ?></td><td class="num"><?= h(fmt_num($se, 4)) ?></td>
        <?php if ($info['has_app']): ?>
          <td class="num"><?= h(fmt_num($app, 4)) ?></td>
          <td class="num"><?= $theta !== null && $app !== null ? h(sprintf('%.1e', $theta - $app)) : '–' ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if (count($result['rows']) > SCORE_SHOW_ROWS): ?>
    <p class="muted"><?= te('Showing the first %d persons. Use “Calculate and download CSV” for all.', SCORE_SHOW_ROWS) ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php
admin_end();

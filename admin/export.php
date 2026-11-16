<?php
/**
 * CSV export.
 *   long  – one row per answer
 *   wide  – one row per session, one column per item (scored response)
 *   items – item parameters of the test's item bank (codebook)
 * Scores leave the system only through this authenticated download.
 */

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_login();

[$scope, $scopeParams] = scope_tests('t');
$tests = q_all("SELECT t.id, t.name, t.slug, t.bank_id FROM {tests} t WHERE 1 = 1 $scope ORDER BY t.name", $scopeParams);
$testsById = array_column($tests, null, 'id');

$testId = (int)($_GET['test_id'] ?? 0);
$format = in_array($_GET['format'] ?? '', ['long', 'wide', 'items'], true) ? $_GET['format'] : 'wide';
$dialect = ($_GET['dialect'] ?? '') === 'excel' ? 'excel' : 'standard';
$which = ($_GET['sessions'] ?? '') === 'completed' ? 'completed' : 'all';
$error = '';

if (isset($_GET['download'])) {
    if ($testId && !isset($testsById[$testId])) {
        $error = t('Unknown test.');
    } elseif (!$testId && $format !== 'long') {
        $error = t('The wide format and the item parameters need a single test, because each test can have different items.');
    } else {
        export_csv($format, $dialect, $which, $testId ? $testsById[$testId] : null);
        exit;
    }
}

// ─── Export ───────────────────────────────────────────────────────────

function export_csv(string $format, string $dialect, string $which, ?array $test): void
{
    $delim = $dialect === 'excel' ? ';' : ',';
    $dec = $dialect === 'excel' ? ',' : '.';
    $num = function ($x, int $d = 6) use ($dec) {
        if ($x === null || $x === '') {
            return '';
        }
        $s = rtrim(rtrim(number_format((float)$x, $d, '.', ''), '0'), '.');
        return str_replace('.', $dec, $s === '-0' ? '0' : $s);
    };
    $txt = function ($s) use ($dialect) {
        $s = (string)$s;
        // Excel formula injection guard
        if ($dialect === 'excel' && $s !== '' && strpbrk($s[0], '=+-@') !== false && !is_numeric($s)) {
            return "'" . $s;
        }
        return $s;
    };
    $iso = fn($dt) => $dt ? str_replace(' ', 'T', $dt) . 'Z' : '';

    $name = 'cat_' . ($test['slug'] ?? 'all') . '_' . $format . '_' . gmdate('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');

    if (cfg('db_driver', 'mysql') === 'mysql') {
        db()->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false); // stream large exports
    }

    $out = fopen('php://output', 'w');
    if ($dialect === 'excel') {
        fwrite($out, "\xEF\xBB\xBF");
    }
    $put = fn(array $row) => fputcsv($out, $row, $delim, '"', '');

    $statusSql = $which === 'completed' ? " AND s.status = 'completed'" : '';
    if ($test) {
        $testSql = ' AND s.test_id = ' . (int)$test['id'];
    } else {
        $ids = array_map('intval', array_column($GLOBALS['tests'], 'id'));
        $testSql = ' AND s.test_id IN (' . ($ids ? implode(',', $ids) : '0') . ')';
    }

    if ($format === 'items') {
        $put(['position', 'item_id', 'question_text', 'response_type', 'model', 'a', 'b', 'c',
              'thresholds', 'n_categories', 'correct_option', 'reverse', 'options']);
        $st = q('SELECT * FROM {items} WHERE bank_id = ? ORDER BY position', [$test['bank_id']]);
        while ($it = $st->fetch()) {
            $b = json_decode($it['b'], true);
            $opts = $it['options'] ? json_decode($it['options'], true) : [];
            $put([
                $it['position'], $txt($it['item_ref']), $txt($it['question_text']), $it['response_type'], $it['model'],
                $num($it['a']), is_array($b) ? '' : $num($b), $it['model'] === '3PL' ? $num($it['c']) : '',
                is_array($b) ? implode(' | ', array_map($num, $b)) : '',
                $it['response_type'] === 'likert' ? $it['likert_levels'] : ($it['response_type'] === 'dichotomous' ? 2 : count($opts)),
                $it['response_type'] === 'likert' ? '' : $it['correct_option'],
                $it['reverse_scored'], $txt(implode(' | ', $opts)),
            ]);
        }
        fclose($out);
        return;
    }

    if ($format === 'long') {
        $put(['session_id', 'reference_token', 'test', 'session_status', 'position', 'item_id', 'response_type', 'model',
              'raw_response', 'scored_response', 'theta_after', 'sem_after', 'answered_at_utc',
              'final_theta', 'final_sem', 'n_items', 'stopping_reason', 'selection_method', 'randomesque_k']);
        $st = q("SELECT s.*, t.slug, r.position, r.raw_response, r.scored_response, r.theta_after, r.sem_after,
                    r.answered_at, i.item_ref, i.response_type, i.model
                 FROM {responses} r
                 JOIN {sessions} s ON s.id = r.session_id
                 JOIN {tests} t ON t.id = s.test_id
                 JOIN {items} i ON i.id = r.item_id
                 WHERE 1 = 1 $testSql $statusSql
                 ORDER BY s.id, r.position");
        while ($r = $st->fetch()) {
            $put([
                $r['session_id'] ?? $r['id'], $txt($r['reference_token']), $r['slug'], effective_status($r),
                $r['position'], $txt($r['item_ref']), $r['response_type'], $r['model'],
                $r['raw_response'], $r['scored_response'], $num($r['theta_after']), $num($r['sem_after']),
                $iso($r['answered_at']), $num($r['theta']), $num($r['sem']), $r['items_administered'],
                $r['stopping_reason'], $r['selection_method'] ?? 'mfi', $r['randomesque_k'] ?? 1,
            ]);
        }
        fclose($out);
        return;
    }

    // wide
    $items = q_all('SELECT id, item_ref FROM {items} WHERE bank_id = ? ORDER BY position', [$test['bank_id']]);
    $fixed = ['session_id', 'reference_token', 'test', 'status', 'started_at_utc', 'completed_at_utc',
              'duration_sec', 'n_items', 'theta', 'sem', 'stopping_reason', 'selection_method', 'randomesque_k'];
    $itemCols = [];
    foreach ($items as $it) {
        $col = $it['item_ref'];
        $itemCols[(int)$it['id']] = in_array($col, $fixed, true) ? 'item_' . $col : $col;
    }
    $put(array_merge($fixed, array_map($txt, array_values($itemCols))));

    $st = q("SELECT s.*, r.item_id AS r_item, r.scored_response AS r_score
             FROM {sessions} s
             LEFT JOIN {responses} r ON r.session_id = s.id
             WHERE 1 = 1 $testSql $statusSql
             ORDER BY s.id, r.position");
    $current = null;
    $answers = [];
    $flush = function () use (&$current, &$answers, $put, $itemCols, $num, $txt, $iso, $test) {
        if ($current === null) {
            return;
        }
        $end = $current['completed_at'] ?: $current['last_activity'];
        $row = [
            $current['id'], $txt($current['reference_token']), $test['slug'], effective_status($current),
            $iso($current['started_at']), $iso($current['completed_at']),
            strtotime($end . ' UTC') - strtotime($current['started_at'] . ' UTC'),
            $current['items_administered'], $num($current['theta']), $num($current['sem']), $current['stopping_reason'],
            $current['selection_method'] ?? 'mfi', $current['randomesque_k'] ?? 1,
        ];
        foreach (array_keys($itemCols) as $itemId) {
            $row[] = $answers[$itemId] ?? '';
        }
        $put($row);
    };
    while ($r = $st->fetch()) {
        if ($current === null || (int)$current['id'] !== (int)$r['id']) {
            $flush();
            $current = $r;
            $answers = [];
        }
        if ($r['r_item'] !== null) {
            $answers[(int)$r['r_item']] = $r['r_score'];
        }
    }
    $flush();
    fclose($out);
}

// ─── Page ─────────────────────────────────────────────────────────────

admin_start(t('Export'), 'export');
if ($error) {
    echo '<div class="alert alert-error">' . h($error) . '</div>';
}
?>
<form method="get" class="form panel">
  <input type="hidden" name="download" value="1">
  <label><?= te('Test') ?>
    <select name="test_id">
      <option value="0"><?= is_admin() ? te('All tests (long format only)') : te('All my tests (long format only)') ?></option>
      <?php foreach ($tests as $t): ?>
        <option value="<?= (int)$t['id'] ?>"<?= (int)$t['id'] === $testId ? ' selected' : '' ?>><?= h($t['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <fieldset>
    <legend><?= te('Format') ?></legend>
    <label class="check"><input type="radio" name="format" value="wide"<?= $format === 'wide' ? ' checked' : '' ?>>
      <?= tm('<strong>Wide:</strong> one row per participant with θ, SEM, number of items and one column per item with the scored answer (empty = not given). Ready to merge with the survey data.') ?></label>
    <label class="check"><input type="radio" name="format" value="long"<?= $format === 'long' ? ' checked' : '' ?>>
      <?= tm('<strong>Long:</strong> one row per answer, in the order given, with θ and SEM after each answer.') ?></label>
    <label class="check"><input type="radio" name="format" value="items"<?= $format === 'items' ? ' checked' : '' ?>>
      <?= tm('<strong>Item parameters:</strong> the item bank of the test as a codebook.') ?></label>
  </fieldset>

  <div class="row2">
    <fieldset>
      <legend><?= te('Sessions') ?></legend>
      <label class="check"><input type="radio" name="sessions" value="all"<?= $which === 'all' ? ' checked' : '' ?>> <?= te('All (with a status column)') ?></label>
      <label class="check"><input type="radio" name="sessions" value="completed"<?= $which === 'completed' ? ' checked' : '' ?>> <?= te('Completed only') ?></label>
    </fieldset>
    <fieldset>
      <legend><?= te('File style') ?></legend>
      <label class="check"><input type="radio" name="dialect" value="standard"<?= $dialect === 'standard' ? ' checked' : '' ?>> <?= te('R / SPSS / Python (comma, decimal point)') ?></label>
      <label class="check"><input type="radio" name="dialect" value="excel"<?= $dialect === 'excel' ? ' checked' : '' ?>> <?= te('Excel with semicolons and decimal commas (e.g. German, French, Spanish)') ?></label>
    </fieldset>
  </div>

  <button class="btn btn-primary" type="submit"><?= te('Download CSV') ?></button>
</form>

<details class="help card">
  <summary><?= te('Merging with LimeSurvey in R') ?></summary>
<pre>cat  &lt;- read.csv("cat_my-test_wide_<?= gmdate('Y-m-d') ?>.csv")
lime &lt;- read.csv("limesurvey_results.csv")
dat  &lt;- merge(lime, cat, by.x = "token", by.y = "reference_token", all.x = TRUE)</pre>
  <p class="muted"><?= tm('Times are in UTC. <code>raw_response</code> is the option number as shown; <code>scored_response</code> is the value used by the IRT model.') ?></p>
</details>
<?php
admin_end();

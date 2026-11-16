<?php
/**
 * Item bank CSV import.
 *
 * Accepts what Excel produces: comma, semicolon or tab separated; decimal
 * point or decimal comma; UTF-8 (with or without BOM), UTF-16 or Windows-1252.
 * See README.md for the column reference.
 */

declare(strict_types=1);

defined('CAT') or exit;

const CSV_MAX_ITEMS = 5000;

/** Convert uploaded bytes to UTF-8 text. */
function csv_to_utf8(string $raw): string
{
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
        return substr($raw, 3);
    }
    $from = null;
    if (strncmp($raw, "\xFF\xFE", 2) === 0) {
        $raw = substr($raw, 2);
        $from = 'UTF-16LE';
    } elseif (strncmp($raw, "\xFE\xFF", 2) === 0) {
        $raw = substr($raw, 2);
        $from = 'UTF-16BE';
    } elseif (!csv_is_utf8($raw)) {
        $from = 'Windows-1252';
    }
    if ($from === null) {
        return $raw;
    }
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($raw, 'UTF-8', $from);
    }
    if (function_exists('iconv')) {
        $out = iconv($from === 'Windows-1252' ? 'CP1252' : $from, 'UTF-8//TRANSLIT', $raw);
        if ($out !== false) {
            return $out;
        }
    }
    throw new RuntimeException(t('The file is not UTF-8 encoded and this server cannot convert it. Save the CSV as “CSV UTF-8”.'));
}

function csv_is_utf8(string $s): bool
{
    return function_exists('mb_check_encoding')
        ? mb_check_encoding($s, 'UTF-8')
        : (bool)preg_match('//u', $s);
}

/** Open CSV text as a stream and detect the delimiter (; , or tab) from the header line. */
function csv_open(string $text): array
{
    $firstLine = strtok($text, "\r\n") ?: '';
    $counts = [';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")];
    arsort($counts);
    $delim = array_key_first($counts);

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    return [$fh, $delim];
}

/** Parse CSV text into a list of associative rows keyed by normalized header. */
function csv_rows(string $text): array
{
    [$fh, $delim] = csv_open($text);

    $header = null;
    $rows = [];
    $line = 0;
    while (($cells = fgetcsv($fh, null, $delim, '"', '')) !== false) {
        $line++;
        if ($header === null) {
            $header = array_map('csv_norm_header', $cells);
            continue;
        }
        if (count(array_filter($cells, fn($c) => trim((string)$c) !== '')) === 0) {
            continue; // empty line
        }
        $row = ['__line' => $line];
        foreach ($header as $i => $name) {
            if ($name !== '') {
                $row[$name] = trim((string)($cells[$i] ?? ''));
            }
        }
        $rows[] = $row;
    }
    fclose($fh);

    return [$header ?? [], $rows];
}

function csv_norm_header($h): string
{
    $h = strtolower(trim((string)$h));
    $h = preg_replace('/[\s\-]+/', '_', $h);
    $aliases = [
        'id' => 'item_id', 'item' => 'item_id', 'itemid' => 'item_id',
        'text' => 'question_text', 'question' => 'question_text', 'item_text' => 'question_text',
        'type' => 'response_type', 'responsetype' => 'response_type',
        'reverse_scored' => 'reverse', 'reversed' => 'reverse',
    ];
    if (isset($aliases[$h])) {
        return $aliases[$h];
    }
    if (preg_match('/^option_?(\d+)$/', $h, $m)) {
        return 'option_' . (int)$m[1];
    }
    return $h;
}

function csv_norm_model(string $s): ?string
{
    $s = strtolower(trim($s));
    return [
        '1pl' => '1PL', 'rasch' => '1PL', '2pl' => '2PL', '3pl' => '3PL',
        'grm' => 'GRM', 'graded' => 'GRM', 'graded_response' => 'GRM',
    ][$s] ?? null;
}

function csv_norm_type(string $s): ?string
{
    $s = strtolower(trim(preg_replace('/[\s\-]+/', '_', $s)));
    return [
        'dichotomous' => 'dichotomous', 'dich' => 'dichotomous', 'binary' => 'dichotomous',
        'yes_no' => 'dichotomous', 'yesno' => 'dichotomous', 'true_false' => 'dichotomous',
        'multiple_choice' => 'multiple_choice', 'mc' => 'multiple_choice', 'multiplechoice' => 'multiple_choice',
        'likert' => 'likert', 'rating' => 'likert', 'rating_scale' => 'likert',
    ][$s] ?? null;
}

/** Collect numbered columns (option_1.., b1..) from a row; returns [values, error]. */
function csv_numbered(array $row, string $pattern): array
{
    $found = [];
    foreach ($row as $key => $val) {
        if (preg_match($pattern, (string)$key, $m) && $val !== '') {
            $found[(int)$m[1]] = $val;
        }
    }
    if (!$found) {
        return [[], null];
    }
    ksort($found);
    $expected = range(1, max(array_keys($found)));
    if (array_keys($found) !== $expected) {
        return [[], t('numbered columns must not have gaps (found %s)', implode(', ', array_keys($found)))];
    }
    return [array_values($found), null];
}

function csv_bool(string $s): ?bool
{
    $s = strtolower(trim($s));
    if (in_array($s, ['', '0', 'no', 'nein', 'false', 'falsch'], true)) {
        return false;
    }
    if (in_array($s, ['1', 'yes', 'ja', 'true', 'wahr'], true)) {
        return true;
    }
    return null;
}

/**
 * Validate rows and build item records. Returns [items, errors].
 * Import happens only if errors is empty.
 */
function csv_parse_items(array $header, array $rows): array
{
    $errors = [];
    $items = [];

    $missing = array_diff(['item_id', 'question_text', 'response_type', 'model'], $header);
    if ($missing) {
        return [[], [t('Missing required columns: %1$s. Found: %2$s', implode(', ', $missing), implode(', ', array_filter($header)))]];
    }
    if (!$rows) {
        return [[], [t('The file contains no item rows.')]];
    }
    if (count($rows) > CSV_MAX_ITEMS) {
        return [[], [t('Too many rows (at most %d).', CSV_MAX_ITEMS)]];
    }

    $seen = [];
    foreach ($rows as $row) {
        $line = $row['__line'];
        $err = function (string $msg) use (&$errors, $line) {
            $errors[] = t('Row %1$d: %2$s', $line, $msg);
        };

        $ref = $row['item_id'] ?? '';
        $text = $row['question_text'] ?? '';
        $type = csv_norm_type($row['response_type'] ?? '');
        $model = csv_norm_model($row['model'] ?? '');

        if ($ref === '') { $err(t('item_id is empty')); continue; }
        if ((function_exists('mb_strlen') ? mb_strlen($ref) : strlen($ref)) > 100) { $err(t('item_id is longer than 100 characters')); continue; }
        if (isset($seen[$ref])) { $err(t('item_id “%1$s” appears twice (also in row %2$d)', $ref, $seen[$ref])); continue; }
        $seen[$ref] = $line;
        if ($text === '') { $err(t('question_text is empty (%s)', $ref)); continue; }
        if ($type === null) { $err(t('unknown response_type “%s” (use dichotomous, multiple_choice or likert)', $row['response_type'] ?? '')); continue; }
        if ($model === null) { $err(t('unknown model “%s” (use 1PL, 2PL, 3PL or GRM)', $row['model'] ?? '')); continue; }

        if ($model === 'GRM' && $type !== 'likert') { $err(t('model GRM requires response_type likert')); continue; }
        if ($model !== 'GRM' && $type === 'likert') { $err(t('response_type likert requires model GRM (got %s)', $model)); continue; }

        $num = function (string $col) use ($row) {
            return isset($row[$col]) && $row[$col] !== '' ? parse_number($row[$col]) : null;
        };
        $has = fn(string $col) => isset($row[$col]) && $row[$col] !== '';

        $item = [
            'item_ref' => $ref, 'question_text' => $text, 'response_type' => $type, 'model' => $model,
            'a' => 1.0, 'b' => null, 'c' => 0.0, 'options' => null, 'correct_option' => null,
            'likert_levels' => null, 'likert_min_label' => null, 'likert_max_label' => null, 'reverse_scored' => 0,
        ];
        $rowOk = true;

        // a
        if ($has('a')) {
            $a = $num('a');
            if ($a === null || $a <= 0) { $err(t('a must be a positive number (got “%s”)', $row['a'])); $rowOk = false; }
            else { $item['a'] = $a; }
        } elseif ($model !== '1PL') {
            $err(t('a is required for %s', $model)); $rowOk = false;
        }

        // c
        if ($model === '3PL') {
            $c = $num('c');
            if ($c === null || $c < 0 || $c >= 1) { $err(t('c must be a number between 0 and 1 for 3PL')); $rowOk = false; }
            else { $item['c'] = $c; }
        } elseif ($has('c') && $num('c') !== 0.0) {
            $err(t('c is only allowed for 3PL (leave it empty or 0 for %s)', $model)); $rowOk = false;
        }

        // b / thresholds
        if ($model === 'GRM') {
            [$bs, $gap] = csv_numbered($row, '/^b(\d+)$/');
            if ($gap) { $err(t('GRM thresholds: %s', $gap)); $rowOk = false; }
            elseif (!$bs) {
                $err(t('GRM needs thresholds in the columns b1, b2, … (one threshold per column)')); $rowOk = false;
            } else {
                $thr = [];
                foreach ($bs as $i => $v) {
                    $x = parse_number($v);
                    if ($x === null) { $err(t('b%1$d is not a number (“%2$s”)', $i + 1, $v)); $rowOk = false; break; }
                    $thr[] = $x;
                }
                if ($rowOk) {
                    for ($i = 1; $i < count($thr); $i++) {
                        if ($thr[$i] <= $thr[$i - 1]) { $err(t('GRM thresholds must be strictly increasing (b1 < b2 < …)')); $rowOk = false; break; }
                    }
                    $item['b'] = $thr;
                    $item['likert_levels'] = count($thr) + 1;
                }
            }
        } else {
            $b = $num('b');
            if ($b === null) { $err($has('b') ? t('b must be a number (got “%s”)', $row['b']) : t('b is required')); $rowOk = false; }
            else { $item['b'] = $b; }
        }

        // options
        [$opts, $gap] = csv_numbered($row, '/^option_(\d+)$/');
        if ($gap) { $err(t('options: %s', $gap)); $rowOk = false; }

        if ($type === 'multiple_choice') {
            if (count($opts) < 2) { $err(t('multiple_choice needs at least two options (option_1, option_2, …)')); $rowOk = false; }
            $co = $num('correct_option');
            if ($co === null || $co != (int)$co || $co < 1 || $co > count($opts)) {
                $err(t('correct_option must be the number of the correct option (1 to %d)', count($opts))); $rowOk = false;
            } else {
                $item['correct_option'] = (int)$co;
            }
            $item['options'] = $opts;
        } elseif ($type === 'dichotomous') {
            if ($opts && count($opts) !== 2) { $err(t('dichotomous items need exactly two options (or none for yes/no)')); $rowOk = false; }
            $item['options'] = $opts ?: null;
            if ($has('correct_option')) {
                $co = $num('correct_option');
                if ($co !== 1.0 && $co !== 2.0) { $err(t('correct_option for dichotomous items must be 1 or 2 (the answer scored as 1)')); $rowOk = false; }
                else { $item['correct_option'] = (int)$co; }
            } else {
                $item['correct_option'] = 1;
            }
        } else { // likert
            if ($has('likert_levels') && $item['likert_levels'] !== null) {
                $lv = $num('likert_levels');
                if ($lv === null || (int)$lv !== $item['likert_levels']) {
                    $err(t('likert_levels (%1$s) does not match the number of thresholds + 1 (%2$d)', $row['likert_levels'], $item['likert_levels'])); $rowOk = false;
                }
            }
            if ($opts && $item['likert_levels'] !== null && count($opts) !== $item['likert_levels']) {
                $err(t('the number of option labels (%1$d) must equal the number of categories (%2$d)', count($opts), $item['likert_levels'])); $rowOk = false;
            }
            $item['options'] = $opts ?: null;
            $item['likert_min_label'] = ($row['likert_min_label'] ?? '') ?: null;
            $item['likert_max_label'] = ($row['likert_max_label'] ?? '') ?: null;
            $rev = csv_bool($row['reverse'] ?? '');
            if ($rev === null) { $err(t('reverse must be 0 or 1')); $rowOk = false; }
            else { $item['reverse_scored'] = $rev ? 1 : 0; }
        }
        if ($type !== 'likert' && csv_bool($row['reverse'] ?? '') === true) {
            $err(t('reverse is only used for likert items (for dichotomous items set correct_option instead)')); $rowOk = false;
        }

        if ($rowOk) {
            $items[] = $item;
        }
    }

    if (count($errors) > 60) {
        $more = count($errors) - 60;
        $errors = array_slice($errors, 0, 60);
        $errors[] = t('… and %d more errors.', $more);
    }
    return [$items, $errors];
}

/** Insert a validated item bank. Returns the new bank id. */
function csv_store_bank(string $name, string $filename, array $items, ?int $ownerId = null, bool $isExample = false): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO {item_banks} (name, source_filename, owner_id, is_example, created_at) VALUES (?, ?, ?, ?, ?)',
            [$name, $filename, $ownerId, $isExample ? 1 : 0, now_utc()]);
        $bankId = (int)$pdo->lastInsertId();
        $st = $pdo->prepare(sql('INSERT INTO {items}
            (bank_id, position, item_ref, question_text, response_type, model, a, b, c, options,
             correct_option, likert_levels, likert_min_label, likert_max_label, reverse_scored)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'));
        foreach ($items as $i => $it) {
            $st->execute([
                $bankId, $i + 1, $it['item_ref'], $it['question_text'], $it['response_type'], $it['model'],
                $it['a'], json_encode($it['b']), $it['c'],
                $it['options'] !== null ? json_encode($it['options'], JSON_UNESCAPED_UNICODE) : null,
                $it['correct_option'], $it['likert_levels'], $it['likert_min_label'], $it['likert_max_label'],
                $it['reverse_scored'],
            ]);
        }
        $pdo->commit();
        return $bankId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

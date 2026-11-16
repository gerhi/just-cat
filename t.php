<?php
/**
 * Test-taker page: /t/{slug}  (or t.php?slug={slug})
 *
 *   GET  without session → intro page (reads ?ref= from LimeSurvey)
 *   POST action=start    → create session, set cookie, redirect to clean URL
 *   GET  with session    → current question, or the end page
 *   POST action=answer   → score, re-estimate θ, pick next item or finish
 *
 * State lives in the database. The browser only holds a random token in a
 * session cookie (technically necessary, §25 Abs. 2 TDDDG – no banner needed).
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

const REF_PATTERN = '/^[A-Za-z0-9_.~-]{1,128}$/';

$slug = (string)($_GET['slug'] ?? '');
if (!preg_match('/^[a-z0-9-]{1,64}$/', $slug)) {
    page_message('404', texts(i18n_detect())['not_found'], i18n_detect(), 404);
}

$test = q_one('SELECT * FROM {tests} WHERE slug = ?', [$slug]);
if (!$test) {
    page_message('404', texts(i18n_detect())['not_found'], i18n_detect(), 404);
}
$L = texts($test['language']);
$lang = $test['language'];
$self = test_path($slug);
$cookieName = 'cat_t' . $test['id'];

// ─── Helpers ──────────────────────────────────────────────────────────

function current_session(array $test, string $cookieName): ?array
{
    $token = (string)($_COOKIE[$cookieName] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    return q_one('SELECT * FROM {sessions} WHERE token_hash = ? AND test_id = ?',
        [hash('sha256', $token), $test['id']]);
}

function set_session_cookie(string $name, string $value): void
{
    setcookie($name, $value, [
        'expires' => 0,                       // until the browser is closed
        'path' => base_path() . '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Return URL for LimeSurvey etc.: {ref} replaced, cat_complete=1 appended. */
function callback_target(array $test, array $session): ?string
{
    $tpl = trim((string)$test['callback_url']);
    if ($tpl === '') {
        return null;
    }
    $url = str_replace('{ref}', rawurlencode((string)$session['reference_token']), $tpl);
    $frag = '';
    if (($pos = strpos($url, '#')) !== false) {
        $frag = substr($url, $pos);
        $url = substr($url, 0, $pos);
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . 'cat_complete=1' . $frag;
}

/** All items of the bank, keyed by id. */
function bank_items(int $bankId): array
{
    $rows = q_all('SELECT * FROM {items} WHERE bank_id = ? ORDER BY position', [$bankId]);
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['id']] = $r;
    }
    return $out;
}

/** Pick the next item among those not yet administered (criterion + randomesque k). */
function next_item(array $items, array $usedIds, array $post, string $method, int $k): ?array
{
    $pool = [];
    foreach ($items as $id => $row) {
        if (!isset($usedIds[$id])) {
            $pool[] = engine_item($row);
        }
    }
    return cat_choose($pool, $post, $method, $k, 'random_int');
}

// ─── POST ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $session = current_session($test, $cookieName);

    if ($action === 'start') {
        if ($session) {
            redirect($self);
        }
        if (!$test['is_active']) {
            page_message($test['name'], $L['unavailable'], $lang, 403);
        }
        $ref = trim((string)($_POST['ref'] ?? ''));
        if ($ref !== '' && !preg_match(REF_PATTERN, $ref)) {
            page_message($test['name'], $L['invalid_ref'], $lang, 400);
        }
        if ($ref === '' && $test['require_ref']) {
            page_message($test['name'], $L['need_ref'], $lang, 400);
        }

        ensure_schema();
        $method = isset(CAT_METHODS[$test['selection_method'] ?? '']) ? $test['selection_method'] : 'mfi';
        $k = max(1, (int)($test['randomesque_k'] ?? 1));
        $items = bank_items((int)$test['bank_id']);
        $prior = cat_posterior(array_fill(0, CAT_GRID_N, 0.0));
        $first = next_item($items, [], $prior, $method, $k);
        if (!$first) {
            page_message($test['name'], $L['unavailable'], $lang, 500);
        }

        $token = bin2hex(random_bytes(32));
        $now = now_utc();
        // The selection settings are stored with the session, so changing them
        // in the admin area does not affect sessions already running.
        q('INSERT INTO {sessions} (test_id, token_hash, reference_token, selection_method, randomesque_k,
              status, current_item_id, items_administered, started_at, last_activity)
           VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)',
            [$test['id'], hash('sha256', $token), $ref !== '' ? $ref : null, $method, $k,
             'in_progress', $first['id'], $now, $now]);
        set_session_cookie($cookieName, $token);
        redirect($self); // clean URL: the ref token leaves the address bar and history
    }

    if ($action === 'answer') {
        if (!$session) {
            redirect($self);
        }
        if ($session['status'] !== 'in_progress') {
            redirect($self);
        }
        $itemId = (int)($_POST['item_id'] ?? 0);
        $raw = (int)($_POST['response'] ?? 0);

        // Only the question currently shown may be answered (double clicks, back button).
        if ($itemId <= 0 || $itemId !== (int)$session['current_item_id']) {
            redirect($self);
        }
        $itemRow = q_one('SELECT * FROM {items} WHERE id = ? AND bank_id = ?', [$itemId, $test['bank_id']]);
        $score = $itemRow ? score_response($itemRow, $raw) : null;
        if ($score === null) {
            redirect($self); // no/invalid answer: show the question again
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Claim the current item atomically: a second concurrent submit changes 0 rows.
            $claimed = q('UPDATE {sessions} SET current_item_id = NULL WHERE id = ? AND current_item_id = ?',
                [$session['id'], $itemId])->rowCount();
            if ($claimed !== 1) {
                $pdo->rollBack();
                redirect($self);
            }

            $items = bank_items((int)$test['bank_id']);
            $prev = q_all('SELECT item_id, scored_response FROM {responses} WHERE session_id = ? ORDER BY position',
                [$session['id']]);
            $scored = [];
            $used = [];
            foreach ($prev as $p) {
                $scored[] = ['item' => engine_item($items[(int)$p['item_id']]), 'score' => (int)$p['scored_response']];
                $used[(int)$p['item_id']] = true;
            }
            $scored[] = ['item' => engine_item($itemRow), 'score' => $score];
            $used[$itemId] = true;

            $post = cat_posterior(cat_loglik_sum($scored));
            $theta = $post['theta'];
            $sem = $post['sem'];
            $n = count($scored);
            $now = now_utc();

            q('INSERT INTO {responses} (session_id, item_id, position, raw_response, scored_response,
                  theta_after, sem_after, answered_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$session['id'], $itemId, $n, $raw, $score, $theta, $sem, $now]);

            [$stop, $reason] = cat_should_stop($n, $sem, (int)$test['max_items'], (float)$test['sem_threshold']);
            $method = $session['selection_method'] ?? ($test['selection_method'] ?? 'mfi');
            $k = (int)($session['randomesque_k'] ?? ($test['randomesque_k'] ?? 1));
            $next = $stop ? null : next_item($items, $used, $post, $method, $k);
            if (!$stop && !$next) {
                $stop = true;
                $reason = 'item_bank_exhausted';
            }

            if ($stop) {
                q('UPDATE {sessions} SET status = ?, items_administered = ?, theta = ?, sem = ?, stopping_reason = ?,
                      last_activity = ?, completed_at = ? WHERE id = ?',
                    ['completed', $n, $theta, $sem, $reason, $now, $now, $session['id']]);
            } else {
                q('UPDATE {sessions} SET current_item_id = ?, items_administered = ?, theta = ?, sem = ?,
                      last_activity = ? WHERE id = ?',
                    [$next['id'], $n, $theta, $sem, $now, $session['id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('CAT answer error: ' . $e->getMessage());
            page_message($test['name'], $L['error'], $lang, 500);
        }

        if ($stop) {
            $target = callback_target($test, $session);
            redirect($target ?? $self);
        }
        redirect($self);
    }

    redirect($self);
}

// ─── GET ──────────────────────────────────────────────────────────────

$session = current_session($test, $cookieName);

// Finished: thank-you page (with a link back to the survey if configured)
if ($session && $session['status'] === 'completed') {
    $target = callback_target($test, $session);
    page_start($L['done_title'], $lang);
    ?>
    <div class="sheet done">
      <div class="bubble" aria-hidden="true">✓</div>
      <h1 class="test-title"><?= h($L['done_title']) ?></h1>
      <?php if ($target): ?>
        <p><?= h($L['done_continue_text']) ?></p>
        <p><a class="btn btn-primary btn-lg" href="<?= h($target) ?>"><?= h($L['done_continue']) ?></a></p>
      <?php else: ?>
        <p><?= h($L['done_text']) ?></p>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    exit;
}

// In progress: show the current question
if ($session && $session['status'] === 'in_progress' && $session['current_item_id']) {
    $item = q_one('SELECT * FROM {items} WHERE id = ?', [$session['current_item_id']]);
    if (!$item) {
        page_message($test['name'], $L['error'], $lang, 500);
    }
    $labels = item_labels($item, $L);
    $num = (int)$session['items_administered'] + 1;
    $max = min((int)$test['max_items'], (int)q_val('SELECT COUNT(*) FROM {items} WHERE bank_id = ?', [$test['bank_id']]));
    $type = $item['response_type'];

    page_start($test['name'], $lang);
    ?>
    <div class="test-head">
      <span class="test-name"><?= h($test['name']) ?></span>
      <span><?= h(sprintf($L['progress'], $num, $max)) ?></span>
    </div>
    <?php if ($max <= 60): ?>
      <div class="ruler" aria-hidden="true"><?php for ($i = 1; $i <= $max; $i++): ?><span class="tick<?= $i < $num ? ' done' : ($i === $num ? ' now' : '') ?>"></span><?php endfor; ?></div>
    <?php else: ?>
      <progress class="bar" value="<?= $num - 1 ?>" max="<?= $max ?>" aria-hidden="true"></progress>
    <?php endif; ?>

    <form method="post" action="<?= h($self) ?>" class="sheet question">
      <input type="hidden" name="action" value="answer">
      <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
      <fieldset>
        <legend class="qtext"><?= nl2br(h($item['question_text'])) ?></legend>

        <?php if ($type === 'likert'): $labelled = (bool)$item['options']; ?>
          <div class="scale n<?= count($labels) ?><?= $labelled ? ' labelled' : '' ?>">
            <?php foreach ($labels as $i => $label): ?>
              <label class="point">
                <input type="radio" name="response" value="<?= $i + 1 ?>" required>
                <span class="bubble"><?= $i + 1 ?></span>
                <?php if ($labelled): ?><span class="plabel"><?= h($label) ?></span><?php endif; ?>
                <?php if (!$labelled && $i === 0 && $item['likert_min_label']): ?><span class="sr-only"><?= h($item['likert_min_label']) ?></span><?php endif; ?>
                <?php if (!$labelled && $i === count($labels) - 1 && $item['likert_max_label']): ?><span class="sr-only"><?= h($item['likert_max_label']) ?></span><?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>
          <?php if ($item['likert_min_label'] || $item['likert_max_label']): ?>
            <div class="anchors" aria-hidden="true">
              <span><?= h($item['likert_min_label']) ?></span><span><?= h($item['likert_max_label']) ?></span>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="choices">
            <?php foreach ($labels as $i => $label): ?>
              <label class="choice">
                <input type="radio" name="response" value="<?= $i + 1 ?>" required>
                <span class="bubble" aria-hidden="true"><?= $type === 'multiple_choice' ? chr(65 + $i) : '' ?></span>
                <span class="label"><?= h($label) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </fieldset>
      <div class="submit-row">
        <p class="note"><?= h($L['saved_note']) ?></p>
        <button type="submit" class="btn btn-primary btn-lg"><?= h($L['next']) ?></button>
      </div>
    </form>
    <?php
    page_end();
    exit;
}

if ($session) {
    // In progress but no current item (should not happen): fail safely.
    page_message($test['name'], $L['error'], $lang, 500);
}

// No session yet: intro page
if (!$test['is_active']) {
    page_message($test['name'], $L['unavailable'], $lang, 403);
}
$ref = trim((string)($_GET['ref'] ?? ''));
if ($ref !== '' && !preg_match(REF_PATTERN, $ref)) {
    page_message($test['name'], $L['invalid_ref'], $lang, 400);
}
if ($ref === '' && $test['require_ref']) {
    page_message($test['name'], $L['need_ref'], $lang, 400);
}

page_start($test['name'], $lang);
?>
<div class="sheet">
  <h1 class="test-title"><?= h($test['name']) ?></h1>
  <?php if (trim((string)$test['intro_text']) !== ''): ?>
    <div class="intro"><?= nl2br(h($test['intro_text'])) ?></div>
  <?php endif; ?>
  <p class="note"><?= h($L['info_adaptive']) ?></p>
  <form method="post" action="<?= h($self) ?>">
    <input type="hidden" name="action" value="start">
    <input type="hidden" name="ref" value="<?= h($ref) ?>">
    <button type="submit" class="btn btn-primary btn-lg"><?= h($L['start']) ?></button>
  </form>
</div>
<?php
page_end();

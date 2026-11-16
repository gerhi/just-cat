<?php
/**
 * CAT engine: IRT models, EAP estimation, maximum-information item selection,
 * stopping rules.
 *
 * Models (logistic metric, no D = 1.7 scaling):
 *   1PL  P = 1 / (1 + exp(-a(θ - b)))          a = common discrimination (default 1)
 *   2PL  P = 1 / (1 + exp(-a(θ - b)))
 *   3PL  P = c + (1 - c) / (1 + exp(-a(θ - b)))
 *   GRM  P*(X >= k) = 1 / (1 + exp(-a(θ - b_k))),  P(X = k) = P*_k - P*_{k+1}
 *
 * An item is an array: ['id' => int, 'model' => '1PL'|'2PL'|'3PL'|'GRM',
 *                       'a' => float, 'b' => float|float[], 'c' => float]
 * A scored response is: ['item' => item, 'score' => int]
 *   dichotomous models: score 0/1; GRM: category 0..K
 */

declare(strict_types=1);

defined('CAT') or exit;

const CAT_GRID_MIN = -4.5;
const CAT_GRID_MAX = 4.5;
const CAT_GRID_N   = 901;
const CAT_EPS      = 1e-10;

/** Quadrature grid and log N(0,1) prior (constant omitted), computed once. */
function cat_grid(): array
{
    static $grid = null;
    if ($grid === null) {
        $step = (CAT_GRID_MAX - CAT_GRID_MIN) / (CAT_GRID_N - 1);
        $theta = [];
        $logPrior = [];
        for ($i = 0; $i < CAT_GRID_N; $i++) {
            $t = CAT_GRID_MIN + $i * $step;
            $theta[] = $t;
            $logPrior[] = -0.5 * $t * $t;
        }
        $grid = [$theta, $logPrior];
    }
    return $grid;
}

function cat_logistic(float $x): float
{
    // Numerically stable logistic
    if ($x >= 0) {
        return 1.0 / (1.0 + exp(-$x));
    }
    $e = exp($x);
    return $e / (1.0 + $e);
}

/** P(correct) for 1PL/2PL/3PL. */
function cat_prob_dich(float $theta, array $item): float
{
    $p = cat_logistic($item['a'] * ($theta - (float)$item['b']));
    if ($item['model'] === '3PL') {
        $p = $item['c'] + (1.0 - $item['c']) * $p;
    }
    return $p;
}

/** Cumulative boundary probabilities P*_0..P*_{K+1} for GRM. */
function cat_grm_cumulative(float $theta, array $item): array
{
    $cum = [1.0];
    foreach ($item['b'] as $bk) {
        $cum[] = cat_logistic($item['a'] * ($theta - (float)$bk));
    }
    $cum[] = 0.0;
    return $cum;
}

/** Category probabilities P(X = 0..K) for GRM (floored at CAT_EPS). */
function cat_grm_probs(float $theta, array $item): array
{
    $cum = cat_grm_cumulative($theta, $item);
    $probs = [];
    $n = count($cum) - 1;
    for ($k = 0; $k < $n; $k++) {
        $probs[] = max($cum[$k] - $cum[$k + 1], CAT_EPS);
    }
    return $probs;
}

/** Fisher information of one item at theta. */
function cat_info(float $theta, array $item): float
{
    if ($item['model'] === 'GRM') {
        $a = $item['a'];
        $cum = cat_grm_cumulative($theta, $item);
        $n = count($cum);
        $dcum = [];
        for ($k = 0; $k < $n; $k++) {
            $dcum[] = ($k === 0 || $k === $n - 1) ? 0.0 : $a * $cum[$k] * (1.0 - $cum[$k]);
        }
        $info = 0.0;
        for ($k = 0; $k < $n - 1; $k++) {
            $p = max($cum[$k] - $cum[$k + 1], CAT_EPS);
            $d = $dcum[$k] - $dcum[$k + 1];
            $info += $d * $d / $p;
        }
        return $info;
    }

    $a = $item['a'];
    $p = cat_prob_dich($theta, $item);
    $q = 1.0 - $p;
    if ($item['model'] === '3PL') {
        $c = $item['c'];
        return ($a * $a) * (($p - $c) ** 2) * $q / (((1.0 - $c) ** 2) * max($p, CAT_EPS));
    }
    return $a * $a * $p * $q;
}

/**
 * EAP estimate with N(0,1) prior on a 901-point grid over [-4.5, 4.5].
 * Returns [theta, sem]; with no responses returns [null, null].
 */
function cat_eap(array $responses): array
{
    if (!$responses) {
        return [null, null];
    }
    [$grid, $logPrior] = cat_grid();
    $logPost = $logPrior;

    foreach ($responses as $r) {
        $item = $r['item'];
        $score = (int)$r['score'];
        if ($item['model'] === 'GRM') {
            foreach ($grid as $i => $t) {
                $probs = cat_grm_probs($t, $item);
                $logPost[$i] += log($probs[$score]);
            }
        } else {
            foreach ($grid as $i => $t) {
                $p = cat_prob_dich($t, $item);
                $p = min(max($p, CAT_EPS), 1.0 - CAT_EPS);
                $logPost[$i] += $score === 1 ? log($p) : log(1.0 - $p);
            }
        }
    }

    $max = max($logPost);
    $sum = 0.0;
    $mean = 0.0;
    $w = [];
    foreach ($logPost as $i => $lp) {
        $w[$i] = exp($lp - $max);
        $sum += $w[$i];
        $mean += $grid[$i] * $w[$i];
    }
    $mean /= $sum;
    $var = 0.0;
    foreach ($w as $i => $wi) {
        $d = $grid[$i] - $mean;
        $var += $d * $d * $wi;
    }
    $var /= $sum;

    return [$mean, sqrt(max($var, 1e-6))];
}

/** Item selection criteria (key => label shown in the admin area). */
const CAT_METHODS = [
    'mfi'  => 'Maximum Fisher information',
    'mpwi' => 'Maximum posterior-weighted information',
    'mepv' => 'Minimum expected posterior variance',
];

/** Short labels for tables. */
const CAT_METHODS_SHORT = [
    'mfi'  => 'Fisher information',
    'mpwi' => 'Posterior-weighted information',
    'mepv' => 'Expected posterior variance',
];

/**
 * Category probabilities of an item at every grid point: [category][gridIndex].
 * Probabilities are bounded away from 0/1 exactly as in the likelihood.
 * Cached per parameter set for the duration of the request.
 */
function cat_grid_probs(array $item): array
{
    static $cache = [];
    $key = json_encode([$item['model'], $item['a'], $item['b'], $item['c']]);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    [$grid] = cat_grid();
    $P = [];
    if ($item['model'] === 'GRM') {
        foreach ($grid as $g => $t) {
            foreach (cat_grm_probs($t, $item) as $k => $p) {
                $P[$k][$g] = $p;
            }
        }
    } else {
        foreach ($grid as $g => $t) {
            $p = min(max(cat_prob_dich($t, $item), CAT_EPS), 1.0 - CAT_EPS);
            $P[0][$g] = 1.0 - $p;
            $P[1][$g] = $p;
        }
    }
    return $cache[$key] = $P;
}

/** Fisher information of an item at every grid point (cached). */
function cat_grid_info(array $item): array
{
    static $cache = [];
    $key = json_encode([$item['model'], $item['a'], $item['b'], $item['c']]);
    if (!isset($cache[$key])) {
        [$grid] = cat_grid();
        $cache[$key] = array_map(fn($t) => cat_info($t, $item), $grid);
    }
    return $cache[$key];
}

/** Log-likelihood of one scored response at every grid point. */
function cat_loglik_vector(array $item, int $score): array
{
    return array_map('log', cat_grid_probs($item)[$score]);
}

/**
 * Posterior on the grid from a summed log-likelihood vector (all zeros = prior).
 * Returns ['w' => normalized weights, 'theta' => EAP, 'sem' => posterior SD].
 */
function cat_posterior(array $logLik): array
{
    [$grid, $logPrior] = cat_grid();
    $max = -INF;
    foreach ($logLik as $i => $ll) {
        $logLik[$i] = $ll + $logPrior[$i];
        if ($logLik[$i] > $max) {
            $max = $logLik[$i];
        }
    }
    $sum = 0.0;
    $mean = 0.0;
    $w = [];
    foreach ($logLik as $i => $lp) {
        $w[$i] = exp($lp - $max);
        $sum += $w[$i];
        $mean += $grid[$i] * $w[$i];
    }
    $mean /= $sum;
    $var = 0.0;
    foreach ($w as $i => $wi) {
        $w[$i] = $wi / $sum;
        $d = $grid[$i] - $mean;
        $var += $d * $d * $w[$i];
    }
    return ['w' => $w, 'theta' => $mean, 'sem' => sqrt(max($var, 1e-6))];
}

/** EAP from a summed log-likelihood vector. Returns [theta, sem]. */
function cat_eap_from_loglik(array $logLik): array
{
    $post = cat_posterior($logLik);
    return [$post['theta'], $post['sem']];
}

/** Summed log-likelihood vector for a list of scored responses. */
function cat_loglik_sum(array $responses): array
{
    $sum = array_fill(0, CAT_GRID_N, 0.0);
    foreach ($responses as $r) {
        foreach (cat_grid_probs($r['item'])[(int)$r['score']] as $g => $p) {
            $sum[$g] += log($p);
        }
    }
    return $sum;
}

/**
 * Criterion value for each pool item (higher = better).
 *   mfi  : Fisher information at the EAP estimate
 *   mpwi : Fisher information averaged over the posterior
 *   mepv : minus the expected posterior variance after answering the item
 */
function cat_item_scores(string $method, array $pool, array $post): array
{
    $w = $post['w'];
    [$grid] = cat_grid();
    $scores = [];

    if ($method === 'mepv') {
        $m2 = 0.0; // E[θ²] under the current posterior (same for all items)
        foreach ($grid as $g => $t) {
            $m2 += $w[$g] * $t * $t;
        }
        foreach ($pool as $i => $item) {
            // EPV = Σ_k S0_k·Var_k = E[θ²] − Σ_k S1_k² / S0_k
            $sumTerm = 0.0;
            foreach (cat_grid_probs($item) as $Pk) {
                $s0 = 0.0;
                $s1 = 0.0;
                foreach ($Pk as $g => $p) {
                    $wp = $w[$g] * $p;
                    $s0 += $wp;
                    $s1 += $wp * $grid[$g];
                }
                if ($s0 > 0) {
                    $sumTerm += $s1 * $s1 / $s0;
                }
            }
            $scores[$i] = -($m2 - $sumTerm);
        }
        return $scores;
    }

    if ($method === 'mpwi') {
        foreach ($pool as $i => $item) {
            $s = 0.0;
            foreach (cat_grid_info($item) as $g => $info) {
                $s += $w[$g] * $info;
            }
            $scores[$i] = $s;
        }
        return $scores;
    }

    foreach ($pool as $i => $item) {
        $scores[$i] = cat_info($post['theta'], $item);
    }
    return $scores;
}

/**
 * Choose the next item: rank the pool by the criterion and pick at random
 * among the best $k ("randomesque"; k = 1 → always the best item).
 * $rand(int $min, int $max): random integer source (random_int for live tests,
 * a seeded generator for simulations).
 */
function cat_choose(array $pool, array $post, string $method, int $k, callable $rand): ?array
{
    if (!$pool) {
        return null;
    }
    $pool = array_values($pool);
    $scores = cat_item_scores(isset(CAT_METHODS[$method]) ? $method : 'mfi', $pool, $post);
    $order = array_keys($pool);
    usort($order, fn($x, $y) => ($scores[$y] <=> $scores[$x]) ?: ($x <=> $y));
    $k = max(1, min($k, count($order)));
    return $pool[$order[$k === 1 ? 0 : $rand(0, $k - 1)]];
}

/** Maximum Fisher information item at theta (first item wins ties). */
function cat_select(float $theta, array $pool): ?array
{
    $best = null;
    $bestInfo = -1.0;
    foreach ($pool as $item) {
        $info = cat_info($theta, $item);
        if ($info > $bestInfo) {
            $bestInfo = $info;
            $best = $item;
        }
    }
    return $best;
}

/**
 * Stopping rules: maximum number of items, or SEM at/below threshold
 * (threshold 0 disables the SEM rule). Returns [stop, reason].
 */
function cat_should_stop(int $nItems, ?float $sem, int $maxItems, float $semThreshold): array
{
    if ($nItems >= $maxItems) {
        return [true, 'max_items'];
    }
    if ($semThreshold > 0 && $sem !== null && $sem <= $semThreshold) {
        return [true, 'sem_threshold'];
    }
    return [false, null];
}

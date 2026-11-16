<?php
/**
 * Small server-rendered SVG charts (no JavaScript, no inline styles: CSP-safe).
 */

declare(strict_types=1);

defined('CAT') or exit;

/**
 * Test information curve of an item bank over θ ∈ [−4, 4], with reference
 * lines for the information needed to reach SEM 0.30 and 0.40.
 */
function info_curve_svg(array $items): string
{
    $W = 640; $H = 220; $L = 44; $R = 16; $T = 14; $B = 34;
    $pw = $W - $L - $R;
    $ph = $H - $T - $B;
    $pts = [];
    $max = 0.0;
    for ($t = -4.0; $t <= 4.0001; $t += 0.05) {
        $info = 0.0;
        foreach ($items as $it) {
            $info += cat_info($t, $it);
        }
        $pts[] = [$t, $info];
        $max = max($max, $info);
    }
    // nice y-axis maximum
    $step = $max <= 2 ? 0.5 : ($max <= 5 ? 1 : ($max <= 12 ? 2 : ($max <= 30 ? 5 : ($max <= 60 ? 10 : 20))));
    $ymax = max($step, ceil($max / $step) * $step);
    $x = fn($t) => $L + ($t + 4) / 8 * $pw;
    $y = fn($v) => $T + $ph - $v / $ymax * $ph;

    $svg = '<svg class="chart info-curve" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Test information by theta">';
    for ($v = 0; $v <= $ymax + 1e-9; $v += $step) {
        $svg .= sprintf('<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" class="gridline"/>', $L, $y($v), $W - $R, $y($v));
        $svg .= sprintf('<text x="%d" y="%.1f" class="tick" text-anchor="end">%s</text>', $L - 8, $y($v) + 4, rtrim(rtrim(number_format($v, 1), '0'), '.'));
    }
    for ($t = -4; $t <= 4; $t++) {
        $svg .= sprintf('<text x="%.1f" y="%d" class="tick" text-anchor="middle">%s</text>', $x($t), $H - $B + 18, $t < 0 ? '−' . abs($t) : (string)$t);
    }
    $svg .= sprintf('<text x="%.1f" y="%d" class="axis-label" text-anchor="middle">θ</text>', $L + $pw / 2, $H - 2);
    foreach ([0.30, 0.40] as $sem) {
        $need = 1 / ($sem * $sem);
        if ($need <= $ymax) {
            $svg .= sprintf('<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" class="refline"/>', $L, $y($need), $W - $R, $y($need));
            $svg .= sprintf('<text x="%d" y="%.1f" class="reflabel" text-anchor="end">SEM %.2f</text>', $W - $R - 4, $y($need) - 5, $sem);
        }
    }
    $d = '';
    foreach ($pts as $i => [$t, $v]) {
        $d .= ($i ? 'L' : 'M') . sprintf('%.1f,%.1f', $x($t), $y($v));
    }
    $area = $d . sprintf('L%.1f,%.1fL%.1f,%.1fZ', $x(4), $y(0), $x(-4), $y(0));
    $svg .= '<path d="' . $area . '" class="area"/><path d="' . $d . '" class="line"/>';
    $svg .= sprintf('<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" class="axis"/>', $L, $y(0), $W - $R, $y(0));
    return $svg . '</svg>';
}

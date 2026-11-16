<?php
/**
 * Interface translations.
 *
 * Source strings are English and double as keys. Translations live in
 * lib/lang/{code}.json (key = English string). Missing entries fall back
 * to English. Placeholders follow sprintf (%s, %d, %1$s …).
 *
 *   t('Text')        translated text (raw — escape it yourself)
 *   te('Text')       translated and HTML-escaped
 *   tm('Text <b>%s</b>', $x)   trusted template with markup; arguments are escaped
 */

declare(strict_types=1);

defined('CAT') or exit;

const CAT_LANGS = [
    'de' => 'Deutsch',
    'en' => 'English',
    'fr' => 'Français',
    'es' => 'Español',
    'ja' => '日本語',
    'zh' => '中文',
];

function i18n_set(string $lang): void
{
    $GLOBALS['CAT_LANG'] = isset(CAT_LANGS[$lang]) ? $lang : 'en';
}

function i18n_lang(): string
{
    return $GLOBALS['CAT_LANG'] ?? 'en';
}

/** Value for <html lang="…">. */
function html_lang(?string $lang = null): string
{
    $lang = $lang ?? i18n_lang();
    return ['zh' => 'zh-Hans'][$lang] ?? $lang;
}

/** Best match from the browser's Accept-Language header. */
function i18n_detect(): string
{
    $header = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    $best = 'en';
    $bestQ = -1.0;
    foreach (explode(',', $header) as $part) {
        $bits = explode(';', trim($part));
        $code = substr(trim($bits[0]), 0, 2);
        $q = 1.0;
        if (isset($bits[1]) && preg_match('/q=([0-9.]+)/', $bits[1], $m)) {
            $q = (float)$m[1];
        }
        if (isset(CAT_LANGS[$code]) && $q > $bestQ) {
            $best = $code;
            $bestQ = $q;
        }
    }
    return $best;
}

function i18n_dict(string $lang): array
{
    static $dicts = [];
    if (!isset($dicts[$lang])) {
        $file = __DIR__ . '/lang/' . $lang . '.json';
        $dicts[$lang] = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    }
    return $dicts[$lang];
}

function t(string $text, ...$args): string
{
    $lang = i18n_lang();
    if ($lang !== 'en') {
        $dict = i18n_dict($lang);
        if (isset($dict[$text]) && $dict[$text] !== '') {
            $text = $dict[$text];
        }
    }
    return $args ? vsprintf($text, $args) : $text;
}

function te(string $text, ...$args): string
{
    return htmlspecialchars(t($text, ...$args), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tm(string $text, ...$args): string
{
    $safe = array_map(fn($a) => is_string($a) ? htmlspecialchars($a, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $a, $args);
    return t($text, ...$safe);
}

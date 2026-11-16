<?php
/**
 * Page layout helpers (no inline styles or scripts: the CSP forbids them).
 */

declare(strict_types=1);

defined('CAT') or exit;

function head_tags(string $title): void
{
    ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?></title>
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>?v=<?= CAT_VERSION ?>">
<link rel="icon" href="<?= h(url('assets/favicon.svg')) ?>" type="image/svg+xml">
<?php
}

function page_start(string $title, string $lang = 'de'): void
{
    ?><!doctype html>
<html lang="<?= h(html_lang($lang)) ?>">
<head>
<?php head_tags($title); ?>
</head>
<body class="participant">
<main class="wrap">
<?php
}

function page_end(): void
{
    ?>
</main>
</body>
</html>
<?php
}

/** Simple message page for participants (not found, unavailable, errors). */
function page_message(string $title, string $text, string $lang = 'de', int $status = 200): void
{
    http_response_code($status);
    page_start($title, $lang);
    echo '<div class="sheet message"><h1 class="test-title">' . h($title) . '</h1><p>' . h($text) . '</p></div>';
    page_end();
    exit;
}

/** Links to switch the interface language (returns to the current page). */
function language_menu(): string
{
    $back = (string)($_SERVER['REQUEST_URI'] ?? url('admin/index.php'));
    $out = '<details class="langs"><summary>' . te('Language') . ': ' . h(CAT_LANGS[i18n_lang()]) . '</summary><ul>';
    foreach (CAT_LANGS as $code => $name) {
        $href = url('admin/lang.php?set=' . $code . '&back=' . rawurlencode($back));
        $out .= '<li><a href="' . h($href) . '" lang="' . h(html_lang($code)) . '"'
            . ($code === i18n_lang() ? ' aria-current="true"' : '') . '>' . h($name) . '</a></li>';
    }
    return $out . '</ul></details>';
}

/**
 * Admin page frame with side navigation.
 * $actions: optional HTML for buttons next to the page title.
 */
function admin_start(string $title, string $active = '', string $actions = ''): void
{
    $user = current_user();
    $groups = [
        t('Studies') => [
            'tests' => ['admin/tests.php', t('Tests')],
            'sessions' => ['admin/sessions.php', t('Sessions')],
            'export' => ['admin/export.php', t('Export')],
        ],
        t('Item banks') => [
            'banks' => ['admin/banks.php', t('Item banks')],
            'score' => ['admin/score.php', t('Rescore')],
        ],
    ];
    if ($user && $user['role'] === 'admin') {
        $groups[t('Administration')] = ['users' => ['admin/users.php', t('Accounts')]];
    }
    $groups[t('Help')] = [
        'help-en' => ['admin/help.php?lang=en', 'English'],
        'help-de' => ['admin/help.php?lang=de', 'Deutsch'],
    ];
    ?><!doctype html>
<html lang="<?= h(html_lang()) ?>">
<head>
<?php head_tags($title . ' – ' . t('CAT admin')); ?>
<script src="<?= h(url('assets/admin.js')) ?>?v=<?= CAT_VERSION ?>" defer></script>
</head>
<body class="admin">
<aside class="sidebar">
  <a class="brand" href="<?= h(url('admin/index.php')) ?>"<?= $active === 'overview' ? ' aria-current="page"' : '' ?>><span class="bubble" aria-hidden="true">θ</span><?= te('CAT admin') ?></a>
  <?php if ($user): ?>
  <?php foreach ($groups as $label => $links): ?>
    <nav class="nav-group" aria-label="<?= h($label) ?>">
      <span class="group-label"><?= h($label) ?></span>
      <?php foreach ($links as $key => [$href, $text]): ?>
        <a href="<?= h(url($href)) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= h($text) ?></a>
      <?php endforeach; ?>
    </nav>
  <?php endforeach; ?>
  <div class="bottom">
    <div class="who">
      <strong><?= h($user['name']) ?></strong>
      <span class="muted small"><?= h(role_label($user['role'])) ?></span>
    </div>
    <?= language_menu() ?>
    <nav class="nav-group" aria-label="<?= te('Account') ?>">
      <a href="<?= h(url('admin/password.php')) ?>"<?= $active === 'password' ? ' aria-current="page"' : '' ?>><?= te('Change password') ?></a>
    </nav>
    <form method="post" action="<?= h(url('admin/logout.php')) ?>" class="logout">
      <?= csrf_field() ?><button type="submit" class="linklike"><?= te('Log out') ?></button>
    </form>
  </div>
  <?php endif; ?>
</aside>
<div class="main"><div class="content">
<div class="page-head"><h1><?= h($title) ?></h1><?= $actions ?></div>
<?= flash_html() ?>
<?php
}

function admin_end(): void
{
    ?>
<p class="foot">JUST – <?= te('Adaptive testing, version %s', CAT_VERSION) ?></p>
</div></div>
</body>
</html>
<?php
}

/** Translated name of an item selection criterion. */
function method_label(string $method, bool $short = false): string
{
    if ($short) {
        return match ($method) {
            'mpwi' => t('Posterior-weighted information'),
            'mepv' => t('Expected posterior variance'),
            default => t('Fisher information'),
        };
    }
    return match ($method) {
        'mpwi' => t('Maximum posterior-weighted information'),
        'mepv' => t('Minimum expected posterior variance'),
        default => t('Maximum Fisher information'),
    };
}

/** Translated session status. */
function status_label(string $status): string
{
    return match ($status) {
        'completed' => t('Completed'),
        'abandoned' => t('Abandoned'),
        default => t('In progress'),
    };
}

/** Translated stopping reason. */
function reason_label(?string $reason): string
{
    return match ((string)$reason) {
        'max_items' => t('Maximum number of items'),
        'sem_threshold' => t('SEM threshold'),
        'item_bank_exhausted' => t('No items left'),
        '' => '',
        default => (string)$reason,
    };
}

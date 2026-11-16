<?php
/**
 * Help: shows README.md (English) or README.de.md (German) from the
 * application folder, rendered as HTML.
 */

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/markdown.php';
require_login();

$lang = ($_GET['lang'] ?? 'en') === 'de' ? 'de' : 'en';
$file = __DIR__ . '/../' . ($lang === 'de' ? 'README.de.md' : 'README.md');
$md = is_file($file) ? (string)file_get_contents($file) : '';
[$html, $toc] = md_render($md);
// The README's own title becomes the page title.
$html = preg_replace('#^<h1[^>]*>.*?</h1>\s*#s', '', $html, 1);

admin_start(t('Help'), $lang === 'de' ? 'help-de' : 'help-en');
if (!in_array(i18n_lang(), ['de', 'en'], true)) {
    echo '<p class="muted">' . te('The detailed help is available in English and German.') . '</p>';
}
?>
<div class="help-layout">
  <nav class="toc" aria-label="<?= te('Contents') ?>">
    <div class="lang-switch">
      <a href="<?= h(url('admin/help.php?lang=en')) ?>"<?= $lang === 'en' ? ' aria-current="page"' : '' ?>>English</a>
      <a href="<?= h(url('admin/help.php?lang=de')) ?>"<?= $lang === 'de' ? ' aria-current="page"' : '' ?>>Deutsch</a>
    </div>
    <ol>
      <?php foreach ($toc as [$id, $title]): ?>
        <li><a href="#<?= h($id) ?>"><?= h(preg_replace('/^\d+[a-z]?\.\s*/', '', strip_tags($title))) ?></a></li>
      <?php endforeach; ?>
    </ol>
  </nav>
  <article class="doc" lang="<?= h($lang) ?>">
    <?= $md === '' ? '<p class="muted">' . te('The help file was not found.') . '</p>' : $html ?>
  </article>
</div>
<?php
admin_end();

<?php
/**
 * Installer: enter database details, test the connection, create the first
 * administrator. Creates the tables and writes lib/config.php.
 * Delete this file after installation (there is a button for that).
 */

declare(strict_types=1);

define('CAT', true);
define('CAT_VERSION', '1.2.0');
require __DIR__ . '/lib/i18n.php';
require __DIR__ . '/lib/schema.php';

ini_set('display_errors', '0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'");
header('Cache-Control: no-store');

$CONFIG_FILE = __DIR__ . '/lib/config.php';
$BASE_PATH = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

// Language: ?lang=xx (kept in the form), else the browser's language.
$lang = (string)($_POST['lang'] ?? $_GET['lang'] ?? '');
i18n_set(isset(CAT_LANGS[$lang]) ? $lang : i18n_detect());

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function inst_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/** Connect to MySQL with the form values. Throws PDOException. */
function inst_connect(array $f): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $f['db_host'], (int)$f['db_port'], $f['db_name']);
    return new PDO($dsn, $f['db_user'], $f['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function inst_hint(string $msg): string
{
    $m = strtolower($msg);
    if (str_contains($m, 'access denied')) {
        return t('The user name or password is wrong, or this user has no rights on the database.');
    }
    if (str_contains($m, 'unknown database')) {
        return t('The database does not exist. Create it in your hosting control panel first, then use exactly that name.');
    }
    if (str_contains($m, 'no such file') || str_contains($m, 'connection refused') || str_contains($m, 'getaddrinfo')
        || str_contains($m, 'name or service') || str_contains($m, 'timed out')) {
        return t('The database server was not reached. Check the host name your provider gives you (sometimes it is not “localhost”), or try 127.0.0.1.');
    }
    return '';
}

function inst_page_start(string $title): void
{
    ?><!doctype html>
<html lang="<?= e(html_lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= CAT_VERSION ?>">
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
</head>
<body class="plain">
<header class="topbar">
  <span class="brand"><span class="bubble" aria-hidden="true">θ</span>JUST – <?= te('Adaptive testing: installation') ?></span>
  <nav class="lang-row" aria-label="<?= te('Language') ?>">
    <?php foreach (CAT_LANGS as $code => $name): ?>
      <a href="?lang=<?= e($code) ?>" lang="<?= e(html_lang($code)) ?>"<?= $code === i18n_lang() ? ' aria-current="true"' : '' ?>><?= e($name) ?></a>
    <?php endforeach; ?>
  </nav>
</header>
<main class="wrap">
<h1><?= e($title) ?></h1>
<?php
}

function inst_page_end(): void
{
    echo "</main>\n</body>\n</html>\n";
}

$action = $_POST['action'] ?? '';
$langField = '<input type="hidden" name="lang" value="' . e(i18n_lang()) . '">';

// ─── Already installed ────────────────────────────────────────────────
if (is_file($CONFIG_FILE)) {
    $deleted = false;
    $deleteError = '';
    if ($action === 'delete_installer') {
        $deleted = @unlink(__FILE__);
        if (!$deleted) {
            $deleteError = t('install.php could not be deleted automatically. Delete it by FTP or in your hosting file manager.');
        }
    }
    inst_page_start($deleted ? t('Installer deleted') : t('Already installed'));
    if ($deleted) {
        echo '<div class="alert alert-success">' . te('install.php has been deleted.') . '</div>';
    } else {
        if ($deleteError) {
            echo '<div class="alert alert-error">' . e($deleteError) . '</div>';
        }
        echo '<p>' . tm('The application is installed (<code>lib/config.php</code> exists). To install again, delete <code>lib/config.php</code> first. Existing tables and data are kept.') . '</p>';
        echo '<div class="alert alert-warning">' . tm('For security, delete <code>install.php</code> now.') . '</div>';
        echo '<form method="post">' . $langField . '<input type="hidden" name="action" value="delete_installer">'
            . '<button class="btn btn-danger" type="submit">' . te('Delete install.php') . '</button></form>';
    }
    echo '<p class="mt"><a class="btn" href="admin/login.php">' . te('Go to the login page') . '</a></p>';
    inst_page_end();
    exit;
}

// ─── Form values ──────────────────────────────────────────────────────
$f = [
    'db_host' => trim($_POST['db_host'] ?? 'localhost'),
    'db_port' => trim($_POST['db_port'] ?? '3306'),
    'db_name' => trim($_POST['db_name'] ?? ''),
    'db_user' => trim($_POST['db_user'] ?? ''),
    'db_pass' => (string)($_POST['db_pass'] ?? ''),
    'db_prefix' => trim($_POST['db_prefix'] ?? 'cat_'),
    'admin_name' => trim($_POST['admin_name'] ?? ''),
    'admin_username' => trim($_POST['admin_username'] ?? 'admin'),
    'admin_email' => trim($_POST['admin_email'] ?? ''),
    'timezone' => trim($_POST['timezone'] ?? 'Europe/Berlin'),
    'force_https' => $action ? !empty($_POST['force_https']) : inst_is_https(),
    'pretty_urls' => $action ? !empty($_POST['pretty_urls']) : true,
];

$errors = [];
$testResult = null;   // ['ok' => bool, 'lines' => [...]]
$installed = null;    // ['written' => bool, 'config' => string]

function inst_validate_db(array $f): array
{
    $err = [];
    if ($f['db_host'] === '') $err[] = t('The database host is required.');
    if (!ctype_digit($f['db_port']) || (int)$f['db_port'] < 1 || (int)$f['db_port'] > 65535) $err[] = t('The port must be a number (usually 3306).');
    if ($f['db_name'] === '') $err[] = t('The database name is required.');
    if ($f['db_user'] === '') $err[] = t('The database user is required.');
    if (!preg_match('/^[a-zA-Z0-9_]{0,20}$/', $f['db_prefix'])) $err[] = t('The table prefix may only contain letters, digits and _ (at most 20).');
    return $err;
}

if ($action === 'test' || $action === 'install') {
    $errors = inst_validate_db($f);

    if ($action === 'install') {
        $pw = (string)($_POST['admin_password'] ?? '');
        $pw2 = (string)($_POST['admin_password2'] ?? '');
        if ($f['admin_name'] === '') $errors[] = t('Name is required.');
        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $f['admin_username'])) $errors[] = t('User name: 3 to 64 characters, letters, digits, dot, hyphen and underscore.');
        if ($f['admin_email'] !== '' && !filter_var($f['admin_email'], FILTER_VALIDATE_EMAIL)) $errors[] = t('The e-mail address is not valid.');
        if (strlen($pw) < 10) $errors[] = t('The password must have at least 10 characters.');
        elseif ($pw !== $pw2) $errors[] = t('The two passwords do not match.');
        if (!in_array($f['timezone'], timezone_identifiers_list(), true)) $errors[] = t('Unknown time zone (example: Europe/Berlin).');
    }

    if (!$errors) {
        try {
            $pdo = inst_connect($f);
            $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            $p = $f['db_prefix'];

            if ($action === 'test') {
                $lines = [t('Connected to MySQL/MariaDB %s.', $version)];
                $probe = $p . 'install_probe';
                $pdo->exec("CREATE TABLE IF NOT EXISTS `$probe` (id INT) ENGINE=InnoDB");
                $pdo->exec("DROP TABLE `$probe`");
                $lines[] = t('This user may create tables.');
                $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $p) . '%';
                $st = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?');
                $st->execute([$like]);
                $existing = $st->fetchAll(PDO::FETCH_COLUMN);
                $ours = array_intersect($existing, array_map(fn($t) => $p . $t, ['users', 'item_banks', 'items', 'tests', 'sessions', 'responses']));
                if ($ours) {
                    $lines[] = t('Tables with this prefix already exist (%s). They will be kept, including their data.', implode(', ', $ours));
                }
                $testResult = ['ok' => true, 'lines' => $lines];
            } else {
                foreach (cat_schema('mysql', $p) as $stmt) {
                    $pdo->exec($stmt);
                }
                cat_migrate($pdo, 'mysql', $p); // tables kept from an older version

                // First administrator. If the user name exists already (reinstall), it gets the new password.
                $hash = password_hash($pw, PASSWORD_DEFAULT);
                $st = $pdo->prepare("SELECT id FROM {$p}users WHERE username = ?");
                $st->execute([$f['admin_username']]);
                $existingId = $st->fetchColumn();
                if ($existingId) {
                    $pdo->prepare("UPDATE {$p}users SET name = ?, email = ?, password_hash = ?, role = 'admin', is_active = 1, must_change_password = 0, language = ? WHERE id = ?")
                        ->execute([$f['admin_name'], $f['admin_email'] ?: null, $hash, i18n_lang(), $existingId]);
                    $adminId = (int)$existingId;
                } else {
                    $pdo->prepare("INSERT INTO {$p}users (username, name, email, password_hash, role, language, created_at) VALUES (?, ?, ?, ?, 'admin', ?, ?)")
                        ->execute([$f['admin_username'], $f['admin_name'], $f['admin_email'] ?: null, $hash, i18n_lang(), gmdate('Y-m-d H:i:s')]);
                    $adminId = (int)$pdo->lastInsertId();
                }
                // Tests and banks from a version without accounts belong to this administrator.
                $pdo->prepare("UPDATE {$p}tests SET owner_id = ? WHERE owner_id IS NULL")->execute([$adminId]);
                $pdo->prepare("UPDATE {$p}item_banks SET owner_id = ? WHERE owner_id IS NULL")->execute([$adminId]);

                $config = [
                    'db_driver' => 'mysql',
                    'db_host' => $f['db_host'],
                    'db_port' => (int)$f['db_port'],
                    'db_name' => $f['db_name'],
                    'db_user' => $f['db_user'],
                    'db_pass' => $f['db_pass'],
                    'db_prefix' => $p,
                    'base_path' => $BASE_PATH,
                    'pretty_urls' => $f['pretty_urls'],
                    'force_https' => $f['force_https'],
                    'timezone' => $f['timezone'],
                    'abandon_after_minutes' => 120,
                    'admin_idle_minutes' => 240,
                    'debug' => false,
                ];
                $php = "<?php\n// Generated by install.php on " . gmdate('Y-m-d H:i') . " UTC. Keep this file private.\n"
                    . "// Accounts are stored in the database (table {$p}users).\n"
                    . "// abandon_after_minutes: in-progress sessions idle this long are shown as 'abandoned'.\n"
                    . 'return ' . var_export($config, true) . ";\n";
                $written = @file_put_contents($CONFIG_FILE, $php, LOCK_EX) !== false;
                $installed = ['written' => $written, 'config' => $php];
            }
        } catch (PDOException $ex) {
            $msg = $ex->getMessage();
            $hint = inst_hint($msg);
            if ($action === 'test') {
                $testResult = ['ok' => false, 'lines' => array_filter([t('The connection failed: %s', $msg), $hint])];
            } else {
                $errors[] = t('Database error: %s', $msg) . ($hint ? ' ' . $hint : '');
            }
        }
    }
}

// ─── Success page ─────────────────────────────────────────────────────
if ($installed) {
    inst_page_start(t('Installation complete'));
    if ($installed['written']) {
        echo '<div class="alert alert-success">' . tm('The tables were created and <code>lib/config.php</code> was saved. Log in with the user name <strong>%s</strong>.', $f['admin_username']) . '</div>';
        echo '<div class="alert alert-warning">' . tm('<strong>Delete install.php now.</strong> It is no longer needed.') . '</div>';
        echo '<form method="post">' . $langField . '<input type="hidden" name="action" value="delete_installer">'
            . '<button class="btn btn-danger" type="submit">' . te('Delete install.php') . '</button></form>';
        echo '<p class="mt"><a class="btn btn-primary" href="admin/login.php">' . te('Go to the login page') . '</a></p>';
    } else {
        echo '<div class="alert alert-warning">' . tm('The tables were created, but <code>lib/config.php</code> could not be written because the <code>lib</code> folder is not writable. Create the file <code>lib/config.php</code> with the content below (by FTP or in the file manager), then reload this page.') . '</div>';
        echo '<textarea class="code" rows="22" readonly>' . e($installed['config']) . '</textarea>';
        echo '<p class="muted">' . te('This text contains your database password. Do not send it by e-mail.') . '</p>';
    }
    inst_page_end();
    exit;
}

// ─── Form ─────────────────────────────────────────────────────────────
$checks = [
    [t('PHP 8.1 or newer'), version_compare(PHP_VERSION, '8.1.0', '>='), 'PHP ' . PHP_VERSION, true],
    [t('PDO MySQL extension (pdo_mysql)'), extension_loaded('pdo_mysql'), '', true],
    [t('The folder lib/ is writable'), is_writable(__DIR__ . '/lib'), t('If not, you will copy the config file by hand.'), false],
    [t('mbstring or iconv'), extension_loaded('mbstring') || function_exists('iconv'), t('Needed only for CSV files that are not saved as UTF-8.'), false],
    ['HTTPS', inst_is_https(), t('Strongly recommended: open this page with https:// before installing.'), false],
];
$blocked = false;
foreach ($checks as $c) {
    if ($c[3] && !$c[1]) {
        $blocked = true;
    }
}

inst_page_start(t('Install'));
?>
<p class="lead"><?= tm('Fill in the database details from your hosting provider. Use <em>Test connection</em> first; nothing is saved until you click <em>Install</em>.') ?></p>

<h2><?= te('Requirements') ?></h2>
<ul class="checks">
<?php foreach ($checks as [$label, $ok, $note, $required]): ?>
  <li class="<?= $ok ? 'ok' : ($required ? 'bad' : 'warn') ?>">
    <span class="mark"><?= $ok ? '✓' : ($required ? '✗' : '!') ?></span>
    <?= e($label) ?><?php if ($note): ?> <span class="muted small"><?= e($note) ?></span><?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>

<?php if ($blocked): ?>
  <div class="alert alert-error"><?= te('A required component is missing. Ask your provider to enable it (often a PHP version setting in the control panel).') ?></div>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-error"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if ($testResult): ?>
  <div class="alert <?= $testResult['ok'] ? 'alert-success' : 'alert-error' ?>">
    <?= implode('<br>', array_map('e', $testResult['lines'])) ?>
  </div>
<?php endif; ?>

<form method="post" class="form panel" autocomplete="off">
  <?= $langField ?>
  <fieldset class="first">
    <legend><?= te('Database (MySQL or MariaDB)') ?></legend>
    <div class="row2">
      <label><?= te('Host') ?>
        <input name="db_host" value="<?= e($f['db_host']) ?>" required>
        <small><?= tm('Often <code>localhost</code>; some providers use a separate name.') ?></small>
      </label>
      <label><?= te('Port') ?>
        <input name="db_port" value="<?= e($f['db_port']) ?>" inputmode="numeric" required>
      </label>
    </div>
    <label><?= te('Database name') ?>
      <input name="db_name" value="<?= e($f['db_name']) ?>" required>
    </label>
    <div class="row2">
      <label><?= te('Database user') ?>
        <input name="db_user" value="<?= e($f['db_user']) ?>" required autocomplete="off">
      </label>
      <label><?= te('Database password') ?>
        <input type="password" name="db_pass" value="<?= e($f['db_pass']) ?>" autocomplete="new-password">
      </label>
    </div>
    <label><?= te('Table prefix') ?>
      <input name="db_prefix" value="<?= e($f['db_prefix']) ?>">
      <small><?= te('Lets this application share a database with other software.') ?></small>
    </label>
    <button class="btn" type="submit" name="action" value="test" formnovalidate><?= te('Test connection') ?></button>
  </fieldset>

  <fieldset>
    <legend><?= te('Administrator account') ?></legend>
    <div class="row2">
      <label><?= te('Name') ?>
        <input name="admin_name" value="<?= e($f['admin_name']) ?>" maxlength="255">
      </label>
      <label><?= te('User name') ?>
        <input name="admin_username" value="<?= e($f['admin_username']) ?>" maxlength="64" autocomplete="off">
      </label>
    </div>
    <label><?= te('E-mail (optional)') ?>
      <input type="email" name="admin_email" value="<?= e($f['admin_email']) ?>">
    </label>
    <div class="row2">
      <label><?= te('Password') ?>
        <input type="password" name="admin_password" minlength="10" autocomplete="new-password">
        <small><?= te('At least 10 characters.') ?></small>
      </label>
      <label><?= te('Repeat password') ?>
        <input type="password" name="admin_password2" minlength="10" autocomplete="new-password">
      </label>
    </div>
    <p class="muted small"><?= te('The administrator can create further accounts for project leaders after logging in.') ?></p>
  </fieldset>

  <fieldset>
    <legend><?= te('Settings') ?></legend>
    <label><?= te('Time zone for dates in the admin area') ?>
      <input name="timezone" value="<?= e($f['timezone']) ?>">
    </label>
    <label class="check"><input type="checkbox" name="force_https" value="1"<?= $f['force_https'] ? ' checked' : '' ?>>
      <?= te('Always use HTTPS (redirect http:// to https://)') ?></label>
    <label class="check"><input type="checkbox" name="pretty_urls" value="1"<?= $f['pretty_urls'] ? ' checked' : '' ?>>
      <?= tm('Short test links such as <code>%s/t/my-test</code> (needs Apache mod_rewrite; untick if those links give an error 404)', $BASE_PATH) ?></label>
    <p class="muted small"><?= tm('Installing into: <code>%s</code>', ($_SERVER['HTTP_HOST'] ?? '') . $BASE_PATH . '/') ?></p>
  </fieldset>

  <div class="actions"><button class="btn btn-primary" type="submit" name="action" value="install"<?= $blocked ? ' disabled' : '' ?>><?= te('Install') ?></button></div>
</form>
<?php
inst_page_end();

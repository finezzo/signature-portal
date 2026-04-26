<?php
declare(strict_types=1);

/**
 * First-run web installer.
 *
 * Verifies the runtime, asks for DB credentials and an admin login, generates
 * config/config.php with a fresh APP_KEY, runs migrations, and seeds the
 * first user.
 *
 * DELETE THIS FILE AFTER INSTALL — it is reachable without authentication.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Crypto\Encryption;
use App\Db\Database;
use App\Db\MigrationRunner;

$rootDir       = dirname(__DIR__);
$configDir     = $rootDir . '/config';
$configPath    = $configDir . '/config.php';
$migrationsDir = $rootDir . '/migrations';

if (is_file($configPath)) {
    $error = 'Installation already completed. Delete config/config.php to re-run, or delete this file (public/install.php).';
    render_layout('Already installed', '<div class="alert alert-error">' . htmlspecialchars($error) . '</div>');
    exit;
}

$requiredExtensions = ['pdo', 'pdo_mysql', 'curl', 'mbstring', 'openssl', 'sodium', 'json', 'dom'];
$phpOk    = version_compare(PHP_VERSION, '8.1.0', '>=');
$extState = [];
foreach ($requiredExtensions as $ext) {
    $extState[$ext] = extension_loaded($ext);
}
$envOk = $phpOk && !in_array(false, $extState, true);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST' && $envOk) {
    $form = [
        'db_host'        => trim((string)($_POST['db_host'] ?? '')),
        'db_port'        => (int)($_POST['db_port'] ?? 3306),
        'db_name'        => trim((string)($_POST['db_name'] ?? '')),
        'db_user'        => trim((string)($_POST['db_user'] ?? '')),
        'db_pass'        => (string)($_POST['db_pass'] ?? ''),
        'admin_email'    => trim((string)($_POST['admin_email'] ?? '')),
        'admin_password' => (string)($_POST['admin_password'] ?? ''),
        'admin_name'     => trim((string)($_POST['admin_name'] ?? '')),
        'base_url'       => rtrim(trim((string)($_POST['base_url'] ?? '')), '/'),
    ];

    $errors = [];
    if ($form['db_host'] === '')        $errors[] = 'Database host is required.';
    if ($form['db_name'] === '')        $errors[] = 'Database name is required.';
    if ($form['db_user'] === '')        $errors[] = 'Database user is required.';
    if (!filter_var($form['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Admin email is not valid.';
    }
    if (strlen($form['admin_password']) < 10) {
        $errors[] = 'Admin password must be at least 10 characters.';
    }
    if ($form['base_url'] === '' || !filter_var($form['base_url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Base URL is required and must include the scheme (e.g. https://...).';
    }

    if ($errors === []) {
        try {
            $pdo = Database::connect(
                $form['db_host'], $form['db_port'], $form['db_name'],
                $form['db_user'], $form['db_pass']
            );

            (new MigrationRunner($pdo, $migrationsDir))->run();

            $insert = $pdo->prepare(
                'INSERT INTO users (tenant_id, email, password_hash, role, name)
                 VALUES (NULL, :email, :hash, :role, :name)'
            );
            $insert->execute([
                ':email' => mb_strtolower($form['admin_email']),
                ':hash'  => password_hash($form['admin_password'], PASSWORD_BCRYPT),
                ':role'  => 'superadmin',
                ':name'  => $form['admin_name'] !== '' ? $form['admin_name'] : null,
            ]);

            if (!is_dir($configDir)) {
                @mkdir($configDir, 0775, true);
            }
            $appKey = Encryption::generateAppKey();
            file_put_contents($configPath, render_config_file($form, $appKey), LOCK_EX);
            @chmod($configPath, 0640);

            render_layout('Installation complete', render_success($form));
            exit;
        } catch (\Throwable $e) {
            $errors[] = 'Setup failed: ' . $e->getMessage();
        }
    }

    render_layout('SignaturePortal — Install', render_form($form, $extState, $phpOk, $errors));
    exit;
}

render_layout('SignaturePortal — Install', render_form([
    'db_host'     => 'mysql',
    'db_port'     => 3306,
    'db_name'     => 'signatureportal',
    'db_user'     => 'sigportal',
    'db_pass'     => 'sigportal',
    'admin_email' => '',
    'admin_password' => '',
    'admin_name'  => '',
    'base_url'    => detected_base_url(),
], $extState, $phpOk, []));

// ----------------------------------------------------------------------------

function render_layout(string $title, string $body): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . htmlspecialchars($title) . '</title>'
       . '<link rel="stylesheet" href="/assets/css/portal.css"></head>'
       . '<body><main class="main"><div class="container">'
       . '<div class="card" style="max-width:720px;margin:0 auto;">'
       . '<h1>' . htmlspecialchars($title) . '</h1>'
       . $body
       . '</div></div></main></body></html>';
}

/** @param array<string,mixed> $form @param array<string,bool> $extState @param list<string> $errors */
function render_form(array $form, array $extState, bool $phpOk, array $errors): string
{
    $html = '<p class="muted">This wizard creates <code>config/config.php</code>, runs the database migrations, and creates your first administrator account.</p>';

    $html .= '<h2>Environment</h2><div class="install-step">';
    $html .= '<div class="install-check ' . ($phpOk ? 'ok' : 'bad') . '">'
          .  '&nbsp;PHP ' . htmlspecialchars(PHP_VERSION) . ' (need 8.1+)</div>';
    foreach ($extState as $ext => $ok) {
        $html .= '<div class="install-check ' . ($ok ? 'ok' : 'bad') . '">&nbsp;ext-' . htmlspecialchars($ext) . '</div>';
    }
    $html .= '</div>';

    if (!$phpOk || in_array(false, $extState, true)) {
        $html .= '<div class="alert alert-error">Your PHP environment does not meet the requirements above. Fix these before continuing.</div>';
        return $html;
    }

    if ($errors !== []) {
        $html .= '<div class="alert alert-error"><ul style="margin:0;padding-left:18px;">';
        foreach ($errors as $e) {
            $html .= '<li>' . htmlspecialchars($e) . '</li>';
        }
        $html .= '</ul></div>';
    }

    $val = static fn(string $k) => htmlspecialchars((string)($form[$k] ?? ''));
    $html .= '<form method="post" action="/install.php">';

    $html .= '<h2>Database</h2>';
    $html .= row('Host',     'db_host',  $val('db_host'));
    $html .= row('Port',     'db_port',  $val('db_port'),  'number');
    $html .= row('Database', 'db_name',  $val('db_name'));
    $html .= row('User',     'db_user',  $val('db_user'));
    $html .= row('Password', 'db_pass',  $val('db_pass'),  'password');

    $html .= '<h2>Application</h2>';
    $html .= row('Public base URL', 'base_url', $val('base_url'), 'text', 'e.g. https://signatures.example.com');

    $html .= '<h2>Administrator</h2>';
    $html .= row('Email',    'admin_email',    $val('admin_email'),    'email');
    $html .= row('Display name (optional)', 'admin_name', $val('admin_name'));
    $html .= row('Password (min 10 chars)', 'admin_password', '', 'password');

    $html .= '<button type="submit" class="btn btn-primary">Install</button>';
    $html .= '</form>';
    return $html;
}

function row(string $label, string $name, string $value, string $type = 'text', string $hint = ''): string
{
    $h = '<label for="' . htmlspecialchars($name) . '">' . htmlspecialchars($label) . '</label>'
       . '<input id="' . htmlspecialchars($name) . '" name="' . htmlspecialchars($name) . '" type="' . $type . '" value="' . htmlspecialchars($value) . '"'
       . ($type !== 'password' ? '' : '')
       . '>';
    if ($hint !== '') {
        $h .= '<div class="muted" style="font-size:13px;margin-top:4px;">' . htmlspecialchars($hint) . '</div>';
    }
    return $h;
}

/** @param array<string,mixed> $form */
function render_config_file(array $form, string $appKey): string
{
    $exp = static fn(string|int $v) => var_export($v, true);
    return <<<PHP
<?php
declare(strict_types=1);

return [
    'db' => [
        'host' => {$exp((string) $form['db_host'])},
        'port' => {$exp((int)    $form['db_port'])},
        'name' => {$exp((string) $form['db_name'])},
        'user' => {$exp((string) $form['db_user'])},
        'pass' => {$exp((string) $form['db_pass'])},
    ],

    'app_key' => {$exp($appKey)},

    'base_url' => {$exp((string) $form['base_url'])},

    'auth' => [
        'local' => ['enabled' => true],
        'entra' => ['enabled' => false],
    ],

    'session' => [
        'name'     => 'sigportal_sid',
        'lifetime' => 120,
    ],
];

PHP;
}

/** @param array<string,mixed> $form */
function render_success(array $form): string
{
    return '<div class="alert" style="background:#e6f4ea;color:#1a7f37;">'
         . 'Installation complete.</div>'
         . '<p>You can now <a href="/portal/login">sign in</a> as <code>'
         . htmlspecialchars((string) $form['admin_email']) . '</code>.</p>'
         . '<div class="alert alert-error"><strong>Delete <code>public/install.php</code> now</strong> — it is reachable without authentication and can rewrite your config.</div>';
}

function detected_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

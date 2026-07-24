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
$tokenPath     = $configDir . '/.install_token';

if (is_file($configPath)) {
    $error = 'Installation already completed. Delete config/config.php to re-run, or delete this file (public/install.php).';
    render_layout('Already installed', '<div class="alert alert-error">' . htmlspecialchars($error) . '</div>');
    exit;
}

// Install-token gate. Until config.php exists this page can create an admin and
// write the config, so it must not be usable by a random visitor who finds a
// freshly deployed, not-yet-installed instance before the operator does. We
// require a secret that only someone with filesystem access to the server can
// read: it is written to config/.install_token (OUTSIDE the public/ docroot,
// so it is never web-readable) and must be pasted back into the form. This
// doubles as CSRF protection for the install POST. Returns null only if the
// token cannot be persisted (config/ not writable) — in which case the install
// would fail at config-write time anyway, so we degrade rather than hard-block.
$installToken = ensure_install_token($configDir, $tokenPath);

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
    if ($installToken !== null
        && !hash_equals($installToken, (string) ($_POST['install_token'] ?? ''))) {
        $errors[] = 'Install token is missing or incorrect. Open config/.install_token '
                  . 'on the server (via SFTP or your hosting file manager) and paste its contents.';
    }
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

            if (!is_dir($configDir) && !@mkdir($configDir, 0775, true) && !is_dir($configDir)) {
                throw new \RuntimeException('Could not create the config/ directory. Check filesystem permissions.');
            }

            // Create the admin and write config.php atomically from the operator's
            // point of view: the admin INSERT runs inside a transaction that is
            // committed ONLY after config.php is successfully written. If the
            // config write fails (e.g. permissions), we roll back so no orphaned
            // admin account is left behind and the installer can be retried
            // cleanly instead of allowing a second admin to be seeded.
            $pdo->beginTransaction();
            try {
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

                $appKey  = Encryption::generateAppKey();
                $written = @file_put_contents($configPath, render_config_file($form, $appKey), LOCK_EX);
                if ($written === false) {
                    throw new \RuntimeException(
                        'Could not write config/config.php. Check that the config/ directory is writable.'
                    );
                }
                @chmod($configPath, 0640);

                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                @unlink($configPath); // remove any partially written config
                throw $e;
            }

            // Installed successfully — retire the one-time install token.
            if (is_file($tokenPath)) {
                @unlink($tokenPath);
            }

            render_layout('Installation complete', render_success($form));
            exit;
        } catch (\Throwable $e) {
            $errors[] = 'Setup failed: ' . $e->getMessage();
        }
    }

    render_layout('SignaturePortal — Install', render_form($form, $extState, $phpOk, $errors, $installToken));
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
], $extState, $phpOk, [], $installToken));

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
function render_form(array $form, array $extState, bool $phpOk, array $errors, ?string $installToken = null): string
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

    if ($installToken !== null) {
        $html .= '<h2>Install token</h2>';
        $html .= '<div class="alert" style="background:#fff8e1;color:#8a6d00;">'
              .  'For your protection, a one-time <strong>install token</strong> was written to '
              .  '<code>config/.install_token</code> on the server (outside the public web root). '
              .  'Open that file via SFTP or your hosting file manager and paste its contents below. '
              .  'This proves you control the server and blocks anyone else from claiming this installation.'
              .  '</div>';
        $html .= row('Install token', 'install_token', '', 'text', 'contents of config/.install_token');
    }

    $html .= '<h2>Database</h2>';
    $html .= row('Host',     'db_host',  $val('db_host'));
    $html .= row('Port',     'db_port',  $val('db_port'),  'number');
    $html .= row('Database', 'db_name',  $val('db_name'));
    $html .= row('User',     'db_user',  $val('db_user'));
    $html .= row('Password', 'db_pass',  '',  'password'); // never reflect the DB password back into HTML

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

    // When true, unhandled exceptions render with full stack traces in the
    // browser. NEVER set true in production.
    'debug' => false,

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

/**
 * Reads the existing one-time install token, or generates and persists a new
 * one under config/ (outside the web root). Returns null if the token cannot
 * be written — the caller then proceeds without the gate rather than locking
 * out a legitimate operator on a host where config/ is not yet writable.
 */
function ensure_install_token(string $configDir, string $tokenPath): ?string
{
    if (is_file($tokenPath)) {
        $existing = trim((string) @file_get_contents($tokenPath));
        if ($existing !== '') {
            return $existing;
        }
    }

    if (!is_dir($configDir) && !@mkdir($configDir, 0775, true) && !is_dir($configDir)) {
        return null;
    }

    $token = bin2hex(random_bytes(32));
    if (@file_put_contents($tokenPath, $token . "\n", LOCK_EX) === false) {
        return null;
    }
    @chmod($tokenPath, 0600);
    return $token;
}

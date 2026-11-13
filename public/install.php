<?php
/**
 * Meet - web installer for a NEW server (cPanel or VPS). The domain's document root points at public/.
 *
 * 1. Upload and extract the release zip into the Meet folder (vendor/ and public/build/ are inside the zip).
 * 2. Open https://<domain>/install.php . The first visit writes a one-time code to storage/app/install-code.txt -
 *    read it in cPanel File Manager (this proves you control the server) and type it in the form.
 * 3. The installer checks PHP and folders, tests the database, writes .env (from .env.example), runs the migrations and
 *    the role seeder, creates YOUR Super Admin, links public/storage and locks itself (storage/installed).
 *
 * It never runs on an installed system. Existing servers are upgraded with deploy/update.php, never with this file.
 */

declare(strict_types=1);

define('ROOT', dirname(__DIR__));
session_start();

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function page(string $title, string $body): void
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . h($title) . ' - Meet installer</title><style>'
        . 'body{margin:0;background:#f5f6fa;color:#14161f;font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}main{max-width:820px;margin:0 auto;padding:20px 16px}'
        . '.card{background:#fff;border:1px solid #e3e5ec;border-radius:14px;padding:18px;margin:14px 0}h1{font-size:1.5rem;margin:6px 0}h2{font-size:1.1rem;margin:0 0 10px}'
        . 'table{width:100%;border-collapse:collapse}td{padding:6px 4px;border-top:1px solid #eef0f5}.ok{color:#15803d;font-weight:600}.bad{color:#b91c1c;font-weight:600}'
        . '.grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr))}label{display:block;font-weight:600;font-size:14px}'
        . 'input{display:block;width:100%;box-sizing:border-box;margin-top:4px;padding:9px 10px;border:1px solid #cfd3dd;border-radius:8px;font:inherit}.help{font-weight:400;color:#5b6070;font-size:13px}'
        . 'button{background:#4f46e5;color:#fff;border:0;border-radius:10px;padding:11px 18px;font-weight:600;font-size:15px;cursor:pointer}button:disabled{opacity:.5}'
        . '.alert{border-radius:10px;padding:12px 14px;margin:12px 0}.alert.bad{background:#fef2f2;color:#991b1b}.alert.ok{background:#f0fdf4;color:#166534}.alert.warn{background:#fffbeb;color:#92400e}'
        . 'pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f1f3f8;border-radius:8px;padding:10px;font-size:13px}</style></head><body><main>'
        . '<div class="help">Meet installer</div><h1>' . h($title) . '</h1>' . $body . '</main></body></html>';
    exit;
}

// a server set up before this installer existed (a .env with an APP_KEY): treat it as installed and lock the installer for good
if (!is_file(ROOT . '/storage/installed') && is_file(ROOT . '/.env') && preg_match('/^APP_KEY=\S+/m', (string) file_get_contents(ROOT . '/.env'))) {
    @file_put_contents(ROOT . '/storage/installed', 'Already set up before install.php existed; locked ' . date('c') . "\n");
}
if (is_file(ROOT . '/storage/installed')) {
    http_response_code(403);
    page('Already installed', '<div class="alert warn">This server is already installed. Updates go through deploy/update.php. You may delete install.php; it stays locked either way.</div>');
}

// ---------- one-time install code ----------
$codeFile = ROOT . '/storage/app/install-code.txt';
if (!is_file($codeFile)) {
    @mkdir(dirname($codeFile), 0750, true);
    if (@file_put_contents($codeFile, bin2hex(random_bytes(6)) . "\n") === false) {
        page('Folder not writable', '<div class="alert bad">Cannot write storage/app. Set folder permissions (755 or 775) for storage/ and bootstrap/cache/ and reload.</div>');
    }
    @chmod($codeFile, 0600);
}
$installCode = trim((string) file_get_contents($codeFile));

// ---------- requirements ----------
$checks = [];
$checks[] = ['PHP 8.4 or newer (found ' . PHP_VERSION . ') - cPanel > MultiPHP Manager', version_compare(PHP_VERSION, '8.4.0', '>=')];
foreach (['ctype', 'curl', 'dom', 'fileinfo', 'iconv', 'json', 'mbstring', 'openssl', 'pdo_mysql', 'simplexml', 'sodium', 'tokenizer', 'zip'] as $ext) {
    $checks[] = ['PHP extension ' . $ext, extension_loaded($ext)];
}
foreach (['storage', 'storage/app', 'storage/app/public', 'storage/framework', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $dir) {
    @mkdir(ROOT . '/' . $dir, 0775, true);
    $checks[] = ['Writable: ' . $dir, is_writable(ROOT . '/' . $dir)];
}
$checks[] = ['Application files (vendor/autoload.php)', is_file(ROOT . '/vendor/autoload.php')];
$checks[] = ['Built screens (public/build/manifest.json)', is_file(ROOT . '/public/build/manifest.json')];
$checks[] = ['Settings template (.env.example)', is_file(ROOT . '/.env.example')];
$checks[] = ['Meet folder writable (to create .env)', is_writable(ROOT)];
$allOk = !in_array(false, array_column($checks, 1), true);

$errors = [];
$old = $_POST + ['app_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
    'db_host' => 'localhost', 'db_port' => '3306', 'admin_name' => 'Super Admin', 'mail_port' => '465'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['install_csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'The form expired. Please try again.';
    }
    if (!hash_equals($installCode, trim((string) ($_POST['install_code'] ?? '')))) {
        $errors[] = 'Wrong install code. Open storage/app/install-code.txt in your file manager.';
        sleep(2);
    }
    foreach (['app_url', 'db_host', 'db_port', 'db_name', 'db_user', 'admin_name', 'admin_email', 'admin_password'] as $k) {
        if (trim((string) ($_POST[$k] ?? '')) === '') {
            $errors[] = 'Please fill in ' . str_replace('_', ' ', $k) . '.';
        }
    }
    if (!filter_var($_POST['admin_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Super Admin e-mail is not valid.';
    }
    if (strlen((string) ($_POST['admin_password'] ?? '')) < 10) {
        $errors[] = 'Super Admin password must have at least 10 characters.';
    }
    if (!$allOk) {
        $errors[] = 'Fix the red requirement checks first.';
    }

    if (!$errors) {
        try {
            $pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $_POST['db_host'], (int) $_POST['db_port']), $_POST['db_user'], (string) ($_POST['db_pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db = preg_replace('/[^A-Za-z0-9_]/', '', (string) $_POST['db_name']);
            try {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (Throwable $e) {
                // cPanel users usually cannot create databases - it must exist already
            }
            $pdo->exec("USE `$db`");
            $tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
            if ($tables > 0 && empty($_POST['allow_existing'])) {
                $errors[] = "Database `$db` already has $tables tables. Use an empty database (or tick the box if you really want to install into it).";
            }
        } catch (Throwable $e) {
            $errors[] = 'Database connection failed: ' . $e->getMessage() . ' (cPanel: create the database and user in MySQL Databases, add the user with ALL PRIVILEGES; names include your account prefix).';
        }
    }

    if (!$errors) {
        // ---------- .env from .env.example ----------
        $q = static function (string $v): string {
            return preg_match('/^[A-Za-z0-9_.:\/@,+-]*$/', $v) ? $v : '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $v) . '"';
        };
        $url = rtrim((string) $_POST['app_url'], '/');
        $host = (string) parse_url($url, PHP_URL_HOST);
        $set = [
            'APP_NAME' => '"Meet"', 'APP_ENV' => 'production', 'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)), 'APP_DEBUG' => 'false', 'APP_URL' => $q($url),
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $q((string) $_POST['db_host']), 'DB_PORT' => (string) (int) $_POST['db_port'], 'DB_DATABASE' => $q((string) $_POST['db_name']),
            'DB_USERNAME' => $q((string) $_POST['db_user']), 'DB_PASSWORD' => $q((string) ($_POST['db_pass'] ?? '')),
            'SESSION_SECURE_COOKIE' => str_starts_with($url, 'https') ? 'true' : 'false', 'SESSION_DOMAIN' => $q($host),
            'MAIL_MAILER' => trim((string) ($_POST['mail_host'] ?? '')) !== '' ? 'smtp' : 'log', 'MAIL_HOST' => $q((string) ($_POST['mail_host'] ?? '')),
            'MAIL_PORT' => (string) (int) ($_POST['mail_port'] ?: 465), 'MAIL_USERNAME' => $q((string) ($_POST['mail_user'] ?? '')), 'MAIL_PASSWORD' => $q((string) ($_POST['mail_pass'] ?? '')),
            'MAIL_ENCRYPTION' => (int) ($_POST['mail_port'] ?: 465) === 587 ? 'tls' : 'ssl', 'MAIL_FROM_ADDRESS' => $q((string) (($_POST['mail_from'] ?? '') ?: $_POST['admin_email'])),
            'MAIL_FROM_NAME' => '"Meet"',
        ];
        $lines = preg_split('/\R/', (string) file_get_contents(ROOT . '/.env.example'));
        $seen = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $m) && array_key_exists($m[1], $set)) {
                $lines[$i] = $m[1] . '=' . $set[$m[1]];
                $seen[$m[1]] = true;
            }
        }
        foreach ($set as $k => $v) {
            if (!isset($seen[$k])) {
                $lines[] = $k . '=' . $v;
            }
        }
        array_unshift($lines, '# Meet - written by install.php on ' . date('Y-m-d H:i'));
        if (is_file(ROOT . '/.env')) {
            @copy(ROOT . '/.env', ROOT . '/.env.before-install-' . date('YmdHis'));
        }
        file_put_contents(ROOT . '/.env', implode("\n", $lines) . "\n", LOCK_EX);
        @chmod(ROOT . '/.env', 0640);

        // ---------- migrate + seed + Super Admin inside Laravel ----------
        foreach (['config.php', 'routes-v7.php', 'services.php', 'packages.php', 'modules.php'] as $f) {
            @unlink(ROOT . '/bootstrap/cache/' . $f);
        }
        $log = [];
        try {
            @set_time_limit(600);
            require ROOT . '/vendor/autoload.php';
            $app = require ROOT . '/bootstrap/app.php';
            $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
            $kernel->bootstrap();
            foreach ([['migrate', ['--force' => true]], ['db:seed', ['--force' => true]]] as [$cmd, $args]) {
                $code = $kernel->call($cmd, $args);
                $log[] = $cmd . ': ' . ($code === 0 ? 'ok' : 'exit ' . $code);
                if ($code !== 0) {
                    throw new RuntimeException($cmd . ' failed: ' . $kernel->output());
                }
            }
            $email = strtolower((string) $_POST['admin_email']);
            $user = Modules\Authentication\Models\User::where('email', $email)->first() ?? new Modules\Authentication\Models\User();
            $isNew = !$user->exists;
            $user->forceFill(['full_name' => mb_substr((string) $_POST['admin_name'], 0, 150), 'email' => $email, 'password' => Illuminate\Support\Facades\Hash::make((string) $_POST['admin_password']),
                'status' => 'active', 'email_verified_at' => now()])->save();
            if ($isNew) {
                (new Modules\Authentication\Listeners\CreateDefaultUserRecords())->handle(new Modules\Authentication\Events\UserRegistered($user, '000000')); // profile, privacy, settings
            }
            Modules\Admin\Models\Admin::updateOrCreate(['user_id' => $user->id], ['admin_level' => 'super', 'is_super_admin' => true]); // opens /admin
            $role = Modules\Admin\Models\Role::where('name', 'Super Admin')->first();
            if ($role && !$user->roles()->where('roles.id', $role->id)->exists()) {
                $user->roles()->attach($role->id, ['assigned_at' => now()]);
            }
            $log[] = 'Super Admin: ' . $email;
            // public/storage -> ../storage/app/public (relative, so a moved folder keeps working): profile photos and uploads
            if (!file_exists(ROOT . '/public/storage')) {
                $log[] = 'storage link: ' . (@symlink('../storage/app/public', ROOT . '/public/storage') ? 'ok' : 'not made (make it in cPanel or run "php artisan storage:link")');
            }
        } catch (Throwable $e) {
            page('Installation failed', '<div class="alert bad">' . h($e->getMessage()) . '</div><pre>' . h(implode("\n", $log)) . '</pre><p>.env was written. Fix the problem and reload this page (the code stays the same).</p>');
        }

        file_put_contents(ROOT . '/storage/installed', 'Installed ' . date('c') . "\n");
        @unlink($codeFile);
        $cron = '/opt/cpanel/ea-php84/root/usr/bin/php ' . ROOT . '/artisan schedule:run >> /dev/null 2>&1';
        page('Installed', '<div class="alert ok">Meet is installed.</div><div class="card"><h2>Next steps</h2><ol>'
            . '<li>Sign in at <a href="' . h($url) . '/login">' . h($url) . '/login</a> as ' . h($email) . '.</li>'
            . '<li>cPanel &gt; Cron Jobs: add (every minute) <pre>' . h($cron) . '</pre> (PHP 8.4; if that path does not exist, use the PHP 8.4 path cPanel shows in MultiPHP).</li>'
            . '<li>Mail addresses for your people (optional): Meet &gt; Mail settings shows the one cPanel step (the mail pipe).</li>'
            . '<li>Calls on strict office / mobile networks need a TURN server (CALLS_TURN_URL in .env).</li></ol></div>'
            . '<pre>' . h(implode("\n", $log)) . '</pre>');
    }
}

$_SESSION['install_csrf'] = $_SESSION['install_csrf'] ?? bin2hex(random_bytes(16));
$rows = '';
foreach ($checks as [$label, $ok]) {
    $rows .= '<tr><td>' . h($label) . '</td><td class="' . ($ok ? 'ok' : 'bad') . '">' . ($ok ? 'OK' : 'missing') . '</td></tr>';
}
$err = $errors ? '<div class="alert bad"><ul>' . implode('', array_map(fn ($e) => '<li>' . h($e) . '</li>', $errors)) . '</ul></div>' : '';
$f = fn ($name, $label, $type = 'text', $help = '') => '<label for="' . $name . '">' . h($label) . '<input id="' . $name . '" name="' . $name . '" type="' . $type . '" value="'
    . ($type === 'password' ? '' : h((string) ($old[$name] ?? ''))) . '">' . ($help ? '<span class="help">' . h($help) . '</span>' : '') . '</label>';

page('Install Meet', $err . '<div class="card"><h2>1. Server check</h2><table><tbody>' . $rows . '</tbody></table></div>'
    . '<form method="post" class="card"><h2>2. Settings</h2><input type="hidden" name="csrf" value="' . h($_SESSION['install_csrf']) . '"><div class="grid">'
    . $f('install_code', 'Install code', 'text', 'From storage/app/install-code.txt: proves you have access to the server files.') . $f('app_url', 'Address of Meet', 'url')
    . $f('db_host', 'Database host') . $f('db_port', 'Database port', 'number') . $f('db_name', 'Database name') . $f('db_user', 'Database user') . $f('db_pass', 'Database password', 'password')
    . $f('admin_name', 'Your name') . $f('admin_email', 'Your e-mail (Super Admin)', 'email') . $f('admin_password', 'Your password (min. 10 characters)', 'password')
    . $f('mail_host', 'Mail server (optional)', 'text', 'For example mail.yourdomain.com. Empty = mail is only written to the log.') . $f('mail_port', 'Mail port', 'number', '465 (SSL) or 587 (TLS)')
    . $f('mail_user', 'Mail user') . $f('mail_pass', 'Mail password', 'password') . $f('mail_from', 'Send mail from', 'email')
    . '</div><p><label style="font-weight:400"><input type="checkbox" name="allow_existing" value="1" style="display:inline;width:auto"> Install into a database that already has tables (not recommended)</label></p>'
    . '<button' . ($allOk ? '' : ' disabled') . '>Install</button></form>');

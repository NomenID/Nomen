<?php
/**
 * 后台与开放平台页面渲染测试。跑法：
 *
 *     php docs-internal/tests/admin-render-test.php
 *
 * 用临时 SQLite 库跑完整 schema，渲染后台应用、接入点、开发者页和开放平台四页。
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('PROJ', dirname(__DIR__, 2));
$root = PROJ;
$dbFile = sys_get_temp_dir() . '/nomen_render_test.sqlite';

foreach ([$dbFile, $dbFile . '-wal', $dbFile . '-shm'] as $file) {
    @unlink($file);
}

require $root . '/src/bootstrap.php';
require $root . '/src/I18n.php';

spl_autoload_register(function (string $class) use ($root) {
    $prefixes = [
        'Nomen\\Src\\'         => $root . '/src/',
        'Nomen\\Controllers\\' => $root . '/controllers/',
        'Nomen\\Plugin\\'      => $root . '/plugins/',
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        if (strpos($class, $prefix) === 0) {
            $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) {
                require $file;
                return;
            }
        }
    }
});

use Nomen\Src\Admin;
use Nomen\Src\Database;
use Nomen\Src\OAuthClients;
use Nomen\Src\Installer\Database as InstallDb;

$pdo = InstallDb::connect(['driver' => 'sqlite', 'path' => $dbFile], true);
InstallDb::importSchema($pdo, $root . '/database/schema.sqlite.sql');

$pdo->exec("INSERT INTO users (id, username, display_name, email, email_verified, password_hash, role, status)
            VALUES (1, 'admin', 'Admin', 'admin@example.com', 1, 'x', 'admin', 1)");
$pdo->exec("INSERT INTO user_emails (user_id, email, is_primary, verified) VALUES (1, 'admin@example.com', 1, 1)");
$pdo->exec("INSERT INTO developers (id, user_id, name, status) VALUES (1, 1, 'Test Developer', 1)");

$prop = (new ReflectionClass(Database::class))->getProperty('instance');
$prop->setAccessible(true);
$prop->setValue(null, $pdo);

$_SESSION = [
    'user_id' => 1,
    'username' => 'admin',
    'role' => 'admin',
    'user_skin' => 'nomen-2026',
];
$_SERVER['REQUEST_URI'] = '/open/dashboard';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

\Nomen\Src\Plugins::boot();

$created = OAuthClients::create([
    'name' => 'Render Test App',
    'description' => 'render test',
    'homepage' => 'https://example.com',
    'redirect_uris' => 'https://example.com/cb',
    'scopes' => ['openid', 'profile'],
    'client_type' => 'confidential',
], 1, 1);

$fail = 0;
$render = function (string $label, callable $fn, array $expects) use (&$fail) {
    ob_start();
    try {
        $fn();
    } catch (Throwable $e) {
        ob_end_clean();
        $fail++;
        echo "[FAIL] $label threw: ", get_class($e), ': ', $e->getMessage(), "\n";
        return;
    }
    $html = ob_get_clean();

    foreach ($expects as $text) {
        $ok = str_contains($html, $text);
        if (!$ok) { $fail++; }
        printf("[%s] %s contains %s\n", $ok ? 'OK ' : 'FAIL', $label, $text);
    }
    foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'] as $bad) {
        if (str_contains($html, $bad)) {
            $fail++;
            echo "[FAIL] $label output has $bad\n";
        }
    }
};

$render('admin apps list', fn() => (new Nomen\Controllers\AdminAppsController())->index(), ['全部应用', 'Render Test App']);
$render('admin app detail', fn() => (new Nomen\Controllers\AdminAppsController())->show($created['client_id']), ['接入端点', 'Render Test App']);
$render('admin mounts', fn() => (new Nomen\Controllers\AdminMountsController())->index(), ['插件挂载点', '/open', '/w']);
$render('admin developers page', fn() => Admin::render('developers'), ['Test Developer']);
$render('open landing', fn() => (new Nomen\Plugin\OpenPlatform\Controller())->index(), ['开放平台', '应用接入']);
$render('open dashboard', fn() => (new Nomen\Plugin\OpenPlatform\Controller())->dashboard(), ['我的应用', 'Render Test App']);
$render('open app show', fn() => (new Nomen\Plugin\OpenPlatform\Controller())->showApp($created['client_id']), ['接入信息', 'Render Test App']);
$render('open settings', fn() => (new Nomen\Plugin\OpenPlatform\Controller())->settings(), ['开发者设置', 'Test Developer']);
$render('app shell', function (): void {
    Nomen\Src\Response::view('app-shell', [
        'appProps' => [
            'viewer' => ['username' => 'admin'],
            'brand' => ['name' => 'Nomen', 'logo' => '', 'url' => 'http://localhost', 'copyright' => ''],
            'csrf' => 'test',
            'locales' => [],
            'page' => ['name' => 'dashboard', 'data' => []],
        ],
        'title' => '仪表盘',
    ], 'app');
}, ['app-props', 'assets/dist/js/app-']);

foreach ([$dbFile, $dbFile . '-wal', $dbFile . '-shm'] as $file) {
    @unlink($file);
}

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);

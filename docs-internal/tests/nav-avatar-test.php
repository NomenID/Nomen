<?php
/**
 * 头像展示测试（导航栏 / 移动抽屉）。跑法：
 *
 *     php docs-internal/tests/nav-avatar-test.php
 *
 * 覆盖：
 *   1. 有头像的账号：导航和移动抽屉都渲染 <img>
 *   2. 没头像的账号：还是首字母方块，不出现空 <img src="">
 *   3. resolveAvatarUrl / avatarUrlForUser 的地址拼装
 *
 * 数据库用 SQLite 内存库顶 MySQL，布局离线渲染。
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('PROJ', dirname(__DIR__, 2));
$_SERVER['REQUEST_URI'] = '/dashboard';

spl_autoload_register(function (string $class) {
    $prefixes = [
        'Nomen\\Src\\'         => PROJ . '/src/',
        'Nomen\\Controllers\\' => PROJ . '/controllers/',
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        if (strpos($class, $prefix) === 0) {
            $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) { require $file; return; }
        }
    }
});

// t() 是全局函数不会被自动加载，测试里直接引 I18n（CLI 下 locale 固定 zh-hans，翻译键=中文原文）
require PROJ . '/src/I18n.php';

use Nomen\Src\Database;

const TEST_AVATAR = '1700000000-navavatar.png';
const TEST_ICON   = '1700000001-devicon.png';

function setupDb(?string $avatar, ?string $devIcon): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, role TEXT, avatar TEXT)');
    $pdo->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY, user_id INTEGER, read_at TEXT)');
    $pdo->exec('CREATE TABLE developers (id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT, icon TEXT, status INTEGER)');

    $pdo->exec("INSERT INTO users (id, username, display_name, role, avatar)
                VALUES (4, 'yunyun', '云云', 'admin', " . ($avatar === null ? 'NULL' : "'{$avatar}'") . ')');
    $pdo->exec("INSERT INTO developers (id, user_id, name, icon, status)
                VALUES (1, 4, '虚舟实验室', " . ($devIcon === null ? 'NULL' : "'{$devIcon}'") . ', 1)');

    $prop = (new ReflectionClass(Database::class))->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);
    return $pdo;
}

function login(int $userId = 4, string $username = 'yunyun'): void
{
    $_SESSION = ['user_id' => $userId, 'username' => $username, 'role' => 'admin'];
}

function render(string $layout, array $vars): string
{
    $file = \Nomen\Src\Skins::layoutFile($layout);
    if ($file === null) {
        throw new RuntimeException('找不到布局：' . $layout);
    }
    extract($vars);
    ob_start();
    require $file;
    return ob_get_clean();
}

$fails = 0;
$check = function (string $label, $got, $want) use (&$fails) {
    $ok = $got === $want;
    if (!$ok) { $fails++; }
    printf("[%s] %-50s got=%s\n", $ok ? 'OK ' : 'FAIL', $label, var_export($got, true));
};

// =====================================================================
echo "==== 1) 地址拼装 ====\n";
// =====================================================================
setupDb(TEST_AVATAR, TEST_ICON);
$check('有头像时的 URL',
    \Nomen\Controllers\DashboardController::avatarUrlForUser(4), '/assets/avatars/' . TEST_AVATAR);
$check('不存在的账号返回 null',
    \Nomen\Controllers\DashboardController::avatarUrlForUser(999), null);
$check('相对地址补成 /assets/avatars/',
    \Nomen\Controllers\DashboardController::resolveAvatarUrl('a.png'), '/assets/avatars/a.png');
$check('外部地址原样返回',
    \Nomen\Controllers\DashboardController::resolveAvatarUrl('https://x.com/a.png'), 'https://x.com/a.png');

// =====================================================================
echo "\n==== 2) 有头像：两套布局的导航 + 抽屉 ====\n";
// =====================================================================
setupDb(TEST_AVATAR, TEST_ICON);
login();
foreach (['default'] as $tpl) {
    $html = render($tpl, ['content' => 'X', 'title' => '仪表盘', 'extraCss' => [], 'extraJs' => null]);
    $check("{$tpl} 导航有头像 img",
        substr_count($html, '<img src="/assets/avatars/' . TEST_AVATAR . '" alt="" class="nav-avatar">'), 1);
    $check("{$tpl} 抽屉有头像 img",
        substr_count($html, '<img src="/assets/avatars/' . TEST_AVATAR . '" alt="" class="mobile-menu-avatar">'), 1);
    $check("{$tpl} 用户名还在（导航 + 抽屉各一次）", substr_count($html, '>yunyun<'), 2);
    $check("{$tpl} 页脚没有多余链接", str_contains($html, 'footer-links'), false);
    $check("{$tpl} 页脚有署名", str_contains($html, 'Powered by Nomen'), true);
    $check("{$tpl} 署名链到版本页", str_contains($html, 'class="footer-powered" href="/special/version"'), true);
}

// =====================================================================
echo "\n==== 3) 没头像：退回首字母 ====\n";
// =====================================================================
setupDb(null, null);
login();
foreach (['default'] as $tpl) {
    $html = render($tpl, ['content' => 'X', 'title' => '仪表盘', 'extraCss' => [], 'extraJs' => null]);
    $check("{$tpl} 不渲染 nav-avatar", str_contains($html, 'nav-avatar'), false);
    $check("{$tpl} 抽屉退回首字母",
        substr_count($html, '<span class="mobile-menu-avatar">Y</span>'), 1);
}

// =====================================================================
echo $fails === 0 ? "\nALL PASS\n" : "\n{$fails} FAILED\n";
exit($fails === 0 ? 0 : 1);

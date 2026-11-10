<?php
/**
 * 活动日志测试。跑法：
 *
 *     php docs-internal/tests/activity-log-test.php           # 全部
 *     php docs-internal/tests/activity-log-test.php avatar    # 只跑头像上传那条链路
 *
 * 覆盖：
 *   1. record() 落库的字段形状（操作人 / 分类 / 动作 / 对象 / 变更明细）
 *   2. 敏感字段永不进变更明细
 *   3. 更换头像要带上文件名，并且真跑一遍 uploadAvatar()
 *      —— 回归点：日志原先写在 Response::json() 之后，而那个方法会 exit，
 *         AJAX 路径（设置页就是）整条不落库，日志是死代码。
 *   4. 开放平台全套写操作都有日志（源码守卫：写了库却没 ActivityLog::record 的方法判红）
 *
 * 设计要点：
 *  - SQLite 内存库顶 MySQL，反射塞进 Database::$instance。
 *  - uploadAvatar() 内部 Response::json() 会 exit，所以起子进程，用 shutdown 钩子把库里的行捞回来。
 *  - 子进程会真的往 public/assets/avatars/ 写一张图，父进程读完成功后删掉（用后即焚）。
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('PROJ', dirname(__DIR__, 2));
$CASE = $argv[1] ?? '';
$ONLY = in_array($CASE, ['avatar'], true) ? $CASE : '';

$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'activity-log-test';

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

use Nomen\Src\ActivityLog;

function makePdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE activity_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        actor_type TEXT DEFAULT "user", actor_id INTEGER, actor_username TEXT,
        action TEXT, category TEXT, target_type TEXT, target_id TEXT,
        target_name TEXT, summary TEXT, changes TEXT,
        ip_address TEXT, user_agent TEXT, device_name TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    return $pdo;
}

function usePdo(PDO $pdo): void
{
    $p = (new ReflectionClass(\Nomen\Src\Database::class))->getProperty('instance');
    $p->setAccessible(true);
    $p->setValue(null, $pdo);
}

function lastRow(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM activity_logs ORDER BY id DESC LIMIT 1')->fetch() ?: [];
}

// =====================================================================
// 子进程模式：跑一次真的 uploadAvatar()
// =====================================================================
if ($CASE === 'avatar') {
    $oldAvatar = '1700000000-oldavatar.webp';

    $pdo = makePdo();
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, avatar TEXT)');
    $pdo->exec("INSERT INTO users (id, avatar) VALUES (42, '{$oldAvatar}')");
    usePdo($pdo);

    $tmpImage = sys_get_temp_dir() . '/_activity_log_avatar.png';
    $im = imagecreatetruecolor(200, 120);
    imagefilledrectangle($im, 0, 0, 200, 120, imagecolorallocate($im, 30, 60, 120));
    imagepng($im, $tmpImage);
    imagedestroy($im);

    $csrf = 'csrf-for-test';
    $_SESSION['user_id']      = 42;
    $_SESSION['role']         = 'user';
    $_SESSION['username']     = 'yunyun';
    $_SESSION['csrf_token']   = $csrf;
    $_POST['_csrf']           = $csrf;
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_FILES['avatar'] = [
        'name' => 'me.png', 'type' => 'image/png', 'tmp_name' => $tmpImage,
        'error' => UPLOAD_ERR_OK, 'size' => filesize($tmpImage),
    ];

    // Response::json() 里 exit 之后才轮到 shutdown 钩子，正好把结果捞出来
    register_shutdown_function(function () use ($pdo) {
        echo "\n__LOG__ " . json_encode(lastRow($pdo), JSON_UNESCAPED_UNICODE) . "\n";
        echo "__USER__ " . json_encode($pdo->query('SELECT avatar FROM users WHERE id = 42')->fetch(), JSON_UNESCAPED_UNICODE) . "\n";
    });

    $c = (new ReflectionClass(\Nomen\Controllers\DashboardController::class))->newInstanceWithoutConstructor();
    $dbProp = new ReflectionProperty(\Nomen\Controllers\DashboardController::class, 'db');
    $dbProp->setAccessible(true);
    $dbProp->setValue($c, $pdo);

    $c->uploadAvatar();
    exit(0);
}

// =====================================================================
$fails = 0;
$check = function (string $label, $got, $want) use (&$fails) {
    $ok = $got === $want;
    if (!$ok) { $fails++; }
    printf("[%s] %-52s got=%s\n", $ok ? 'OK ' : 'FAIL', $label, var_export($got, true));
};

// ---------------------------------------------------------------- 1) 落库字段
if ($ONLY === '') {
    echo "==== 1) record() 落库字段 ====\n";
    $pdo = makePdo();
    usePdo($pdo);
    $_SESSION = ['user_id' => 42, 'role' => 'user', 'username' => 'yunyun'];

    ActivityLog::record([
        'action'      => 'profile.avatar',
        'category'    => ActivityLog::CAT_PROFILE,
        'target_type' => 'avatar',
        'target_name' => '1700000000-abcd1234.png',
        'changes'     => ActivityLog::diff(
            ['avatar' => '1600000000-old.webp'],
            ['avatar' => '1700000000-abcd1234.png'],
            ['avatar']
        ),
        'summary'     => '更换头像',
    ]);
    $row = lastRow($pdo);

    $check('动作', $row['action'], 'profile.avatar');
    $check('分类', $row['category'], 'profile');
    $check('对象列带文件名', $row['target_name'], '1700000000-abcd1234.png');
    $check('变更明细带旧/新文件名', $row['changes'],
        '{"avatar":{"old":"1600000000-old.webp","new":"1700000000-abcd1234.png"}}');
    $check('操作人取自会话', $row['actor_type'] . ':' . $row['actor_username'], 'user:yunyun');
    $check('IP 落库', $row['ip_address'], '127.0.0.1');

    // 管理员操作走 admin 类型
    $_SESSION['role'] = 'admin';
    ActivityLog::record(['action' => 'admin.user_disable', 'category' => ActivityLog::CAT_ADMIN, 'summary' => '禁用用户']);
    $check('管理员操作 actor_type=admin', lastRow($pdo)['actor_type'], 'admin');
    $_SESSION['role'] = 'user';

    // ------------------------------------------------------------ 2) 敏感值
    echo "\n==== 2) 敏感字段不进变更明细 ====\n";
    $check('client_secret 被丢弃',
        ActivityLog::diff(['client_secret' => 'a'], ['client_secret' => 'b'], ['client_secret']), []);
    $check('password 被丢弃',
        ActivityLog::diff(['password' => 'a'], ['password' => 'b'], ['password']), []);
    $check('正常字段照常记',
        count(ActivityLog::diff(['icon' => 'a.png'], ['icon' => 'b.png'], ['icon'])), 1);

    // ------------------------------------------------------------ 3) 动作登记
    echo "\n==== 3) 开放平台的动作与分类已登记 ====\n";
    $check('「开放平台」分类存在', ActivityLog::CATEGORIES[ActivityLog::CAT_DEV] ?? null, 'Open Platform');
    $check('分类图标存在', ActivityLog::iconFor(ActivityLog::CAT_DEV) !== '•', true);
    foreach ([
        'developer.register', 'developer.profile_update', 'developer.app_create',
        'developer.app_update', 'developer.app_reset_secret', 'developer.app_delete',
    ] as $action) {
        $check("动作有中文名 {$action}", ActivityLog::actionLabel($action) !== $action, true);
    }
}

// ---------------------------------------------------------------- 4) 头像端到端
if ($ONLY === '' || $ONLY === 'avatar') {
    echo "\n==== 4) uploadAvatar() 端到端（真写库 + 真落文件）====\n";
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg('avatar');
    exec($cmd . ' 2>&1', $lines, $exitCode);
    $raw = implode("\n", $lines);

    $log = $user = null;
    if (preg_match('/^__LOG__ (.*)$/m', $raw, $m))  { $log  = json_decode($m[1], true); }
    if (preg_match('/^__USER__ (.*)$/m', $raw, $m)) { $user = json_decode($m[1], true); }
    $response = preg_match('/\{(?:[^{}]|(?R))*\}/s', $raw, $m) ? json_decode($m[0], true) : null;

    if (!$log || !$user) {
        $fails++;
        printf("[FAIL] %-52s 子进程没交出结果\n       %s\n", '子进程输出',
            trim(preg_replace('/\s+/', ' ', mb_substr($raw, 0, 300))));
    } else {
        $newAvatar = (string)$user['avatar'];
        $changes = json_decode((string)($log['changes'] ?? ''), true) ?: [];

        $check('接口返回 success', $response['success'] ?? null, true);
        $check('库里有 profile.avatar 一行', $log['action'] ?? null, 'profile.avatar');
        $check('分类是个人资料', $log['category'] ?? null, 'profile');
        $check('对象列 = 新文件名', $log['target_name'] ?? null, $newAvatar);
        $check('变更明细 old = 旧文件名', $changes['avatar']['old'] ?? null, '1700000000-oldavatar.webp');
        $check('变更明细 new = 新文件名', $changes['avatar']['new'] ?? null, $newAvatar);
        $check('users.avatar 真的换了', $newAvatar !== '' && $newAvatar !== '1700000000-oldavatar.webp', true);

        // 收尾：子进程真写出来的图删掉
        $file = PROJ . '/public/assets/avatars/' . $newAvatar;
        $check('头像文件真的落地', is_file($file), true);
        if (is_file($file)) { unlink($file); }
        @unlink(sys_get_temp_dir() . '/_activity_log_avatar.png');

        // 日志写在成功响应之前，是这个回归点的本质（写在后面就整条丢掉）
        $dash = file_get_contents(PROJ . '/controllers/DashboardController.php');
        $body = substr($dash, strpos($dash, 'function uploadAvatar'));
        $body = substr($body, 0, strpos($body, "\n    /**"));
        $check('日志写在最后一次 Response::json 之前',
            strpos($body, "'profile.avatar'") < strrpos($body, 'Response::json'), true);
    }
}

// ---------------------------------------------------------------- 5) 开放平台插件源码守卫
if ($ONLY === '') {
    echo "\n==== 5) 开放平台插件：写了库的方法必须有日志 ====\n";
    $src = file_get_contents(PROJ . '/plugins/open-platform/src/Controller.php');

    preg_match_all('/^    public function (\w+)\(/m', $src, $m, PREG_OFFSET_CAPTURE);
    $methodCount = count($m[1]);
    $methods = [];
    for ($i = 0; $i < $methodCount; $i++) {
        $start = $m[1][$i][1];
        $end = $i + 1 < $methodCount ? $m[1][$i + 1][1] : strlen($src);
        $methods[$m[1][$i][0]] = substr($src, $start, $end - $start);
    }

    $writeMethods = [
        'registerDeveloper' => 'developer.register',
        'storeApp'          => 'developer.app_create',
        'updateApp'         => 'developer.app_update',
        'resetSecret'       => 'developer.app_reset_secret',
        'deleteApp'         => 'developer.app_delete',
        'updateProfile'     => 'developer.profile_update',
    ];

    foreach ($writeMethods as $name => $action) {
        $check("{$name}() 存在", isset($methods[$name]), true);
        if (isset($methods[$name])) {
            $check("{$name}() 有 ActivityLog::record", str_contains($methods[$name], 'ActivityLog::record'), true);
        }
        $check("{$action} 只记一处", substr_count($src, "'{$action}'"), 1);
    }

    // 密钥明文绝不能进日志
    $check('没有把 newSecret 交给日志',
        (bool)preg_match('/ActivityLog::record\((?:[^;]*newSecret)/s', $src), false);
}

echo $fails === 0 ? "\nALL PASS\n" : "\n{$fails} FAILED\n";
exit($fails === 0 ? 0 : 1);

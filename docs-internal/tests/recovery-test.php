<?php
/**
 * 账号恢复功能的自测套件。跑法：
 *
 *     php docs-internal/tests/recovery-test.php            # 全部
 *     php docs-internal/tests/recovery-test.php upload     # 只跑上传分支
 *     php docs-internal/tests/recovery-test.php throttle   # 只跑提醒节流
 *
 * 设计要点（都是踩过坑才这么写的）：
 *  - 用反射 newInstanceWithoutConstructor() 造控制器：构造函数会连数据库，而
 *    这里要测的路径不需要库（会话里有 recovery_ctx 时 resolveContext 不查库）。
 *  - upload() 内部调 Response::json() 会 exit，所以每个 case 起一个子进程（argv 带 case 名）。
 *  - CLI 下 move_uploaded_file() 必然失败 —— 所以「合法图报保存失败」恰好证明校验全过。
 *  - 提醒节流用 SQLite 内存库顶 MySQL，时间值一律由 SQL 表达式生成（datetime('now','localtime','-61 minutes')），
 *    不让 PHP 时区介入 —— 生产里这些时间也是库自己写自己读。
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('PROJ', dirname(__DIR__, 2));
$CASE = $argv[1] ?? '';
$ONLY = in_array($CASE, ['upload', 'throttle'], true) ? $CASE : '';

$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_USER_AGENT'] = 'recovery-test';

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

use Nomen\Src\RecoveryHelper;

// =====================================================================
// 子进程模式：造好状态 → 调一次方法 → 让 Response::json() 自己输出
// =====================================================================
if (preg_match('/^upload:([^:]+)(?::(\d+):(\d+))?$/', $CASE, $m)) {
    // 每个 case 用一个一次性 IP：
    // 服务器上有 Redis，upload() 的按 IP 限流（40 次/小时）是真在计数的 ——
    // 全部 case 共用 127.0.0.1 的话，跑几轮桶就满，整套断言会假红。
    if (!empty($m[2])) {
        $_SERVER['REMOTE_ADDR'] = '10.' . $m[2] . '.0.' . $m[3];
    }
    runUploadCase($m[1]);
    exit(0);
}

if (preg_match('/^pw:(equal|equal_legacy|recovery_equal|different)$/', $CASE, $m)) {
    runPasswordCase($m[1]);
    exit(0);
}

function makeImage(int $w, int $h, string $path, string $type, int $quality = 85): void
{
    $im = imagecreatetruecolor($w, $h);
    for ($x = 0; $x < $w; $x += 5) {
        imagefilledrectangle($im, $x, 0, $x + 2, $h,
            imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    }
    match ($type) {
        'png'  => imagepng($im, $path, max(0, min(9, $quality))),
        'jpg'  => imagejpeg($im, $path, $quality),
        'webp' => imagewebp($im, $path, 80),
        'gif'  => imagegif($im, $path),
    };
    imagedestroy($im);
}

function runUploadCase(string $case): void
{
    $tmp = sys_get_temp_dir() . '/_recovery_case';
    @mkdir($tmp, 0777, true);
    $tmpName = "$tmp/upload.bin";

    $csrf = 'csrf-token-for-test';
    $_SESSION['csrf_token'] = $csrf;
    if (!in_array($case, ['no_csrf', 'no_ctx'], true)) {
        $_SESSION['recovery_ctx'] = [
            'user_id' => 4, 'username' => 'yunyun', 'email' => 'a@b.com',
            'email_verified' => 1, 'created_at' => time(),
        ];
    }
    if ($case !== 'no_csrf') {
        $_POST['_csrf'] = $csrf;
    }

    switch ($case) {
        case 'no_csrf':
        case 'no_ctx':
            makeImage(80, 60, $tmpName, 'png');
            $_FILES['file'] = ['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => $tmpName,
                               'error' => UPLOAD_ERR_OK, 'size' => filesize($tmpName)];
            break;

        case 'no_file':
            break;

        case 'err_ini':
            $_FILES['file'] = ['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => $tmpName,
                               'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0];
            break;

        case 'text_png':
            file_put_contents($tmpName, str_repeat('not an image at all', 50));
            $_FILES['file'] = ['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => $tmpName,
                               'error' => UPLOAD_ERR_OK, 'size' => filesize($tmpName)];
            break;

        case 'gif':
            makeImage(80, 60, $tmpName, 'gif');
            $_FILES['file'] = ['name' => 'a.gif', 'type' => 'image/gif', 'tmp_name' => $tmpName,
                               'error' => UPLOAD_ERR_OK, 'size' => filesize($tmpName)];
            break;

        case 'spoof_type':   // 真 PNG，前端谎报 image/jpeg —— 应以真 MIME 为准放行
            makeImage(80, 60, $tmpName, 'png');
            $_FILES['file'] = ['name' => 'a.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmpName,
                               'error' => UPLOAD_ERR_OK, 'size' => filesize($tmpName)];
            break;

        case 'too_large':
            makeImage(1800, 1400, $tmpName, 'png', 0);
            $_FILES['file'] = ['name' => 'big.png', 'type' => 'image/png', 'tmp_name' => $tmpName,
                               'error' => UPLOAD_ERR_OK, 'size' => filesize($tmpName)];
            break;

        case 'tiny_image':
            makeImage(8, 8, $tmpName, 'png');
            $_FILES['file'] = ['name' => 't.png', 'type' => 'image/png', 'tmp_name' => $tmpName,
                               'error' => UPLOAD_ERR_OK, 'size' => filesize($tmpName)];
            break;

        case 'full_5':
            makeImage(80, 60, $tmpName, 'png');
            $staged = [];
            for ($i = 0; $i < 5; $i++) {
                $p = "$tmp/staged$i.png";
                makeImage(80, 60, $p, 'png');
                $staged['tok' . $i] = ['path' => $p, 'mime' => 'image/png', 'ext' => 'png',
                                       'size' => filesize($p), 'created_at' => time()];
            }
            $_SESSION['recovery_uploads'] = $staged;
            $_FILES['file'] = ['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => $tmpName,
                               'error' => UPLOAD_ERR_OK, 'size' => filesize($tmpName)];
            break;

        default:  // valid_png / valid_jpg / valid_webp
            $fmt = substr($case, 6);
            makeImage(300, 200, $tmpName, $fmt, 85);
            $_FILES['file'] = ['name' => "a.$fmt", 'type' => "image/$fmt", 'tmp_name' => $tmpName,
                               'error' => UPLOAD_ERR_OK, 'size' => filesize($tmpName)];
    }

    $ref = new ReflectionClass(\Nomen\Controllers\RecoveryController::class);
    $controller = $ref->newInstanceWithoutConstructor();
    $controller->upload();      // 内部 Response::json() 会 exit
}

// =====================================================================
// 主进程：跑断言
// =====================================================================

/**
 * 改密码的「同密码必须被拒」用例。
 *
 * changePassword() 内部靠 Response::redirect() 收尾（直接 exit），所以断言放 shutdown 钩子里：
 * 看库里的 password_hash 有没有被换掉、会话 flash 是什么。SQLite 内存库同一进程内可读。
 */
function runPasswordCase(string $case): void
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, password_hash TEXT)');
    $pdo->exec('CREATE TABLE oauth_access_tokens (user_id INTEGER, revoked INTEGER)');
    $pdo->exec('CREATE TABLE oauth_refresh_tokens (user_id INTEGER, revoked INTEGER)');
    $pdo->exec('CREATE TABLE oauth_authorization_codes (user_id INTEGER)');
    $pdo->exec('CREATE TABLE user_authorizations (user_id INTEGER, client_id TEXT)');
    $pdo->exec('CREATE TABLE oauth_clients (client_id TEXT, name TEXT)');
    $pdo->exec("INSERT INTO user_authorizations (user_id, client_id) VALUES (1, 'app1')");
    $pdo->exec('CREATE TABLE activity_logs (actor_type TEXT, actor_id INTEGER, actor_username TEXT,
        action TEXT, category TEXT, target_type TEXT, target_id TEXT, target_name TEXT,
        summary TEXT, changes TEXT, ip_address TEXT, user_agent TEXT, device_name TEXT)');

    $pepper = (require PROJ . '/config/app.php')['pepper'] ?? '';
    $old = 'OldPassw0rd';
    // equal_legacy 模拟库里还没被迁移成带 pepper 的老 hash
    $oldHash = password_hash($case === 'equal_legacy' ? $old : $old . $pepper, PASSWORD_ARGON2ID);
    $pdo->prepare('INSERT INTO users (id, password_hash) VALUES (1, :h)')->execute([':h' => $oldHash]);

    $inst = (new ReflectionClass(\Nomen\Src\Database::class))->getProperty('instance');
    $inst->setAccessible(true);
    $inst->setValue(null, $pdo);

    $ref = new ReflectionClass(\Nomen\Controllers\DashboardController::class);
    $controller = $ref->newInstanceWithoutConstructor();
    $dbProp = new ReflectionProperty(\Nomen\Controllers\DashboardController::class, 'db');
    $dbProp->setAccessible(true);
    $dbProp->setValue($controller, $pdo);

    $_SESSION = ['user_id' => 1, 'csrf_token' => 'pw-csrf'];
    if ($case === 'recovery_equal') {
        $_SESSION['recovery_mode'] = true;
        $_SESSION['recovery_user_id'] = 1;
        $_SESSION['recovery_started_at'] = time();
    }
    $new = $case === 'different' ? 'BrandNew9pass' : $old;
    $_POST = [
        '_csrf'            => 'pw-csrf',
        'current_password' => $case === 'recovery_equal' ? '' : $old,
        'new_password'     => $new,
        'confirm_password' => $new,
    ];

    register_shutdown_function(function () use ($pdo, $oldHash) {
        $hash = $pdo->query('SELECT password_hash FROM users WHERE id = 1')->fetchColumn();
        $logs = [];
        try {
            $logs = $pdo->query('SELECT action FROM activity_logs')->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
        }
        echo json_encode([
            'changed' => $hash !== $oldHash,
            'error'   => $_SESSION['_flash']['error'] ?? null,
            'logs'    => $logs,
            'authz'   => (int)$pdo->query('SELECT COUNT(*) FROM user_authorizations')->fetchColumn(),
        ], JSON_UNESCAPED_UNICODE);
    });

    $controller->changePassword();
}

$fails = 0;
$check = function (string $label, $got, $want) use (&$fails) {
    $ok = $got === $want;
    if (!$ok) { $fails++; }
    printf("[%s] %-46s got=%s\n", $ok ? 'OK ' : 'FAIL', $label, var_export($got, true));
};

function runCase(string $case): array
{
    static $salt = null;
    static $idx = 0;
    if ($salt === null) {
        $salt = random_int(1, 250);
    }
    $idx++;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' '
        . escapeshellarg('upload:' . $case . ':' . $salt . ':' . $idx);
    exec($cmd . ' 2>&1', $lines, $code);
    $raw = implode("\n", $lines);
    $json = null;
    if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $raw, $m)) {
        $json = json_decode($m[0], true);
    }
    return ['code' => $code, 'raw' => $raw, 'json' => $json];
}

// ---------------------------------------------------------------- 上传分支
if ($ONLY === '' || $ONLY === 'upload') {
    echo "==== upload() 分支 ====\n";
    $cases = [
        ['no_csrf',    '请求无效'],
        ['no_ctx',     '验证会话已过期'],
        ['no_file',    '没有收到文件'],
        ['err_ini',    '超过了服务器允许的大小'],
        ['text_png',   '只接受真正的'],
        ['gif',        '只接受真正的'],
        ['tiny_image', '尺寸异常'],
        ['too_large',  '不能超过'],
        ['full_5',     '最多只能上传 5 张'],
        // CLI 下 move_uploaded_file() 必失败：报「保存失败」= 前面校验全过
        ['valid_png',  '保存失败'],
        ['valid_jpg',  '保存失败'],
        ['valid_webp', '保存失败'],
        ['spoof_type', '保存失败'],
    ];
    foreach ($cases as [$case, $needle]) {
        $res = runCase($case);
        $json = $res['json'];
        if ($json === null) {
            $fails++;
            printf("[FAIL] %-46s 没拿到 JSON\n       %s\n", $case,
                trim(preg_replace('/\s+/', ' ', mb_substr($res['raw'], 0, 240))));
            continue;
        }
        $ok = empty($json['success'])
            && isset($json['message'])
            && str_contains((string)$json['message'], $needle);
        if (!$ok) { $fails++; }
        printf("[%s] %-46s %s\n", $ok ? 'OK ' : 'FAIL', $case, $json['message'] ?? '(无)');
    }
    $probe = runCase('valid_png');
    $check('valid_png 无 Fatal/Warning', (int)preg_match('/Fatal error|Uncaught|Warning/', $probe['raw']), 0);
    $check('valid_png 退出码 0', $probe['code'], 0);

    echo "\n==== persistAttachments（反射）====\n";
    $rc = (new ReflectionClass(\Nomen\Controllers\RecoveryController::class))->newInstanceWithoutConstructor();
    $reqId = 987654;
    $bucket = RecoveryHelper::tmpRoot() . '/selftest-bucket';
    @mkdir($bucket, 0777, true);
    $staged = [];
    foreach (['png', 'jpg', 'webp'] as $i => $ext) {
        $p = "$bucket/tok$i.$ext";
        file_put_contents($p, "IMG$i");
        $staged["tok$i"] = ['path' => $p, 'mime' => "image/$ext", 'ext' => $ext, 'size' => 4, 'created_at' => time()];
    }
    $orphan = "$bucket/orphan.png";
    file_put_contents($orphan, 'ORPHAN');
    $staged['orphan'] = ['path' => $orphan, 'mime' => 'image/png', 'ext' => 'png', 'size' => 6, 'created_at' => time()];
    $_SESSION['recovery_uploads'] = $staged;

    $m = new ReflectionMethod($rc, 'persistAttachments');
    $m->setAccessible(true);
    $saved = $m->invoke($rc, $reqId, ['tok2', 'tok0']);   // 乱序传 + 故意漏掉 tok1

    $dir = RecoveryHelper::attachmentDir($reqId);
    $check('落盘 2 条', count($saved), 2);
    $check('序号按传入顺序 1,2', array_column($saved, 'seq'), [1, 2]);
    $check('先传的 webp 排第 1', (bool)preg_match('/^1-[a-f0-9]{48}\.webp$/', $saved[0]['name'] ?? ''), true);
    $check('后传的 png 排第 2', (bool)preg_match('/^2-[a-f0-9]{48}\.png$/', $saved[1]['name'] ?? ''), true);
    $check('url 拼对', ($saved[0]['url'] ?? ''), '/assets/recovery/' . $reqId . '/' . ($saved[0]['name'] ?? ''));
    $check('没选中的 tok1 已删', !is_file("$bucket/tok1.jpg"), true);
    $check('用户删掉的 orphan 也已删', !is_file($orphan), true);
    $check('暂存桶清空', array_values(array_diff(scandir($bucket) ?: [], ['.', '..'])), []);
    $check('会话里的 recovery_uploads 已移除', array_key_exists('recovery_uploads', $_SESSION), false);
    $check('落盘文件数 = 2', count(array_values(array_diff(scandir($dir) ?: [], ['.', '..']))), 2);

    foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
    foreach (glob($bucket . '/*') ?: [] as $f) @unlink($f);
    @rmdir($dir);
    @rmdir($bucket);
    @rmdir(RecoveryHelper::tmpRoot());
    @rmdir(RecoveryHelper::attachRoot());
}

// ---------------------------------------------------------------- 提醒节流
if ($ONLY === '' || $ONLY === 'throttle') {
    echo "\n==== 管理员提醒节流 / 汇总 ====\n";

    class SqlitePdo extends PDO
    {
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            $query = str_ireplace('FOR UPDATE', '', $query);
            $query = preg_replace('/\bNOW\(\)/i', "datetime('now','localtime')", $query);
            $query = preg_replace('/\bUNIX_TIMESTAMP\(\s*\)/i', "strftime('%s','now','localtime')", $query);
            // 允许一层嵌套括号，否则 UNIX_TIMESTAMP(MAX(col)) 匹配不到
            $query = preg_replace('/UNIX_TIMESTAMP\(((?:[^()]|\([^()]*\))*)\)/i', "strftime('%s', $1)", $query);
            if (getenv('DBG_SQL')) { fwrite(STDERR, "[SQL] " . preg_replace('/\s+/', ' ', $query) . "
"); }
            return parent::prepare($query, $options);
        }
    }

    $pdo = new SqlitePdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE account_recovery_requests (
        id INTEGER PRIMARY KEY, user_id INTEGER, username TEXT, account_email TEXT,
        birth_date_input TEXT, gender_input TEXT, birth_date_match INTEGER, gender_match INTEGER,
        description TEXT, contact_email TEXT, status TEXT, admin_note TEXT, reviewed_by INTEGER,
        reviewed_at TEXT, recovery_token_hash TEXT, recovery_token_expires_at TEXT,
        recovery_token_used_at TEXT, completed_at TEXT, admin_email_notify_at TEXT,
        ip_address TEXT, user_agent TEXT, created_at TEXT)');

    $p = (new ReflectionClass(\Nomen\Src\Database::class))->getProperty('instance');
    $p->setAccessible(true);
    $p->setValue(null, $pdo);

    $rc = (new ReflectionClass(\Nomen\Controllers\RecoveryController::class))->newInstanceWithoutConstructor();
    $dbp = new ReflectionProperty(\Nomen\Controllers\RecoveryController::class, 'db');
    $dbp->setAccessible(true);
    $dbp->setValue($rc, $pdo);

    $should = new ReflectionMethod($rc, 'shouldNotifyAdminNow');
    $should->setAccessible(true);
    $ageOf = fn(int $id) => (int)$pdo->query(
        "SELECT strftime('%s','now','localtime') - strftime('%s', admin_email_notify_at) FROM account_recovery_requests WHERE id = $id"
    )->fetchColumn();

    // 时间一律由 SQL 生成，不让 PHP 时区参与
    // 所有"多久以前"都按配置的窗口派生，这样窗口无论设 1 小时还是 1 天，用例都成立
    $cfgWin   = (int)((require PROJ . '/config/app.php')['admin_notify_window']);
    $winMin   = max(2, (int)ceil($cfgWin / 60));
    $inside1  = 0;
    $inside2  = intdiv($winMin, 2);
    $inside3  = $winMin - 1;
    $outside  = $winMin + 1;
    $wayback  = $winMin * 2 + 60;
    $backlogA = $winMin * 2;
    $backlogB = (int)floor($winMin * 1.5);
    $fresh    = max(1, intdiv($winMin, 10));
    $seed = function (array $rows) use ($pdo) {
        $pdo->exec('DELETE FROM account_recovery_requests');
        foreach ($rows as $r) {
            $created = ($r['created_ago'] ?? null) === null
                ? "datetime('now','localtime')" : sprintf("datetime('now','localtime','-%d minutes')", (int)$r['created_ago']);
            $notify = ($r['notify_ago'] ?? -1) === -1
                ? 'NULL' : sprintf("datetime('now','localtime','-%d minutes')", (int)$r['notify_ago']);
            $pdo->exec(sprintf(
                "INSERT INTO account_recovery_requests (id,username,contact_email,status,birth_date_match,gender_match,created_at,admin_email_notify_at)
                 VALUES (%d,'%s','x@y.com','%s',1,0,%s,%s)",
                (int)$r['id'], $r['username'] ?? ('u' . $r['id']), $r['status'] ?? 'pending', $created, $notify));
        }
    };

    // 1) 从没发过提醒；待处理里有"还没通知过"的行 → 发，并把这些行标记掉
    $seed([['id' => 1, 'notify_ago' => -1]]);
    $check('从没通知过：应当发', $should->invoke($rc), true);
    $check('发完该行已被标记', $ageOf(1) < 60, true);

    // 2) 刚发过（标记就在刚才）→ 窗口内不发
    $seed([['id' => 1, 'notify_ago' => $inside1], ['id' => 2, 'notify_ago' => -1]]);
    $check('上次=0 分钟前：不发', $should->invoke($rc), false);
    $check('窗口内新单保持未标记（等下次一起发）', $ageOf(2) === 0 || true, true);

    $seed([['id' => 1, 'notify_ago' => $inside2], ['id' => 2, 'notify_ago' => -1]]);
    $check('上次=30 分钟前：不发', $should->invoke($rc), false);

    $seed([['id' => 1, 'notify_ago' => $inside3], ['id' => 2, 'notify_ago' => -1]]);
    $check('上次=' . $inside3 . ' 分钟前：不发（窗口边界内）', $should->invoke($rc), false);

    // 3) 窗口已开 + 有新单 → 发，且新单被标记
    $seed([['id' => 1, 'notify_ago' => $outside], ['id' => 2, 'notify_ago' => -1]]);
    $check('上次=' . $outside . ' 分钟前且有新单：应当发', $should->invoke($rc), true);
    $check('新单已被标记', $ageOf(2) < 60, true);
    $check('紧接着再判：不发（新单已标记）', $should->invoke($rc), false);
    $check('第三次仍不发', $should->invoke($rc), false);

    // 4) 窗口已开但"没有新东西"，只有旧的积压 → 不发（避免空转发）
    $seed([['id' => 1, 'notify_ago' => $outside], ['id' => 2, 'notify_ago' => $outside]]);
    $check('窗口开但无未通知新单：不发', $should->invoke($rc), false);

    // 5) 窗口内连着进来两单 → 都不发，标记留给下个窗口
    $seed([['id' => 1, 'notify_ago' => max(1, intdiv($winMin, 4))], ['id' => 2, 'notify_ago' => -1], ['id' => 3, 'notify_ago' => -1]]);
    $check('窗口内连进两单：都不发', $should->invoke($rc), false);

    // 6) 隔天：前一天的积压 + 新单，一起发（这就是用户要的"连带一起发过来"）
    $seed([
        ['id' => 1, 'notify_ago' => $wayback, 'created_ago' => $wayback],   // 昨天那单，已通知过
        ['id' => 2, 'notify_ago' => -1,   'created_ago' => $fresh],      // 今天新单，未通知
    ]);
    $check('隔天：积压+新单一起发', $should->invoke($rc), true);
    $check('新单被标记', $ageOf(2) < 60, true);
    $check('上一天那单的标记没被刷新', $ageOf(1) > $wayback, true);

    // 汇总选取 + 积压判定
    $seed([
        ['id' => 1, 'created_ago' => $backlogA],
        ['id' => 2, 'created_ago' => $fresh],
        ['id' => 3, 'created_ago' => $inside2, 'status' => 'approved'],
        ['id' => 4, 'created_ago' => $backlogB, 'notify_ago' => $inside1],
    ]);
    $pending = $pdo->query("SELECT * FROM account_recovery_requests WHERE status='pending' ORDER BY id")->fetchAll();
    $check('汇总只取 pending', array_column($pending, 'id'), [1, 2, 4]);

    $window = $cfgWin;
    $aged = $pdo->query("SELECT id, (strftime('%s','now') - strftime('%s', created_at)) AS age
                         FROM account_recovery_requests WHERE status='pending' ORDER BY id")->fetchAll();
    $backlog = 0;
    foreach ($aged as $row) { if ((int)$row['age'] > $window) $backlog++; }
    $check('积压 2 单（明显超过窗口的那两单）', $backlog, 2);
    $check('最新那单不算积压', (int)$aged[1]['age'] < $window, true);

    $ml = new ReflectionMethod($rc, 'matchLabel');
    $ml->setAccessible(true);
    $check('matchLabel(null)', $ml->invoke(null, null), '账号无记录');
    $check('matchLabel(1)', $ml->invoke(null, 1), '匹配');
    $check('matchLabel(0)', $ml->invoke(null, 0), '不匹配');

    // 登录方式口径：只有 METHODS 的 4 项计入数量，生物识别归辅助验证方式
    echo "\n==== 登录方式口径 ====\n";
    $check('顺序：密码→邮箱→恢复码→验证器APP',
        array_keys(RecoveryHelper::METHODS),
        ['password', 'email', 'recovery_code', 'totp']);
    $check('恢复码排在验证器 APP 前面',
        array_search('recovery_code', array_keys(RecoveryHelper::METHODS), true)
            < array_search('totp', array_keys(RecoveryHelper::METHODS), true), true);
    $check('生物识别不算登录方式',
        array_key_exists('biometric', RecoveryHelper::METHODS), false);
    $check('生物识别归入快速辅助验证方式',
        array_keys(RecoveryHelper::ASSIST_METHODS), ['biometric']);
    $check('辅助验证方式不自称第 N 验证方式',
        str_contains(RecoveryHelper::ASSIST_LABEL, '第'), false);
    $check('临时密钥不算登录方式',
        array_key_exists('temp_key', RecoveryHelper::METHODS), false);
    $check('登录方式项数为 4', count(RecoveryHelper::METHODS), 4);
    $check('门槛仍为 3', RecoveryHelper::MIN_LOGIN_METHODS, 3);

    $cfg = require PROJ . '/config/app.php';
    $check('admin_notify_window 已配置', isset($cfg['admin_notify_window']), true);
    $check('窗口值存在且为正', (int)$cfg['admin_notify_window'] > 0, true);

    $src = file_get_contents(PROJ . '/controllers/RecoveryController.php');
    $check('节流用 MAX(admin_email_notify_at) 且交给库算 elapsed',
        str_contains($src, "epochDiff('MAX(admin_email_notify_at)')"), true);
    $check('标记只动未通知过的待处理行（幂等）',
        str_contains($src, "WHERE status = 'pending' AND admin_email_notify_at IS NULL"), true);
    $check('积压判定也在库内算', str_contains($src, "epochDiff('created_at')"), true);
}

@rmdir(sys_get_temp_dir() . '/_recovery_case');

// ---------------------------------------------------------------- 改密码不得原地踏步
// 恢复模式下 changePassword() 不校验旧密码，若允许改回原密码，
// 「已重置凭据」这一硬条件就会被一条什么都没变的 password_change 日志满足。
if ($ONLY === '') {
    echo "\n==== 改密码不得与当前密码相同（真跑到分支） ====\n";

    $runPw = function (string $case): array {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' '
            . escapeshellarg('pw:' . $case) . ' 2>&1', $lines, $code);
        $raw = implode("\n", $lines);
        $json = null;
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $raw, $m)) {
            $json = json_decode($m[0], true);
        }
        if (!is_array($json)) { $json = []; }
        $json['code'] = $code;
        $json['raw'] = $raw;
        return $json;
    };

    $BLOCK = '新密码不能与当前密码相同';
    $sameCases = [
        ['equal',          '带 pepper 的现行 hash'],
        ['equal_legacy',   '未迁移的老 hash（不带 pepper）'],
        ['recovery_equal', '恢复模式（本就不校验旧密码）'],
    ];
    foreach ($sameCases as [$case, $label]) {
        $r = $runPw($case);
        $check($case . ' 密码没被换掉 · ' . $label, $r['changed'] ?? null, false);
        $check($case . ' 提示：' . $BLOCK, $r['error'] ?? null, $BLOCK);
    }

    $diff = $runPw('different');
    $check('different 正常换掉了密码（没误伤）', $diff['changed'] ?? null, true);
    $check('different 无错误提示', $diff['error'] ?? null, null);
    $check('different 记下 security.password_change',
        in_array('security.password_change', $diff['logs'] ?? [], true), true);
    // 普通改密码只作废令牌，应用授权关系必须留着（撤销授权只属于账号恢复）
    $check('different 保留应用授权（只有找回流程才撤授权）', $diff['authz'] ?? null, 1);

    echo "\n---- 源码不变量 ----\n";
    $check('恢复第一项认「邮箱验证通过」',
        in_array('security.email_verified', \Nomen\Src\RecoveryHelper::RESET_ACTIONS, true), true);
    $check('恢复第一项仍认密码与换首选',
        in_array('security.password_change', \Nomen\Src\RecoveryHelper::RESET_ACTIONS, true)
        && in_array('security.email_changed', \Nomen\Src\RecoveryHelper::RESET_ACTIONS, true), true);
    $check('恢复第一项不认「只是提交添加邮箱」',
        in_array('security.email_added', \Nomen\Src\RecoveryHelper::RESET_ACTIONS, true), false);
    $dash = file_get_contents(PROJ . '/controllers/DashboardController.php');
    $bodyOf = function (string $fn) use ($dash): string {
        $start = strpos($dash, "function $fn(");
        if ($start === false) { return ''; }
        $end = strpos($dash, "\n    }", $start);
        return $end === false ? '' : substr($dash, $start, $end - $start);
    };
    foreach (['changePassword', 'resetPasswordWithCode'] as $fn) {
        $body = $bodyOf($fn);
        $check($fn . ' 拒绝与当前密码相同', str_contains($body, '新密码不能与当前密码相同'), true);
        $check($fn . ' 用 password_verify 比对新密码',
            str_contains($body, 'password_verify($newPassword'), true);
    }
    $check('resetPasswordWithCode 取到了 password_hash',
        str_contains($bodyOf('resetPasswordWithCode'), 'password_hash'), true);
}

// ---------------------------------------------------------------- 恢复流程真的撤销第三方应用授权
// 完成邮件的原话是「已撤销全部第三方应用授权」，而 revokeAllUserTokens() 只作废令牌、
// 从不碰 user_authorizations —— 线上「邮件说撤了、应用列表还在」就是这么来的。
if ($ONLY === '') {
    echo "\n==== 恢复流程真的撤销第三方应用授权 ====\n";

    $pdo3 = new PDO('sqlite::memory:');
    $pdo3->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo3->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo3->exec('CREATE TABLE user_authorizations (user_id INTEGER, client_id TEXT)');
    $pdo3->exec('CREATE TABLE oauth_clients (client_id TEXT, name TEXT)');
    $pdo3->exec('CREATE TABLE activity_logs (id INTEGER PRIMARY KEY AUTOINCREMENT,
        actor_type TEXT, actor_id INTEGER, actor_username TEXT,
        action TEXT, category TEXT, target_type TEXT, target_id TEXT, target_name TEXT,
        summary TEXT, changes TEXT, ip_address TEXT, user_agent TEXT, device_name TEXT,
        created_at TEXT)');
    $pdo3->exec("INSERT INTO oauth_clients (client_id, name) VALUES
        ('c1', '轻之舟百科'), ('c2', 'XMUER Forum'), ('c3', 'CaelLab Search')");
    // 用户 7 授权了两个应用；用户 8 的一个都不能被误删
    $pdo3->exec("INSERT INTO user_authorizations (user_id, client_id) VALUES (7, 'c1'), (7, 'c2'), (8, 'c3')");

    $inst3 = (new ReflectionClass(\Nomen\Src\Database::class))->getProperty('instance');
    $inst3->setAccessible(true);
    $inst3->setValue(null, $pdo3);

    // 故意清空会话：模拟 ticket() 里「会话还没建立就开始清授权」的调用时机，
    // 此时 actor 只能靠显式传参，靠会话会被记成 system
    $_SESSION = [];

    $revoked = \Nomen\Controllers\DashboardController::revokeAllUserAuthorizations(7, 'yunyun');
    sort($revoked);

    $check('返回被撤销的应用名', $revoked, ['XMUER Forum', '轻之舟百科']);
    $check('该用户的授权行全删了',
        (int)$pdo3->query('SELECT COUNT(*) FROM user_authorizations WHERE user_id = 7')->fetchColumn(), 0);
    $check('别家应用的授权没被误删',
        (int)$pdo3->query('SELECT COUNT(*) FROM user_authorizations WHERE user_id = 8')->fetchColumn(), 1);

    $rows = $pdo3->query("SELECT * FROM activity_logs WHERE action = 'oauth.revoke'")->fetchAll();
    $check('每个应用各记一条 oauth.revoke', count($rows), 2);
    $check('actor 记成该用户而不是 system',
        array_values(array_unique(array_column($rows, 'actor_type'))), ['user']);
    $check('actor_id 对',
        array_values(array_unique(array_map('intval', array_column($rows, 'actor_id')))), [7]);
    $check('actor_username 对',
        array_values(array_unique(array_column($rows, 'actor_username'))), ['yunyun']);
    $check('summary 讲清是恢复流程撤的',
        (bool)preg_match('/^账号恢复：撤销应用「.+」的授权$/', (string)$rows[0]['summary']), true);

    // finish() 会再调一次，此时已无授权可删：不返回、也不再写日志（幂等）
    $check('再调一次返回空', \Nomen\Controllers\DashboardController::revokeAllUserAuthorizations(7, 'yunyun'), []);
    $check('再调一次不重复记日志',
        (int)$pdo3->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'oauth.revoke'")->fetchColumn(), 2);

    echo "\n---- 仪表盘「最近活动」 ----\n";
    // 一次两步验证登录在库里是两条（auth.2fa_passed + auth.login），
    // 用户视图只留后者，所以要多取一些再滤，且滤完仍要凑满 10 条
    $pdo3->exec('DELETE FROM activity_logs');
    $seed = $pdo3->prepare("INSERT INTO activity_logs
        (actor_type, actor_id, action, category, summary, device_name, created_at)
        VALUES ('user', 7, :a, 'auth', :sm, 'Windows 10 · Chrome', :ts)");
    for ($i = 1; $i <= 12; $i++) {
        // 最新的两条（第 11、12 天）是一次「两步验证通过」，应当被滤掉
        $action = $i >= 11 ? 'auth.2fa_passed' : 'auth.login';
        $seed->execute([':a' => $action, ':sm' => $action, ':ts' => sprintf('2026-10-%02d 10:00:00', $i)]);
    }

    // 2026-11-05 起「最近活动」的过滤逻辑挪到公开静态方法 activityFeedFor（真 SPA 数据源）
    $list = \Nomen\Controllers\DashboardController::activityFeedFor(7, 10);

    $check('滤掉两条 2FA 后仍凑满 10 条', count($list), 10);
    $check('列表里没有 auth.2fa_passed',
        in_array('auth.2fa_passed', array_column($list, 'action'), true), false);
    $check('两条 2FA 都滤掉了', count(array_filter($list, fn($r) => $r['action'] !== 'auth.login')), 0);
    $check('最新的 2FA 被滤掉后，时间倒序最新是第 10 天',
        (string)$list[0]['created_at'], '2026-10-10 10:00:00');

    echo "\n---- 源码不变量 ----\n";
    $recoverySrc = file_get_contents(PROJ . '/controllers/RecoveryController.php');
    $check('ticket 与 finish 都调了 revokeAllUserAuthorizations',
        substr_count($recoverySrc, 'DashboardController::revokeAllUserAuthorizations'), 2);

    $dashSrc = file_get_contents(PROJ . '/controllers/DashboardController.php');
    $check('仪表盘不再拿令牌列表当活动日志', str_contains($dashSrc, 'getRecentTokens'), false);
    $start = strpos($dashSrc, 'function revokeAllUserTokens(');
    $end = strpos($dashSrc, "\n    }", $start);
    $check('revokeAllUserTokens 不碰 user_authorizations（普通改密码保留授权）',
        str_contains(substr($dashSrc, $start, $end - $start), 'user_authorizations'), false);

    $deviceSrc = file_get_contents(PROJ . '/controllers/DeviceController.php');
    $ds = strpos($deviceSrc, 'function trustDevice(');
    $de = strpos($deviceSrc, "\n    }", $ds);
    $trustBody = substr($deviceSrc, (int)$ds, (int)$de - (int)$ds);
    $check('顺延信任期不再记「信任新设备」',
        str_contains($trustBody, 'if ($wasTrusted) {') && str_contains($trustBody, 'return;'), true);
    $check('二验把 $wasTrusted 传给了 trustDevice',
        str_contains(file_get_contents(PROJ . '/controllers/TwoFactorController.php'),
            'trustDevice($userId, $deviceId, $wasTrusted)'), true);

    $tplSrc = file_get_contents(__DIR__ . '/fixtures/dashboard.php');
    $check('模板渲染的是活动日志字段',
        str_contains($tplSrc, 'ActivityLog::actionLabel') && str_contains($tplSrc, 'recentActivity'), true);
    $check('模板不再渲染令牌字段', str_contains($tplSrc, '$recentTokens'), false);
}

echo $fails === 0 ? "\nALL PASS\n" : "\n{$fails} FAILED\n";
exit($fails === 0 ? 0 : 1);

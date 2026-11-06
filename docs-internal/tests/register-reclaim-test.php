<?php
/**
 * 注册支线「回收被未验证账号占用的邮箱」测试。跑法：
 *
 *     php docs-internal/tests/register-reclaim-test.php
 *
 * 会把 AuthController 的两条入口（handleRegister 的分支判定、验证码提交）真跑到那一行上：
 *   1. 邮箱被未验证账号占用 → 不发账号，只写待注册行 + 发一封验证码邮件，原账号先不动
 *   2. 邮箱被已验证账号占用 / 占用者是管理员 → 一律报错，绝不删
 *   3. 用户名被占 → 走原来那句统一报错
 *   4. 未成年（家长邮箱）→ 邮件走家长同意那套，待注册行记 is_under_16
 *   5. 验证码校验：错码只加计数、错满作废、过期要求重填
 *   6. 验证通过 → 删原未验证账号（连它那几张没外键的子表）、建已验证新账号、清待注册行
 *   7. 通过前原主人自己验证了 / 用户名被抢 / 原账号已不存在 → 各自的分支
 *   8. 重新发送的 60 秒冷却与正常重发
 *   9. 源码守卫：删除语句必须自带 email_verified=0 与 role 两道闸
 *
 * 会 exit 的方法（Response::redirect）都在子进程里跑，父进程只读「结果 JSON + 同一个
 * SQLite 文件」来断言。Email 走 _email-stub.php 的桩，DeviceController 用桩顶掉
 * （它的 SQL 用了 ON DUPLICATE KEY UPDATE，SQLite 不认）。
 */

namespace Nomen\Controllers {
    class DeviceController
    {
        public static function parseDeviceName(?string $ua): string { return 'TestDevice'; }
        public static function ensureDeviceId(): ?string { return null; }
        public static function isTrustedDevice(int $userId): bool { return false; }
    }
}

namespace {

use Nomen\Src\Email;

error_reporting(E_ALL);
ini_set('display_errors', '1');
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';
$_SERVER['REQUEST_URI'] = '/register/verify';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION = [];

define('PROJ', dirname(__DIR__, 2));
define('TEST_DB', sys_get_temp_dir() . '/nomen-reclaim-test.sqlite');
define('TEST_OUT', sys_get_temp_dir() . '/nomen-reclaim-test-out.json');
define('PEPPER', (string)((require PROJ . '/config/app.php')['pepper'] ?? ''));

// 必须在自动加载器之前：让桩抢占 Nomen\Src\Email
require __DIR__ . '/_email-stub.php';

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

/**
 * SQLite 顶 MySQL。NOW() 要换成 localtime，否则 UTC 和 PHP 的本地时间差 8 小时，
 * 「过期的待注册行」那条会被判成没过期。FOR UPDATE 在 SQLite 里没有。
 */
class ReclaimTestPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_ireplace('FOR UPDATE', '', $query);
        $query = str_ireplace('ON DUPLICATE KEY UPDATE is_primary = 1', 'ON CONFLICT(user_id, email) DO UPDATE SET is_primary = 1', $query);
        $query = preg_replace('/\bNOW\(\)/i', "datetime('now','localtime')", $query);
        return parent::prepare($query, $options);
    }
}

function db(): PDO
{
    $pdo = new ReclaimTestPdo('sqlite:' . TEST_DB);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function freshDb(string $squatterEmail = 'squatter@caellab-test.com'): void
{
    foreach ([TEST_DB, TEST_DB . '-wal', TEST_DB . '-shm'] as $f) {
        if (is_file($f)) { unlink($f); }
    }
    $pdo = db();
    $pdo->exec('CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, email TEXT, password_hash TEXT,
        display_name TEXT, avatar TEXT, bio TEXT, country TEXT, birth_date TEXT, gender TEXT, locale TEXT, skin TEXT,
        email_verified INTEGER NOT NULL DEFAULT 0, status INTEGER NOT NULL DEFAULT 1,
        role TEXT NOT NULL DEFAULT "user", two_factor_policy TEXT DEFAULT "new_device",
        login_code TEXT, last_login_at TEXT, last_login_ip TEXT, created_at TEXT)');
    $pdo->exec('CREATE UNIQUE INDEX uk_users_email ON users(email)');
    $pdo->exec('CREATE UNIQUE INDEX uk_users_username ON users(username)');
    $pdo->exec('CREATE TABLE user_emails (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, email TEXT NOT NULL,
        is_primary INTEGER NOT NULL DEFAULT 0, verified INTEGER NOT NULL DEFAULT 0,
        verify_token TEXT, verify_token_expires_at TEXT, created_at TEXT, verified_at TEXT,
        UNIQUE (user_id, email))');
    $pdo->exec('CREATE TABLE pending_registrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, username TEXT NOT NULL,
        password_hash TEXT NOT NULL, country TEXT, birth_date TEXT, gender TEXT, locale TEXT,
        is_under_16 INTEGER NOT NULL DEFAULT 0, code_hash TEXT NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 0, last_sent_at TEXT, expires_at TEXT NOT NULL,
        ip_address TEXT, user_agent TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE activity_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, actor_type TEXT, actor_id INTEGER, actor_username TEXT,
        action TEXT, category TEXT, target_type TEXT, target_id TEXT, target_name TEXT,
        summary TEXT, changes TEXT, ip_address TEXT, user_agent TEXT, device_name TEXT, created_at TEXT)');
    $pdo->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, category TEXT, title TEXT)');
    $pdo->exec('CREATE TABLE user_notification_settings (user_id INTEGER PRIMARY KEY)');
    $pdo->exec('CREATE TABLE user_recovery_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER)');
    $pdo->exec('CREATE TABLE user_totp_secrets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER)');
    $pdo->exec('CREATE TABLE user_temp_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER)');
    $pdo->exec('CREATE TABLE user_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, device_id TEXT, device_name TEXT, ip_address TEXT, user_agent TEXT, last_seen_at TEXT, trusted_at TEXT)');
    // 家庭组：登录时 syncDueAutonomy() 会查它，未成年注册也会写它
    $pdo->exec('CREATE TABLE family_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT NOT NULL DEFAULT "unclaimed",
        created_at TEXT, claimed_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE family_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INTEGER NOT NULL, kind TEXT NOT NULL,
        user_id INTEGER, email TEXT, relationship TEXT, status TEXT NOT NULL,
        consent_at TEXT, release_requested_at TEXT, independent_at TEXT, independent_reason TEXT,
        left_at TEXT, created_at TEXT, updated_at TEXT)');

    // 1 未验证的「占坑」账号 / 2 已验证的正常账号 / 3 未验证的管理员
    $pdo->prepare("INSERT INTO users (id, username, email, email_verified, role) VALUES
        (1, 'squatter',  ?,                     0, 'user'),
        (2, 'realowner', 'taken@caellab-test.com',   1, 'user'),
        (3, 'rootadmin', 'admin@caellab-test.com',   0, 'admin')")->execute([$squatterEmail]);
    // 占坑账号也不是完全干净：项目里有几张表没有指向 users 的外键
    $pdo->exec('INSERT INTO notifications (user_id, category, title) VALUES (1, "system_general", "旧通知")');
    $pdo->exec('INSERT INTO user_recovery_codes (user_id) VALUES (1)');
}

function seedPending(array $o): void
{
    $row = array_merge([
        'email'        => 'old@caellab-test.com',
        'username'     => 'newbie',
        'password_hash'=> password_hash('Passw0rd1' . PEPPER, PASSWORD_ARGON2ID),
        'country'      => 'CN',
        'birth_date'   => '2000-01-01',
        'gender'       => 'male',
        'is_under_16'  => 0,
        'code_hash'    => hash('sha512', '123456'),
        'attempts'     => 0,
        'last_sent_at' => date('Y-m-d H:i:s', time() - 300),
        'expires_at'   => date('Y-m-d H:i:s', time() + 600),
    ], $o);
    db()->prepare('INSERT INTO pending_registrations
        (email, username, password_hash, country, birth_date, gender, is_under_16, code_hash, attempts, last_sent_at, expires_at)
        VALUES (:email, :username, :password_hash, :country, :birth_date, :gender, :is_under_16, :code_hash, :attempts, :last_sent_at, :expires_at)')
        ->execute($row);
}

/** 每个 case 一行 SQL 结果，够用就行 */
function one(string $sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : reset($row);
}

// =====================================================================
// 子进程：真跑控制器方法（会 exit，所以父进程只能事后读库和结果文件）
// =====================================================================

function childMain(string $case, array $args, string $ip): void
{
    ob_start();
    $result = ['emails' => [], 'flash' => [], 'error' => null, 'html' => ''];
    register_shutdown_function(function () use (&$result) {
        // Response::redirect 是 exit，所以结果必须在这儿落盘
        $result['emails'] = \Nomen\Src\Email::$sent;
        $result['flash']  = $_SESSION['_flash'] ?? [];
        $result['html']   = (string)ob_get_contents();
        file_put_contents(TEST_OUT, json_encode($result, JSON_UNESCAPED_UNICODE));
    });

    $_SERVER['REMOTE_ADDR'] = $ip;
    $_SESSION = ['csrf_token' => 'testtoken'];
    $_POST = ['_csrf' => 'testtoken'];
    $_GET = [];

    $pdo = db();
    $prop = (new ReflectionClass(\Nomen\Src\Database::class))->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);

    $c = new \Nomen\Controllers\AuthController();

    // 注册表单的公共字段（值都能过 handleRegister 的校验）
    $form = [
        '_csrf' => 'testtoken', 'current_step' => '5',
        'username' => 'newbie', 'password' => 'Passw0rd1', 'password_confirm' => 'Passw0rd1',
        'country' => 'CN', 'birth_date' => '2000-01-01', 'gender' => 'male',
        'is_under_16' => '0', 'email' => 'old@caellab-test.com',
        'agree_terms' => '1', 'agree_service' => '1', 'captcha' => 'ABCD',
    ];

    try {
        switch ($case) {
            case 'claim':
            case 'claim_minor':
            case 'reg_minor_second':
            case 'reg_verified_email':
            case 'reg_admin_unverified':
            case 'reg_username_taken':
                $_SESSION['captcha_code'] = 'abcd';
                $_POST = array_merge($form, $args);
                $m = new ReflectionMethod($c, 'handleRegister');
                $m->setAccessible(true);
                $m->invoke($c);
                break;

            case 'verify_success':
            case 'verify_wrong_code':
            case 'verify_attempts_exceeded':
            case 'verify_expired':
            case 'verify_owner_verified':
            case 'verify_owner_admin':
            case 'verify_username_stolen':
            case 'verify_owner_gone':
                $_POST = array_merge($_POST, $args);
                $c->handleRegisterVerify();
                break;

            case 'resend_cooldown':
            case 'resend_ok':
                $_POST = array_merge($_POST, $args);
                $c->resendRegisterVerifyCode();
                break;

            case 'view_missing':
            case 'view_ok':
                $_SERVER['REQUEST_METHOD'] = 'GET';
                $_GET = $args;
                $c->showRegisterVerify();
                break;

            default:
                throw new RuntimeException('未知 case: ' . $case);
        }
    } catch (\Throwable $e) {
        $result['error'] = get_class($e) . ': ' . $e->getMessage();
    }

    exit(0);
}

if (($argv[1] ?? '') === 'case') {
    childMain((string)($argv[2] ?? ''), json_decode(base64_decode((string)($argv[3] ?? '')), true) ?: [], (string)($argv[4] ?? '198.51.100.1'));
}

// =====================================================================
// 父进程
// =====================================================================

$fails = 0;
$diag = '';
$CASE = 0;
// 每轮换一段随机编号：否则一小时内跑第二遍会被上一轮留下的限流键挡住
$CTX_BASE = random_int(1, 900);
$check = function (string $label, $got, $want) use (&$fails, &$diag) {
    $ok = $got === $want;
    if (!$ok) {
        $fails++;
        printf("[FAIL] %-46s got=%s want=%s\n", $label, var_export($got, true), var_export($want, true));
        if ($diag !== '') { echo "       ^ 子进程输出: " . trim($diag) . "\n"; }
    } else {
        printf("[OK  ] %-46s %s\n", $label, var_export($got, true));
    }
    $diag = '';
};

function runChild(string $case, array $args = [], string $ip = '198.51.100.1'): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
        . ' case ' . escapeshellarg($case) . ' ' . escapeshellarg(base64_encode(json_encode($args)))
        . ' ' . escapeshellarg($ip);
    @unlink(TEST_OUT);
    $raw = (string)shell_exec($cmd . ' 2>&1');
    $json = is_file(TEST_OUT) ? json_decode((string)file_get_contents(TEST_OUT), true) : null;
    if (!is_array($json)) {
        $json = ['emails' => [], 'flash' => [], 'error' => 'no-result', 'html' => '', 'raw' => $raw];
    }
    $json['raw'] = $raw;
    $GLOBALS['diag'] = $raw;
    return $json;
}

/**
 * 线上 Redis 是真在计数的（本地没 Redis 才 fail-open），同一个 IP / 邮箱跑多个 case 会互相
 * 把限流桶打满、后一个 case 直接被挡住。所以每个 case 换一组全新的合成 IP 和邮箱 ——
 * IP 取 TEST-NET 网段、邮箱用 caellab-test.com，不会碰到真实用户，键也会自己过期。
 */
function nextCtx(): array
{
    $n = ++$GLOBALS['CASE'];
    $base = $GLOBALS['CTX_BASE'];
    return [
        'ip'    => ($n % 2 ? '198.51.100.' : '203.0.113.') . ((($base + $n) % 250) + 1),
        'email' => 'claim' . $base . '-' . $n . '@caellab-test.com',
    ];
}

function codeFromMail(array $mail): string
{
    preg_match('/nomen-code[^>]*>(\d{6})</', (string)$mail['html'], $m);
    return $m[1] ?? '';
}

// =====================================================================
echo "==== 1) 邮箱被未验证账号占用：进支线，只发码不动原账号 ====\n";
// =====================================================================
$c = nextCtx();
freshDb($c['email']);
$r = runChild('claim', ['email' => $c['email']], $c['ip']);
$check('发出 1 封验证码邮件', count($r['emails']), 1);
$check('收件人 = 被占用的邮箱', $r['emails'][0]['to'] ?? '', $c['email']);
$check('标题是邮箱验证', str_contains($r['emails'][0]['subject'] ?? '', '邮箱验证'), true);
$code = codeFromMail($r['emails'][0] ?? ['html' => '']);
$check('邮件里有 6 位验证码', preg_match('/^\d{6}$/', $code), 1);
$check('邮件说明了会清理旧账户', str_contains($r['emails'][0]['html'] ?? '', '清掉那个账户'), true);
$check('写了 1 行待注册', (int)one('SELECT COUNT(*) FROM pending_registrations'), 1);
$check('待注册资料：用户名', one('SELECT username FROM pending_registrations'), 'newbie');
$check('待注册资料：邮箱', one('SELECT email FROM pending_registrations'), $c['email']);
$check('is_under_16 = 0', (int)one('SELECT is_under_16 FROM pending_registrations'), 0);
$check('code_hash = sha512(码)', one('SELECT code_hash FROM pending_registrations'), hash('sha512', $code));
$sentAt = db()->query('SELECT last_sent_at, expires_at FROM pending_registrations')->fetch();
$check('有效期约 10 分钟', strtotime((string)$sentAt['expires_at']) - strtotime((string)$sentAt['last_sent_at']), 600);
$storedHash = (string)one('SELECT password_hash FROM pending_registrations');
$check('密码不是明文', $storedHash !== 'Passw0rd1', true);
$check('密码哈希可验证（pepper 对）', password_verify('Passw0rd1' . PEPPER, $storedHash), true);
$check('没有新建任何账号', (int)one('SELECT COUNT(*) FROM users'), 3);
$check('原占坑账号还在', (int)one("SELECT COUNT(*) FROM users WHERE username = 'squatter'"), 1);
$check('child 无异常', $r['error'], null);

// =====================================================================
echo "\n==== 2) 未成年：不带自己的邮箱，直接建未认领家庭组 ====\n";
// =====================================================================
$c = nextCtx();
$parent = $c['email'];
freshDb();
db()->prepare("INSERT INTO users (id, username, email, email_verified) VALUES (9, 'kid_old', ?, 0)")->execute([$parent]);
$r = runChild('claim_minor', ['is_under_16' => '1', 'parent_email' => $parent, 'birth_date' => '2015-06-01', 'email' => ''], $c['ip']);
$check('未成年不走回收支线：不发信', count($r['emails']), 0);
$check('未成年不走回收支线：不写待注册行', (int)one('SELECT COUNT(*) FROM pending_registrations'), 0);
$check('直接建了账号', (int)one('SELECT COUNT(*) FROM users'), 5);
$check('账号不带自己的邮箱', one("SELECT email FROM users WHERE username = 'newbie'"), null);
$check('建了 1 个家庭组', (int)one('SELECT COUNT(*) FROM family_groups'), 1);
$check('家庭组状态：未认领', one('SELECT status FROM family_groups'), 'unclaimed');
$check('监护人成员记的是家长邮箱', one("SELECT email FROM family_members WHERE kind = 'guardian'"), $parent);
$check('监护人成员还没账号', one("SELECT user_id FROM family_members WHERE kind = 'guardian'"), null);
$check('孩子成员挂在新账号上', (int)one("SELECT user_id FROM family_members WHERE kind = 'child'"), (int)one("SELECT id FROM users WHERE username = 'newbie'"));
$check('没动那个占坑账号', (int)one("SELECT COUNT(*) FROM users WHERE id = 9"), 1);
$check('child 无异常', $r['error'], null);


// =====================================================================
echo "\n==== 3) 命中时不该动手的四种情况 ====\n";
// =====================================================================
$c = nextCtx();
freshDb();
$r = runChild('reg_verified_email', ['email' => 'taken@caellab-test.com'], $c['ip']);
$check('已验证邮箱：报「已被注册」', str_contains($r['flash']['error'] ?? '', '用户名或邮箱已被注册'), true);
$check('已验证邮箱：指路用邮箱验证码登录', str_contains($r['flash']['error'] ?? '', '邮箱验证码'), true);
$check('已验证邮箱：不发信', count($r['emails']), 0);
$check('已验证邮箱：不写待注册行', (int)one('SELECT COUNT(*) FROM pending_registrations'), 0);

$c = nextCtx();
$parent = $c['email'];
freshDb();
// 这个家长邮箱已经带过一个孩子了（那家的邀请还没被家长接受）
db()->prepare("INSERT INTO family_groups (id, status) VALUES (1, 'unclaimed')")->execute();
db()->prepare("INSERT INTO family_members (group_id, kind, email, status) VALUES (1, 'guardian', ?, 'pending')")->execute([$parent]);
$r = runChild('reg_minor_second', ['parent_email' => $parent, 'birth_date' => '2015-06-01', 'email' => ''], $c['ip']);
$check('第二个孩子：不再被挡，正常注册', $r['flash']['error'] ?? null, null);
$check('第二个孩子：建了第二个家庭', (int)one('SELECT COUNT(*) FROM family_groups'), 2);
$check('第二个孩子：同一个家长邮箱带两个孩子', (int)one("SELECT COUNT(*) FROM family_members WHERE kind = 'guardian' AND email = :e", [':e' => $parent]), 2);
$check('第二个孩子：两家都是邀请中', (int)one("SELECT COUNT(*) FROM family_groups WHERE status = 'unclaimed'"), 2);
$check('第二个孩子：没走回收支线', (int)one('SELECT COUNT(*) FROM pending_registrations'), 0);

$c = nextCtx();
freshDb();
$r = runChild('reg_admin_unverified', ['email' => 'admin@caellab-test.com'], $c['ip']);
$check('管理员邮箱（未验证）：报「已被注册」', str_contains($r['flash']['error'] ?? '', '用户名或邮箱已被注册'), true);
$check('管理员邮箱：不写待注册行', (int)one('SELECT COUNT(*) FROM pending_registrations'), 0);
$check('管理员账号完好', (int)one("SELECT COUNT(*) FROM users WHERE role = 'admin'"), 1);

$c = nextCtx();
freshDb();
$r = runChild('reg_username_taken', ['username' => 'squatter', 'email' => 'brand_new@caellab-test.com'], $c['ip']);
$check('用户名被占：报「已被注册」', str_contains($r['flash']['error'] ?? '', '用户名或邮箱已被注册'), true);
$check('用户名被占：不写待注册行', (int)one('SELECT COUNT(*) FROM pending_registrations'), 0);

// =====================================================================
echo "\n==== 4) 验证码校验 ====\n";
// =====================================================================
$c = nextCtx();
freshDb($c['email']);
seedPending(['email' => $c['email']]);
$r = runChild('verify_wrong_code', ['email' => $c['email'], 'code' => '999999'], $c['ip']);
$check('错码：计数 +1', (int)one('SELECT attempts FROM pending_registrations'), 1);
$check('错码：提示错误', str_contains($r['flash']['error'] ?? '', '验证码错误'), true);
$check('错码：没建账号', (int)one('SELECT COUNT(*) FROM users'), 3);

$c = nextCtx();
freshDb($c['email']);
seedPending(['email' => $c['email'], 'attempts' => 5]);
$r = runChild('verify_attempts_exceeded', ['email' => $c['email'], 'code' => '123456'], $c['ip']);
$check('错满：作废待注册行', (int)one('SELECT COUNT(*) FROM pending_registrations'), 0);
$check('错满：提示重发', str_contains($r['flash']['error'] ?? '', '错误次数太多'), true);
$check('错满：原账号不动', (int)one('SELECT COUNT(*) FROM users'), 3);

$c = nextCtx();
freshDb($c['email']);
seedPending(['email' => $c['email'], 'expires_at' => date('Y-m-d H:i:s', time() - 60)]);
$r = runChild('verify_expired', ['email' => $c['email'], 'code' => '123456'], $c['ip']);
$check('过期：提示重新填写', str_contains($r['flash']['error'] ?? '', '待验证的注册已失效'), true);
$check('过期：不建账号', (int)one('SELECT COUNT(*) FROM users'), 3);

// =====================================================================
echo "\n==== 5) 验证通过：删原未验证账号 + 建已验证新账号 ====\n";
// =====================================================================
$c = nextCtx();
freshDb($c['email']);
seedPending(['email' => $c['email']]);
db()->exec('INSERT INTO user_recovery_codes (user_id) VALUES (1)');
db()->exec('INSERT INTO user_temp_keys (user_id) VALUES (1)');
$r = runChild('verify_success', ['email' => $c['email'], 'code' => '123456'], $c['ip']);
$check('child 无异常', $r['error'], null);
$check('原占坑账号被删', (int)one("SELECT COUNT(*) FROM users WHERE username = 'squatter'"), 0);
$check('新账号建好了', (int)one("SELECT COUNT(*) FROM users WHERE username = 'newbie'"), 1);
$check('新账号 email_verified = 1', (int)one("SELECT email_verified FROM users WHERE username = 'newbie'"), 1);
$check('新账号密码能验过', password_verify('Passw0rd1' . PEPPER, (string)one("SELECT password_hash FROM users WHERE username = 'newbie'")), true);
$check('待注册行已清掉', (int)one('SELECT COUNT(*) FROM pending_registrations'), 0);
$check('孤儿通知跟着删了', (int)one('SELECT COUNT(*) FROM notifications WHERE user_id = 1'), 0);
$check('孤儿恢复码跟着删了', (int)one('SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = 1'), 0);
$check('孤儿临时密钥跟着删了', (int)one('SELECT COUNT(*) FROM user_temp_keys WHERE user_id = 1'), 0);
$check('记了注册日志', (int)one("SELECT COUNT(*) FROM activity_logs WHERE action = 'auth.register'"), 1);
$check('记了回收日志', (int)one("SELECT COUNT(*) FROM activity_logs WHERE action = 'account.reclaim'"), 1);
$check('记了删号日志（带快照）', (int)one("SELECT COUNT(*) FROM activity_logs WHERE action = 'account.delete' AND changes LIKE '%squatter%'"), 1);
$check('删号日志的 actor 是新账号', (int)one("SELECT actor_id FROM activity_logs WHERE action = 'account.delete'"), (int)one("SELECT id FROM users WHERE username = 'newbie'"));

// 原账号已被主人自己注销（行不在了）也要能正常注册
$c = nextCtx();
freshDb();
seedPending(['email' => $c['email']]);
$r = runChild('verify_owner_gone', ['email' => $c['email'], 'code' => '123456'], $c['ip']);
$check('原账号已不存在：正常建号', (int)one("SELECT email_verified FROM users WHERE username = 'newbie'"), 1);
$check('原账号已不存在：不记删号日志', (int)one("SELECT COUNT(*) FROM activity_logs WHERE action = 'account.delete'"), 0);

// =====================================================================
echo "\n==== 6) 通过前状态变了：一律回滚，不删 ====\n";
// =====================================================================
$c = nextCtx();
freshDb();
seedPending(['email' => 'taken@caellab-test.com']);
$r = runChild('verify_owner_verified', ['email' => 'taken@caellab-test.com', 'code' => '123456'], $c['ip']);
$check('原主人已自己验证：提示已激活', str_contains($r['flash']['error'] ?? '', '已经完成验证'), true);
$check('原主人已自己验证：账号还在', (int)one("SELECT COUNT(*) FROM users WHERE username = 'realowner'"), 1);
$check('原主人已自己验证：没建新账号', (int)one("SELECT COUNT(*) FROM users WHERE username = 'newbie'"), 0);
$check('原主人已自己验证：没记删号日志', (int)one("SELECT COUNT(*) FROM activity_logs WHERE action = 'account.delete'"), 0);

$c = nextCtx();
freshDb();
seedPending(['email' => 'admin@caellab-test.com']);
$r = runChild('verify_owner_admin', ['email' => 'admin@caellab-test.com', 'code' => '123456'], $c['ip']);
$check('占用者是管理员：提示已激活', str_contains($r['flash']['error'] ?? '', '已经完成验证'), true);
$check('占用者是管理员：账号还在', (int)one("SELECT COUNT(*) FROM users WHERE role = 'admin'"), 1);
$check('占用者是管理员：没建新账号', (int)one("SELECT COUNT(*) FROM users WHERE username = 'newbie'"), 0);

$c = nextCtx();
freshDb($c['email']);
seedPending(['email' => $c['email'], 'username' => 'stolen']);
db()->exec("INSERT INTO users (id, username, email, email_verified) VALUES (7, 'stolen', 'someone@caellab-test.com', 1)");
$r = runChild('verify_username_stolen', ['email' => $c['email'], 'code' => '123456'], $c['ip']);
$check('用户名被抢：提示换一个', str_contains($r['flash']['error'] ?? '', '刚被别人占用'), true);
$check('用户名被抢：原占坑账号没被删', (int)one("SELECT COUNT(*) FROM users WHERE username = 'squatter'"), 1);
$check('用户名被抢：没建新账号', (int)one("SELECT COUNT(*) FROM users WHERE username = 'newbie'"), 0);
$check('用户名被抢：回注册页带回了资料', ($r['flash']['old'] ?? [])['username'] ?? '', 'stolen');

// =====================================================================
echo "\n==== 7) 重新发送 ====\n";
// =====================================================================
$c = nextCtx();
freshDb($c['email']);
seedPending(['email' => $c['email'], 'last_sent_at' => date('Y-m-d H:i:s')]);
$r = runChild('resend_cooldown', ['email' => $c['email']], $c['ip']);
$check('冷却中：拒绝重发', str_contains($r['flash']['error'] ?? '', '刚刚已经发过一封'), true);
$check('冷却中：不发信', count($r['emails']), 0);

$c = nextCtx();
freshDb($c['email']);
seedPending(['email' => $c['email'], 'last_sent_at' => date('Y-m-d H:i:s', time() - 300), 'attempts' => 3]);
$r = runChild('resend_ok', ['email' => $c['email']], $c['ip']);
$check('冷却已过：发信 1 封', count($r['emails']), 1);
$newCode = codeFromMail($r['emails'][0] ?? ['html' => '']);
$check('新码换了', $newCode !== '' && $newCode !== '123456', true);
$check('code_hash 换成新码', one('SELECT code_hash FROM pending_registrations'), hash('sha512', $newCode));
$check('重发后计数归零', (int)one('SELECT attempts FROM pending_registrations'), 0);
$check('有效期被续上', (int)one("SELECT COUNT(*) FROM pending_registrations WHERE expires_at > datetime('now','localtime')"), 1);

// =====================================================================
echo "\n==== 8) 页面 ====\n";
// =====================================================================
$c = nextCtx();
freshDb();
$r = runChild('view_missing', ['e' => 'nobody@caellab-test.com'], $c['ip']);
$check('无待注册行：提示失效', str_contains($r['flash']['error'] ?? '', '待验证的注册已失效'), true);

$c = nextCtx();
freshDb($c['email']);
seedPending(['email' => $c['email']]);
$r = runChild('view_ok', ['e' => $c['email']], $c['ip']);
// 2026-11-04 起是 Vue 壳：可见内容都在 props JSON / 组件里，页面本身只剩挂载点
$check('有 Vue 挂载点', str_contains($r['html'], 'id="register-verify-app"'), true);
$check('props JSON 存在', preg_match('/<script type="application\/json" id="register-verify-props">(.*?)<\/script>/s', $r['html'], $propsMatch), 1);
$props = json_decode($propsMatch[1] ?? '', true);
$check('props 能解析', is_array($props), true);
$check('邮箱打码显示（进 props）', str_contains($r['html'], \Nomen\Src\RecoveryHelper::maskEmail($c['email'])), true);
// 完整邮箱在 props JSON 里（旧版隐藏域的等价物，重发表单还要用它），只查可见文字
$check('页面上没出现完整邮箱（只看可见文字）', str_contains(preg_replace(['/<script\b[^>]*>.*?<\/script>/s', '/<input[^>]*>/'], '', $r['html']), $c['email']), false);
$check('带了冷却倒计时初值', ($props['resendAfter'] ?? -1) === 0, true);
$check('Vue 入口脚本已挂', preg_match('#/assets/dist/js/registerVerify-[A-Za-z0-9_-]+\.js#', $r['html']), 1);
$verifySrc = (string)file_get_contents(PROJ . '/frontend/src/pages/register/RegisterVerifyApp.vue');
$check('表单打到验证接口（组件里）', str_contains($verifySrc, 'action="/register/verify"'), true);
$check('有重发按钮（组件里）', str_contains($verifySrc, '/register/verify/resend'), true);

// =====================================================================
echo "\n==== 9) 源码守卫（防以后手滑把已验证账号也删了） ====\n";
// =====================================================================
$src = (string)file_get_contents(PROJ . '/controllers/AuthController.php');
$check('删除语句自带 email_verified = 0', str_contains($src, 'DELETE FROM users WHERE id = :id AND email_verified = 0 AND role <> :admin'), true);
$check('AuthController 里只有这一条删用户语句', substr_count($src, 'DELETE FROM users'), 1);
$check('删之前会看 email_verified', str_contains($src, "!empty(\$owner['email_verified'])"), true);
$check('入口也会看邮箱主是不是管理员', str_contains($src, "(\$emailOwner['role'] ?? 'user') !== 'admin'"), true);
$check('待注册表只存哈希后的密码', str_contains($src, 'password_hash, country') && substr_count($src, '$password . ($config') === 1, true);
$check('管理后台动作名已登记', str_contains((string)file_get_contents(PROJ . '/src/ActivityLog.php'), "'account.reclaim'"), true);

echo "\n" . ($fails === 0 ? "全部通过。\n" : "失败 {$fails} 项。\n");
exit($fails === 0 ? 0 : 1);
}

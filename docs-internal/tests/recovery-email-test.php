<?php
/**
 * 恢复流程的邮件矩阵测试。跑法：
 *
 *     php docs-internal/tests/recovery-email-test.php
 *
 * 覆盖（2026-10-09 用户反馈的三个点）：
 *   1. 完成安全修复只发一封（之前发了两封，重复）
 *   2. 审核结果（通过/拒绝）连同恢复链接**只发联系邮箱**，不抄送账号主邮箱
 *   3. 申请阶段两边都发，但两个地址相同时去重只发一封
 *
 * Email::send 被 _email-stub.php 换成记录桩，不真发信；数据库用 SQLite 内存库顶掉。
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

define('PROJ', dirname(__DIR__, 2));

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

use Nomen\Src\Email;

// SQLite 顶 MySQL；只用到 users / account_recovery_requests
class EmailTestPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_ireplace('FOR UPDATE', '', $query);
        $query = preg_replace('/\bNOW\(\)/i', "datetime('now')", $query);
        $query = preg_replace('/\bUNIX_TIMESTAMP\(\s*\)/i', "strftime('%s','now')", $query);
        $query = preg_replace('/\bUNIX_TIMESTAMP\(((?:[^()]|\([^()]*\))*)\)/i', "strftime('%s', $1)", $query);
        return parent::prepare($query, $options);
    }
}

$pdo = new EmailTestPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, email_verified INTEGER)');
$pdo->exec('CREATE TABLE account_recovery_requests (
    id INTEGER PRIMARY KEY, user_id INTEGER, username TEXT, account_email TEXT,
    birth_date_input TEXT, gender_input TEXT, birth_date_match INTEGER, gender_match INTEGER,
    description TEXT, contact_email TEXT, status TEXT, admin_note TEXT, reviewed_by INTEGER,
    reviewed_at TEXT, recovery_token_hash TEXT, recovery_token_expires_at TEXT,
    recovery_token_used_at TEXT, completed_at TEXT, admin_email_notify_at TEXT,
    ip_address TEXT, user_agent TEXT, created_at TEXT)');
// 注意：刻意不放任何 pending 工单，这样 shouldNotifyAdminNow() 会返回 false、不会掺进管理员提醒
$pdo->exec("INSERT INTO users (id, username, email, email_verified) VALUES (4, 'yunyun', 'acct@x.com', 1)");

$p = (new ReflectionClass(\Nomen\Src\Database::class))->getProperty('instance');
$p->setAccessible(true);
$p->setValue(null, $pdo);

$fails = 0;
$check = function (string $label, $got, $want) use (&$fails) {
    $ok = $got === $want;
    if (!$ok) { $fails++; }
    printf("[%s] %-50s got=%s\n", $ok ? 'OK ' : 'FAIL', $label, var_export($got, true));
};

function controller()
{
    $rc = (new ReflectionClass(\Nomen\Controllers\RecoveryController::class))->newInstanceWithoutConstructor();
    $dbp = new ReflectionProperty(\Nomen\Controllers\RecoveryController::class, 'db');
    $dbp->setAccessible(true);
    $prop = (new ReflectionClass(\Nomen\Src\Database::class))->getProperty('instance');
    $prop->setAccessible(true);
    $dbp->setValue($rc, $prop->getValue());
    return $rc;
}

$adminNotify = (string)((require PROJ . '/config/app.php')['admin_notify_email'] ?? '');

// =====================================================================
echo "==== 1) 申请阶段：两边都发，同址去重 ====\n";
// =====================================================================
$rc = controller();
$call = function (array $ctx, string $contact) use ($rc) {
    $m = new ReflectionMethod($rc, 'sendSubmittedEmails');
    $m->setAccessible(true);
    Email::reset();
    $m->invoke($rc, $ctx, $contact);
    return Email::$sent;
};

$ctxDiff = ['user_id' => 4, 'username' => 'yunyun', 'email' => 'acct@x.com', 'email_verified' => 1];
$sent = $call($ctxDiff, 'temp@y.com');
$check('账号邮箱≠联系邮箱：共 2 封', count($sent), 2);
$check('收件人集合 = 联系邮箱 + 账号邮箱',
    Email::addresses() === ['temp@y.com', 'acct@x.com'], true);
$check('给联系邮箱的是「已收到申诉」', str_contains(Email::to('temp@y.com')[0]['html'], '已收到您的账号恢复申诉'), true);
$check('给账号邮箱的是安全提醒', str_contains(Email::to('acct@x.com')[0]['html'], '账号恢复申诉提醒'), true);
$check('不含管理员那封（无 pending 工单）', count(Email::to($adminNotify)), 0);

$sent = $call(['user_id' => 4, 'username' => 'yunyun', 'email' => 'same@z.com', 'email_verified' => 1], 'same@z.com');
$check('两个地址相同：去重后只 1 封', count($sent), 1);
$check('那一封发到同一个地址', Email::addresses(), ['same@z.com']);

$sent = $call(['user_id' => 4, 'username' => 'yunyun', 'email' => 'unverified@x.com', 'email_verified' => 0], 'temp@y.com');
$check('账号邮箱未验证：只发联系邮箱 1 封（未验证邮箱不发信）', count($sent), 1);
$check('没往未验证的账号邮箱发', Email::to('unverified@x.com'), []);

// =====================================================================
echo "\n==== 2) 审核结果：只发联系邮箱，不抄送主邮箱 ====\n";
// =====================================================================
$ac = (new ReflectionClass(\Nomen\Controllers\AdminController::class))->newInstanceWithoutConstructor();
$dbp = new ReflectionProperty(\Nomen\Controllers\AdminController::class, 'db');
$dbp->setAccessible(true);
$dbp->setValue($ac, $pdo);
$outcome = new ReflectionMethod($ac, 'sendOutcomeEmail');
$outcome->setAccessible(true);

$request = [
    'id' => 2, 'username' => 'ZaneSplash72',
    'contact_email' => 'temp@y.com', 'account_email' => 'acct@x.com',
];

Email::reset();
$outcome->invoke($ac, $request, 'approve', 'TOKEN_ABC');
$sent = Email::$sent;
$check('通过：只发 1 封', count($sent), 1);
$check('通过：只发给联系邮箱', Email::addresses(), ['temp@y.com']);
$check('通过：没抄送账号主邮箱', Email::to('acct@x.com'), []);
$check('通过：正文含一次性恢复链接', str_contains($sent[0]['html'], '/recovery/ticket?token=TOKEN_ABC'), true);
$check('通过：含「7 天内」与「仅使用 1 次」',
    str_contains($sent[0]['html'], '天内点击下方链接') && str_contains($sent[0]['html'], '仅能使用 1 次'), true);
$check('通过：已删掉「如果这不是您本人提交」那句',
    str_contains($sent[0]['html'], '如果这不是您本人提交'), false);
$check('通过：保留「您之所以会收到此邮件」免责声明',
    str_contains($sent[0]['html'], '您之所以会收到此邮件'), true);

Email::reset();
$outcome->invoke($ac, $request, 'reject');
$sent = Email::$sent;
$check('拒绝：只发 1 封', count($sent), 1);
$check('拒绝：只发给联系邮箱', Email::addresses(), ['temp@y.com']);
$check('拒绝：不含恢复链接', str_contains($sent[0]['html'], '/recovery/ticket'), false);
$check('拒绝：保留免责声明', str_contains($sent[0]['html'], '您之所以会收到此邮件'), true);

// =====================================================================
echo "\n==== 3) 完成安全修复：只发一封 ====\n";
// =====================================================================
$rc = controller();
$done = new ReflectionMethod($rc, 'sendCompletedEmail');
$done->setAccessible(true);

Email::reset();
$done->invoke($rc, 4);
$sent = Email::$sent;
$check('完成：只发 1 封', count($sent), 1);
$check('完成：发给账号邮箱（本人）', Email::addresses(), ['acct@x.com']);
$check('完成：正文讲清了撤销授权与踢下线',
    str_contains($sent[0]['html'], '第三方应用授权') && str_contains($sent[0]['html'], '登录状态'), true);

// =====================================================================
echo "\n==== 4) 源码守卫（防止又把重复的那封加回来）====\n";
// =====================================================================
$src = file_get_contents(PROJ . '/controllers/RecoveryController.php');
// 不变量（比去抠 finish() 的函数体更稳）：
//   sendCompletedEmail 只该被调用 1 次（定义处是 private function，不匹配 $this->）
//   notifyAccountEmail 只该被调用 1 次（申请阶段给账号邮箱发安全提醒那处）
$check('sendCompletedEmail 只有 1 处调用（不再重复发完成邮件）',
    substr_count($src, '$this->sendCompletedEmail'), 1);
$check('notifyAccountEmail 只有 1 处调用（只剩申请阶段那封安全提醒）',
    substr_count($src, '$this->notifyAccountEmail'), 1);
// 新工单提醒的收件人是管理员信箱，不是对外的 support_email
$check('管理员提醒读的是 admin_notify_email',
    substr_count($src, "self::config()['admin_notify_email']"), 1);
$check('管理员提醒没有再用 support_email 当收件人',
    str_contains($src, 'Email::send($support'), false);

$admin = file_get_contents(PROJ . '/controllers/AdminController.php');
$check('sendOutcomeEmail 不再拼 contact+account 两个收件人',
    str_contains($admin, '$to = (string)($request[\'contact_email\'] ?? \'\');'), true);

echo $fails === 0 ? "\nALL PASS\n" : "\n{$fails} FAILED\n";
exit($fails === 0 ? 0 : 1);

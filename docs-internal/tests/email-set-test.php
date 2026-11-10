<?php
/**
 * 邮箱域名黑名单 + 一个账号多个邮箱（user_emails）的测试。跑法：
 *
 *     php docs-internal/tests/email-set-test.php
 *
 * 覆盖：
 *   1. 保留域黑名单：精确域、子域、保留后缀都拦，正常域名放行，文案统一
 *   2. EmailSet 的每条规则：上限 5、未验证不占位、验证才转正、设首选、移除的两条底线
 *   3. users.email 镜像：同步、设首选、删首选时顺位提升
 *   4. 收码地址的优先级与家长邮箱回退
 *   5. 真跑 DashboardController 的邮箱端点（黑名单被拒 / 重复被拒 / 密码错 / 添加成功 / 设首选 / 移除）
 *   6. 源码守卫：注册、家庭、恢复三处都接了黑名单；登录标识查询用两个占位符
 *
 * 会 exit 的控制器方法（Response::json / redirect）在子进程里跑，父进程只读结果 JSON + 同一个 SQLite 文件。
 */
namespace {
error_reporting(E_ALL);
ini_set('display_errors', '1');
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
$_SESSION = [];

define('PROJ', dirname(__DIR__, 2));
define('TEST_DB', sys_get_temp_dir() . '/nomen-email-set-test.sqlite');
define('TEST_OUT', sys_get_temp_dir() . '/nomen-email-set-test-out.json');

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

/** SQLite 顶 MySQL：NOW() 换 localtime、去掉 FOR UPDATE、把 upsert 换成 SQLite 写法 */
class EmailSetTestPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_ireplace('FOR UPDATE', '', $query);
        $query = str_ireplace(
            'ON DUPLICATE KEY UPDATE is_primary = 1',
            'ON CONFLICT(user_id, email) DO UPDATE SET is_primary = 1',
            $query
        );
        $query = preg_replace('/\bNOW\(\)/i', "datetime('now','localtime')", $query);
        return parent::prepare($query, $options);
    }
}

function db(): PDO
{
    $pdo = new EmailSetTestPdo('sqlite:' . TEST_DB);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function bindDb(): void
{
    $prop = (new ReflectionClass(\Nomen\Src\Database::class))->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, db());
}

function releaseDb(): void
{
    $prop = (new ReflectionClass(\Nomen\Src\Database::class))->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
}

function resetContactCache(): void
{
    $prop = (new ReflectionClass(\Nomen\Src\FamilyHelper::class))->getProperty('contactCache');
    $prop->setAccessible(true);
    $prop->setValue(null, []);
}

function freshDb(): void
{
    releaseDb();
    foreach ([TEST_DB, TEST_DB . '-wal', TEST_DB . '-shm'] as $f) {
        if (is_file($f)) { unlink($f); }
    }
    $pdo = db();
    $pdo->exec('CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, email TEXT UNIQUE, password_hash TEXT,
        display_name TEXT, avatar TEXT, bio TEXT, country TEXT, birth_date TEXT, gender TEXT, locale TEXT, skin TEXT,
        email_verified INTEGER NOT NULL DEFAULT 0, status INTEGER NOT NULL DEFAULT 1,
        role TEXT NOT NULL DEFAULT "user", two_factor_policy TEXT DEFAULT "new_device",
        created_at TEXT)');
    $pdo->exec('CREATE TABLE user_emails (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, email TEXT NOT NULL,
        is_primary INTEGER NOT NULL DEFAULT 0, verified INTEGER NOT NULL DEFAULT 0,
        verify_token TEXT, verify_token_expires_at TEXT, created_at TEXT, verified_at TEXT,
        UNIQUE (user_id, email))');
    $pdo->exec('CREATE TABLE family_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT NOT NULL DEFAULT "unclaimed",
        created_at TEXT, claimed_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE family_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INTEGER NOT NULL, kind TEXT NOT NULL,
        user_id INTEGER, email TEXT, relationship TEXT, status TEXT NOT NULL,
        consent_at TEXT, release_requested_at TEXT, independent_at TEXT, independent_reason TEXT,
        left_at TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE activity_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, actor_type TEXT, actor_id INTEGER, actor_username TEXT,
        action TEXT, category TEXT, target_type TEXT, target_id TEXT, target_name TEXT,
        summary TEXT, changes TEXT, ip_address TEXT, user_agent TEXT, device_name TEXT, created_at TEXT)');
    $pdo->exec('CREATE TABLE notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, category TEXT, title TEXT, body TEXT,
        link TEXT, link_text TEXT, read_at TEXT, email_sent_at TEXT, dedupe_key TEXT, created_at TEXT)');
    $pdo->exec('CREATE TABLE user_notification_settings (
        user_id INTEGER PRIMARY KEY, notify_system_important INTEGER DEFAULT 1,
        notify_system_general INTEGER DEFAULT 0, notify_system_interact INTEGER DEFAULT 0)');
    bindDb();
    resetContactCache();
}

function one(string $sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : reset($row);
}

function execSql(string $sql, array $params = []): void
{
    db()->prepare($sql)->execute($params);
}

function addUser(array $o): int
{
    if (!isset($GLOBALS['UID_BASE'])) {
        $GLOBALS['UID_BASE'] = random_int(200000, 800000);
    }
    $row = array_merge([
        'id' => ++$GLOBALS['UID_BASE'],
        'username' => 'u' . random_int(1000, 9999),
        'email' => null,
        'password_hash' => 'x',
        'country' => 'CN',
        'birth_date' => '1990-01-01',
        'gender' => 'male',
        'email_verified' => 0,
        'created_at' => date('Y-m-d H:i:s', time() - 400 * 86400),
    ], $o);
    db()->prepare('INSERT INTO users (id, username, email, password_hash, country, birth_date, gender, email_verified, created_at)
             VALUES (:id, :username, :email, :password_hash, :country, :birth_date, :gender, :email_verified, :created_at)')->execute($row);
    return (int)$row['id'];
}

function pepper(): string
{
    $config = require PROJ . '/config/app.php';
    return (string)($config['pepper'] ?? '');
}

function setPassword(int $userId, string $plain): void
{
    execSql('UPDATE users SET password_hash = :p WHERE id = :id', [
        ':p'  => password_hash($plain . pepper(), PASSWORD_ARGON2ID),
        ':id' => $userId,
    ]);
}

// =====================================================================
// 子进程入口
// =====================================================================
function childMain(string $case, array $args, string $ip): void
{
    ob_start();
    $result = ['out' => '', 'emails' => [], 'flash' => [], 'error' => null];
    register_shutdown_function(function () use (&$result) {
        $result['out']    = (string)ob_get_clean();
        $result['emails'] = \Nomen\Src\Email::$sent;
        $result['flash']  = $_SESSION['_flash'] ?? [];
        file_put_contents(TEST_OUT, json_encode($result, JSON_UNESCAPED_UNICODE));
    });

    bindDb();
    $_SERVER['REMOTE_ADDR'] = $ip;
    $_SESSION['user_id'] = (int)($args['session']['user_id'] ?? 0);
    $_SESSION['csrf_token'] = 'testtoken';
    $_POST = ['_csrf' => 'testtoken'] + ($args['post'] ?? []);

    try {
        $class = ($args['controller'] ?? 'dashboard') === 'auth'
            ? \Nomen\Controllers\AuthController::class
            : \Nomen\Controllers\DashboardController::class;
        $c = new $class();
        $c->{$args['method']}();
    } catch (\Throwable $e) {
        $result['error'] = get_class($e) . ': ' . $e->getMessage();
    }
    exit(0);
}

if (($argv[1] ?? '') === 'case') {
    childMain((string)($argv[2] ?? ''), json_decode(base64_decode((string)($argv[3] ?? '')), true) ?: [], (string)($argv[4] ?? '203.0.113.7'));
}

// =====================================================================
// 父进程
// =====================================================================

use Nomen\Src\EmailPolicy;
use Nomen\Src\EmailSet;
use Nomen\Src\FamilyHelper;

$fails = 0;
$check = function (string $label, $got, $want) use (&$fails) {
    if ($got === $want) {
        printf("[OK  ] %-48s %s\n", $label, var_export($got, true));
    } else {
        $fails++;
        printf("[FAIL] %-48s got=%s want=%s\n", $label, var_export($got, true), var_export($want, true));
    }
};

function runChild(string $case, array $args = []): array
{
    static $n = 0;
    $n++;
    $ip = '198.51.100.' . ((random_int(1, 200) + $n) % 250 + 1);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
        . ' case ' . escapeshellarg($case) . ' ' . escapeshellarg(base64_encode(json_encode($args)))
        . ' ' . escapeshellarg($ip);
    @unlink(TEST_OUT);
    $raw = (string)shell_exec($cmd . ' 2>&1');
    $json = is_file(TEST_OUT) ? json_decode((string)file_get_contents(TEST_OUT), true) : null;
    if (!is_array($json)) {
        return ['out' => '', 'emails' => [], 'error' => 'no-result: ' . trim($raw)];
    }
    return $json;
}

function childJson(array $result): array
{
    $data = json_decode((string)($result['out'] ?? ''), true);
    return is_array($data) ? $data : ['success' => null, 'message' => '', 'raw' => $result['out'] ?? '', 'error' => $result['error'] ?? ''];
}

bindDb();
$_SESSION = ['csrf_token' => 'testtoken'];

// =====================================================================
echo "==== 1) 保留域黑名单 ====\n";
// =====================================================================
$check('example.com 被拦', EmailPolicy::isBlocked('a@example.com'), true);
$check('大小写不影响', EmailPolicy::isBlocked('A@EXAMPLE.COM'), true);
$check('子域也算（mail.example.com）', EmailPolicy::isBlocked('a@mail.example.com'), true);
$check('example.net / example.org 一样', [EmailPolicy::isBlocked('a@example.net'), EmailPolicy::isBlocked('a@example.org')], [true, true]);
$check('.test 后缀', EmailPolicy::isBlocked('a@foo.test'), true);
$check('.invalid 后缀', EmailPolicy::isBlocked('a@foo.invalid'), true);
$check('裸 localhost', EmailPolicy::isBlocked('a@localhost'), true);
$check('.local 后缀（mDNS）', EmailPolicy::isBlocked('a@box.local'), true);
$check('.onion 后缀', EmailPolicy::isBlocked('a@something.onion'), true);

$check('正常域名放行', EmailPolicy::isBlocked('a@gmail.com'), false);
$check('只是名字里带 example 的域名放行', EmailPolicy::isBlocked('a@examplex.com'), false);
$check('test.com 不是 .test 后缀', EmailPolicy::isBlocked('a@test.com'), false);
$check('caellab.com 放行', EmailPolicy::isBlocked('yunyun@caellab.com'), false);
$check('空串不拦（由格式校验负责）', EmailPolicy::isBlocked(''), false);
$check('被拦时只有一句话文案', EmailPolicy::rejectReason('a@example.com'), EmailPolicy::REJECT_MESSAGE);
$check('放行时没有文案', EmailPolicy::rejectReason('a@gmail.com'), null);

// =====================================================================
echo "\n==== 2) EmailSet：添加 / 验证 / 上限 ====\n";
// =====================================================================
freshDb();
$uid = addUser(['username' => 'owner', 'email' => null]);

$added = EmailSet::add($uid, 'First@Mail.COM');
$check('添加后返回行 id', is_int($added['id'] ?? null) && $added['id'] > 0, true);
$check('地址归一成小写', one('SELECT email FROM user_emails WHERE id = :i', [':i' => $added['id']]), 'first@mail.com');
$check('新加的邮箱是未验证', (int)one('SELECT verified FROM user_emails WHERE id = :i', [':i' => $added['id']]), 0);
$check('还没验证时不占首选', (int)one('SELECT is_primary FROM user_emails WHERE id = :i', [':i' => $added['id']]), 0);
$check('添加不动 users.email', one('SELECT email FROM users WHERE id = :i', [':i' => $uid]), null);

$other = addUser(['username' => 'other', 'email' => null]);
$added2 = EmailSet::add($other, 'first@mail.com');
$check('同一地址两个人可以各留一条未验证行', is_int($added2['id'] ?? null), true);
$check('未验证的行不占备选位（别人还能用）', EmailSet::secondaryTakenByOther('first@mail.com', $other), false);

$check('验证通过', EmailSet::markVerified($uid, (int)$added['id']), true);
$check('验证后标记成 verified', (int)one('SELECT verified FROM user_emails WHERE id = :i', [':i' => $added['id']]), 1);
$check('账号没有首选时第一个验证通过的自动成首选', (int)one('SELECT is_primary FROM user_emails WHERE id = :i', [':i' => $added['id']]), 1);
$check('users.email 跟着镜像过去', one('SELECT email FROM users WHERE id = :i', [':i' => $uid]), 'first@mail.com');
$check('users.email_verified 也置上', (int)one('SELECT email_verified FROM users WHERE id = :i', [':i' => $uid]), 1);
$check('它现在占着主邮箱位', EmailSet::primaryTakenByOther('first@mail.com', $other), true);
$check('但它不占备选位（主备是两个独立的位）', EmailSet::secondaryTakenByOther('first@mail.com', $other), false);
$check('重复验证是幂等的', EmailSet::markVerified($uid, (int)$added['id']), true);

$check('条数上限 5', EmailSet::MAX, 5);
for ($i = 2; $i <= 5; $i++) {
    EmailSet::add($uid, "addr{$i}@mail.com");
}
$check('第 5 条能加进来', EmailSet::count($uid), 5);
$check('第 6 条被拒（返回 null）', EmailSet::add($uid, 'addr6@mail.com'), null);
$check('被拒之后还是 5 条', EmailSet::count($uid), 5);

// =====================================================================
echo "\n==== 3) 设首选 / 移除 ====\n";
// =====================================================================
$second = EmailSet::findRow($uid, (int)one('SELECT id FROM user_emails WHERE user_id = :u AND email = :e', [':u' => $uid, ':e' => 'addr2@mail.com']));
$check('未验证的邮箱不能设首选', EmailSet::setPrimary($uid, (int)$second['id']), 'This address isn\'t verified yet — click the link in the email first');

EmailSet::markVerified($uid, (int)$second['id']);
$check('验证过之后可以设首选', EmailSet::setPrimary($uid, (int)$second['id']), '');
$check('首选换成了 addr2', one('SELECT email FROM users WHERE id = :i', [':i' => $uid]), 'addr2@mail.com');
$check('表里只剩一个首选', (int)one('SELECT COUNT(*) FROM user_emails WHERE user_id = :u AND is_primary = 1', [':u' => $uid]), 1);
$check('旧的降成次要', (int)one('SELECT is_primary FROM user_emails WHERE id = :i', [':i' => $added['id']]), 0);
$check('原来的地址还留着', EmailSet::belongsTo($uid, 'first@mail.com'), true);

$third = EmailSet::findRow($uid, (int)one('SELECT id FROM user_emails WHERE user_id = :u AND email = :e', [':u' => $uid, ':e' => 'addr3@mail.com']));
$check('移除未验证的次要邮箱：可以', EmailSet::remove($uid, (int)$third['id']), '');
$check('移除后剩 4 条', EmailSet::count($uid), 4);

$check('移除已验证的次要邮箱：可以', EmailSet::remove($uid, (int)$added['id']), '');

$check('移除唯一已验证的邮箱：拒绝', EmailSet::remove($uid, (int)$second['id']), 'This is your only verified address — add and verify another one before removing it');
$check('被拒后那一行还在', EmailSet::findRow($uid, (int)$second['id']) !== null, true);

// 再验证一条，然后删首选：应该顺位提升到剩下的那个已验证邮箱
$fourth = EmailSet::findRow($uid, (int)one('SELECT id FROM user_emails WHERE user_id = :u AND email = :e', [':u' => $uid, ':e' => 'addr4@mail.com']));
EmailSet::markVerified($uid, (int)$fourth['id']);
$check('移除首选：顺位提升到剩下的已验证邮箱', EmailSet::remove($uid, (int)$second['id']), '');
$check('users.email 换成了 addr4', one('SELECT email FROM users WHERE id = :i', [':i' => $uid]), 'addr4@mail.com');
$check('镜像的邮箱还是已验证', (int)one('SELECT email_verified FROM users WHERE id = :i', [':i' => $uid]), 1);
$check('表里始终只有一个首选', (int)one('SELECT COUNT(*) FROM user_emails WHERE user_id = :u AND is_primary = 1', [':u' => $uid]), 1);

// 只剩一条时不能删
while (EmailSet::count($uid) > 1) {
    $rows = EmailSet::all($uid);
    EmailSet::remove($uid, (int)$rows[count($rows) - 1]['id']);
}
$check('只剩一条', EmailSet::count($uid), 1);
$check('最后一条不能移除', EmailSet::remove($uid, (int)EmailSet::all($uid)[0]['id']), 'Keep at least one address');

// =====================================================================
echo "\n==== 3b) 主邮箱位 / 备选位各自唯一（一个地址最多落 2 个账号）====\n";
// =====================================================================
freshDb();
$alice = addUser(['username' => 'alice', 'email' => null]);
$bob   = addUser(['username' => 'bob', 'email' => null]);
$carol = addUser(['username' => 'carol', 'email' => null]);

$aAdd = EmailSet::add($alice, 'shared@mail.com');
EmailSet::markVerified($alice, (int)$aAdd['id']);   // 账号还没有首选 → 自动成为主邮箱
$check('alice 拿共享地址当主邮箱', one('SELECT email FROM users WHERE id = :i', [':i' => $alice]), 'shared@mail.com');

// 别人拿它当备选：**允许**（一主一备是两个独立的位）
$bAdd = EmailSet::add($bob, 'shared@mail.com');
$check('别的账号的主邮箱可以收作自己的备选', is_int($bAdd['id'] ?? null), true);
$check('bob 验证它', EmailSet::markVerified($bob, (int)$bAdd['id']), true);
$check('备选位被 bob 占上了', EmailSet::secondaryTakenByOther('shared@mail.com', $alice), true);
$check('同时它还是 alice 的主邮箱', EmailSet::primaryTakenByOther('shared@mail.com', $bob), true);
$check('bob 没有因此获得主邮箱（备选不抢主位）', one('SELECT email FROM users WHERE id = :i', [':i' => $bob]), null);

// 第三个人想把它当备选：备选位排他
$check('第二个账号不能也拿它当备选', EmailSet::secondaryTakenByOther('shared@mail.com', $carol), true);

// 一个地址最多落 2 个账号 → 候选最多 2 个，主邮箱持有者在前
$cands = EmailSet::candidatesForEmail('shared@mail.com');
$check('候选账号 = 2 个（一主一备）', count($cands), 2);
$check('第一个是主邮箱持有者，且就是 alice', [!empty($cands[0]['as_primary']), (int)$cands[0]['id']], [true, $alice]);
$check('第二个是备选持有者 bob', [(int)$cands[1]['id'], !empty($cands[1]['as_primary'])], [$bob, false]);
$check('没人持有的地址：候选为空', EmailSet::candidatesForEmail('nobody@mail.com'), []);
$check('没人持有的地址：两个位都空着', [EmailSet::primaryTakenByOther('nobody@mail.com', $alice), EmailSet::secondaryTakenByOther('nobody@mail.com', $alice)], [false, false]);

// 换首选会把旧首选降成备选 —— 旧首选的备选位得是空的
$bobSecond = EmailSet::add($bob, 'bob2@mail.com');
EmailSet::markVerified($bob, (int)$bobSecond['id']);
$check('bob 换成 bob2 当主邮箱', EmailSet::setPrimary($bob, (int)$bobSecond['id']), '');
$check('  ^ 共享地址降成了 bob 的备选', (int)one('SELECT is_primary FROM user_emails WHERE id = :i', [':i' => $bAdd['id']]), 0);
$check('  ^ bob 的主邮箱是 bob2', one('SELECT email FROM users WHERE id = :i', [':i' => $bob]), 'bob2@mail.com');

// =====================================================================
echo "\n==== 4) users.email 镜像同步（注册 / 激活那两条路） ====\n";
// =====================================================================
freshDb();
$reg = addUser(['username' => 'fresh', 'email' => 'fresh@mail.com', 'email_verified' => 0]);
EmailSet::syncPrimaryFromUsers($reg);
$check('注册后登记成首选行', (int)one('SELECT is_primary FROM user_emails WHERE user_id = :u', [':u' => $reg]), 1);
$check('注册那行的已验证状态跟着 users 走', (int)one('SELECT verified FROM user_emails WHERE user_id = :u', [':u' => $reg]), 0);

execSql('UPDATE users SET email_verified = 1 WHERE id = :i', [':i' => $reg]);
EmailSet::syncPrimaryFromUsers($reg);
$check('激活后那一行也变已验证', (int)one('SELECT verified FROM user_emails WHERE user_id = :u', [':u' => $reg]), 1);
$check('验证时间落库了', (int)one('SELECT verified_at IS NOT NULL FROM user_emails WHERE user_id = :u', [':u' => $reg]), 1);

$hadEmail = addUser(['username' => 'hademail', 'email' => 'had@mail.com', 'email_verified' => 1]);
EmailSet::syncPrimaryFromUsers($hadEmail);
execSql('UPDATE users SET email = NULL, email_verified = 0 WHERE id = :i', [':i' => $hadEmail]);
EmailSet::syncPrimaryFromUsers($hadEmail);
$check('users 上没有邮箱了 → 首选行也清掉', EmailSet::count($hadEmail), 0);
$check('清干净之后没有首选', EmailSet::primary($hadEmail), null);

// =====================================================================
echo "\n==== 5) 收码地址 resolveRecipient ====\n";
// =====================================================================
freshDb();
$multi = addUser(['username' => 'multi', 'email' => null]);
$primary = EmailSet::add($multi, 'primary@mail.com');
EmailSet::markVerified($multi, (int)$primary['id']);
$alt = EmailSet::add($multi, 'alt@mail.com');
EmailSet::markVerified($multi, (int)$alt['id']);
$multiRow = db()->query("SELECT * FROM users WHERE id = $multi")->fetch();

$check('不指定 → 首选', EmailSet::resolveRecipient($multiRow), 'primary@mail.com');
$check('指定了别的已验证邮箱 → 用它', EmailSet::resolveRecipient($multiRow, 'alt@mail.com'), 'alt@mail.com');
$check('指定了不属于自己的地址 → 回落首选', EmailSet::resolveRecipient($multiRow, 'stranger@mail.com'), 'primary@mail.com');
$check('未验证的地址不认', EmailSet::resolveRecipient($multiRow, 'unverified@mail.com'), 'primary@mail.com');

$kid = addUser(['username' => 'kid', 'email' => null, 'birth_date' => '2015-01-01']);
$kidGroup = FamilyHelper::createUnclaimedGroup('guardian@mail.com', 'mother', $kid);
$kidRow = db()->query("SELECT * FROM users WHERE id = $kid")->fetch();
$check('孩子没有自己的邮箱 → 回退监护人邮箱', EmailSet::resolveRecipient($kidRow), 'guardian@mail.com');
$check('孤儿账号什么都没有 → null', EmailSet::resolveRecipient(['id' => 999999, 'email' => null]), null);

// =====================================================================
echo "\n==== 6) 真跑邮箱端点（DashboardController） ====\n";
// =====================================================================
freshDb();
$actor = addUser(['username' => 'actor', 'email' => 'actor@mail.com', 'email_verified' => 1]);
setPassword($actor, 'CorrectHorse1');
EmailSet::syncPrimaryFromUsers($actor);

$r = childJson(runChild('add_blocked', ['session' => ['user_id' => $actor], 'method' => 'addEmail', 'post' => ['new_email' => 'nope@example.com', 'password' => 'CorrectHorse1']]));
$check('加保留域邮箱：被拒', $r['success'], false);
$check('文案就是那一句', $r['message'], t(EmailPolicy::REJECT_MESSAGE));
$check('被拒时不建行', EmailSet::count($actor), 1);

$r = childJson(runChild('add_dup', ['session' => ['user_id' => $actor], 'method' => 'addEmail', 'post' => ['new_email' => 'actor@mail.com', 'password' => 'CorrectHorse1']]));
$check('加自己已有的邮箱：被拒', $r['message'], 'This email is already on your account');

$r = childJson(runChild('add_badpw', ['session' => ['user_id' => $actor], 'method' => 'addEmail', 'post' => ['new_email' => 'extra@mail.com', 'password' => 'wrong-password']]));
$check('密码不对：被拒', $r['message'], 'Wrong current password');
$check('密码不对时不建行', EmailSet::count($actor), 1);

$raw = runChild('add_ok', ['session' => ['user_id' => $actor], 'method' => 'addEmail', 'post' => ['new_email' => 'Extra@Mail.com', 'password' => 'CorrectHorse1']]);
$r = childJson($raw);
$check('正常添加：成功', $r['success'], true);
$check('新行是未验证的', (int)one('SELECT verified FROM user_emails WHERE user_id = :u AND email = :e', [':u' => $actor, ':e' => 'extra@mail.com']), 0);
// 发信发生在子进程里，收件人要从子进程回传的结果里取
$sentTo = array_values(array_map(static fn(array $m): string => $m['to'], $raw['emails'] ?? []));
$check('验证邮件发到了新地址', $sentTo, ['extra@mail.com']);
$check('添加不改首选', one('SELECT email FROM users WHERE id = :i', [':i' => $actor]), 'actor@mail.com');

$extraId = (int)one('SELECT id FROM user_emails WHERE user_id = :u AND email = :e', [':u' => $actor, ':e' => 'extra@mail.com']);
$r = childJson(runChild('set_unverified', ['session' => ['user_id' => $actor], 'method' => 'setPrimaryEmail', 'post' => ['email_id' => $extraId, 'password' => 'CorrectHorse1']]));
$check('把未验证的设为首选：被拒', $r['message'], 'This address isn\'t verified yet — click the link in the email first');

EmailSet::markVerified($actor, $extraId);
$r = childJson(runChild('set_ok', ['session' => ['user_id' => $actor], 'method' => 'setPrimaryEmail', 'post' => ['email_id' => $extraId, 'password' => 'CorrectHorse1']]));
$check('设首选：成功', $r['success'], true);
$check('首选换成了 extra', one('SELECT email FROM users WHERE id = :i', [':i' => $actor]), 'extra@mail.com');

$r = childJson(runChild('remove_ok', ['session' => ['user_id' => $actor], 'method' => 'removeEmail', 'post' => ['email_id' => $extraId, 'password' => 'CorrectHorse1']]));
$check('先把首选移除', $r['success'], true);
$check('剩下的被顺位提为首选', one('SELECT email FROM users WHERE id = :i', [':i' => $actor]), 'actor@mail.com');

// 再补一条未验证的，好让「条数 > 1」成立，才会走到「唯一已验证」那条判断
$spare = EmailSet::add($actor, 'spare@mail.com');
$actorEmailId = (int)one('SELECT id FROM user_emails WHERE user_id = :u AND email = :e', [':u' => $actor, ':e' => 'actor@mail.com']);
$r = childJson(runChild('remove_only_verified', ['session' => ['user_id' => $actor], 'method' => 'removeEmail', 'post' => ['email_id' => $actorEmailId, 'password' => 'CorrectHorse1']]));
$check('移除唯一已验证的邮箱：被拒', $r['message'], 'This is your only verified address — add and verify another one before removing it');
$check('被拒后那一行还在', EmailSet::findRow($actor, $actorEmailId) !== null, true);

$r = childJson(runChild('remove_spare', ['session' => ['user_id' => $actor], 'method' => 'removeEmail', 'post' => ['email_id' => $spare['id'], 'password' => 'CorrectHorse1']]));
$check('移除未验证的次要邮箱：成功', $r['success'], true);

$r = childJson(runChild('remove_last', ['session' => ['user_id' => $actor], 'method' => 'removeEmail', 'post' => ['email_id' => $actorEmailId, 'password' => 'CorrectHorse1']]));
$check('只剩一条时移除：被拒', $r['message'], 'Keep at least one address');

// =====================================================================
echo "\n==== 6b) 登录消歧：一个邮箱落在两个账号上 ====\n";
// =====================================================================
freshDb();
$primOwner = addUser(['username' => 'primowner', 'email' => 'duo@mail.com', 'email_verified' => 1]);
EmailSet::syncPrimaryFromUsers($primOwner);
$secOwner = addUser(['username' => 'secowner', 'email' => null]);
$secAdd = EmailSet::add($secOwner, 'duo@mail.com');
EmailSet::markVerified($secOwner, (int)$secAdd['id']);
$solo = addUser(['username' => 'solo', 'email' => 'solo@mail.com', 'email_verified' => 1]);
EmailSet::syncPrimaryFromUsers($solo);

$r = childJson(runChild('send_code_choice', ['controller' => 'auth', 'method' => 'sendLoginCode', 'post' => ['email' => 'duo@mail.com']]));
$check('发码时命中两个账号 → 不直接发，先让用户选', !empty($r['need_account_choice']), true);
$check('  ^ 候选是 2 个', count($r['accounts'] ?? []), 2);
$check('  ^ 第一个是主邮箱持有者 primowner', $r['accounts'][0]['username'] ?? '', 'primowner');
$check('  ^ 第二个是备选持有者 secowner', $r['accounts'][1]['username'] ?? '', 'secowner');
$check('  ^ 这一下没有真的发信出去', count($r['emails'] ?? []), 0);

$r = childJson(runChild('send_code_prim', ['controller' => 'auth', 'method' => 'sendLoginCode', 'post' => ['email' => 'duo@mail.com', 'account_id' => $primOwner]]));
$check('选了主邮箱那个账号就不再问', empty($r['need_account_choice']), true);

$r = childJson(runChild('send_code_sec', ['controller' => 'auth', 'method' => 'sendLoginCode', 'post' => ['email' => 'duo@mail.com', 'account_id' => $secOwner]]));
$check('选备选那个账号也行', empty($r['need_account_choice']), true);

$r = childJson(runChild('send_code_solo', ['controller' => 'auth', 'method' => 'sendLoginCode', 'post' => ['email' => 'solo@mail.com']]));
$check('只落一个账号的邮箱不弹选择框', empty($r['need_account_choice']), true);

// 验证码那一步：命中多个账号却不带 account_id，必须拒绝（不然「给 A 发的码」会被拿去验 B）
$r = runChild('code_needs_account', ['controller' => 'auth', 'method' => 'handleLoginWithCode', 'post' => ['email' => 'duo@mail.com', 'code' => '123456']]);
$flashError = (string)($r['flash']['error'] ?? '');
$check('输码时不带账号 → 拒绝并要求先选', str_contains($flashError, '请勾选要登录的账号'), true);

// =====================================================================
echo "\n==== 6c) 填用户名 + 账号绑了多个邮箱 → 问「发到哪个」（打码）====\n";
// =====================================================================
freshDb();
$multi = addUser(['username' => 'multi', 'email' => null]);
$m1 = EmailSet::add($multi, 'first@mail.com');
EmailSet::markVerified($multi, (int)$m1['id']);            // 自动成首选
$m2 = EmailSet::add($multi, 'second@mail.com');
EmailSet::markVerified($multi, (int)$m2['id']);
EmailSet::add($multi, 'pending@mail.com');                 // 故意不验证

$check('可挑的收信地址只列已验证的', count(EmailSet::pickableAddresses($multi)), 2);
$pick = EmailSet::pickableAddresses($multi);
$check('  ^ 首选排最前且打码了', [$pick[0]['is_primary'], $pick[0]['masked']], [true, 'f****@m*.com']);
$check('  ^ 第二个也打码', $pick[1]['masked'], 's*****@m*.com');

$r = childJson(runChild('addr_choice', ['controller' => 'auth', 'method' => 'sendLoginCode', 'post' => ['email' => 'multi']]));
$check('填用户名 + 多邮箱 → 先问发到哪个', !empty($r['need_address_choice']), true);
$check('  ^ 给了两个候选', count($r['addresses'] ?? []), 2);
$check('  ^ 候选里只有打码值', $r['addresses'][0]['masked'] ?? '', 'f****@m*.com');
$check('  ^ 响应里没有真实地址', str_contains($r['out'] ?? '', 'first@mail.com'), false);
$check('  ^ 这一下没有真的发信出去', count($r['emails'] ?? []), 0);

$r = childJson(runChild('addr_chosen', ['controller' => 'auth', 'method' => 'sendLoginCode', 'post' => ['email' => 'multi', 'send_to_id' => $m2]]));
$check('选了收信地址就不再问', empty($r['need_address_choice']), true);

$r = childJson(runChild('addr_other_row', ['controller' => 'auth', 'method' => 'sendLoginCode', 'post' => ['email' => 'multi', 'send_to_id' => 999999]]));
$check('拿不属于自己的行 id → 还是问，不放行', !empty($r['need_address_choice']), true);

// 只有一个已验证邮箱：不问，直接发
$one = addUser(['username' => 'oneaddr', 'email' => 'only@mail.com', 'email_verified' => 1]);
EmailSet::syncPrimaryFromUsers($one);
$r = childJson(runChild('addr_single', ['controller' => 'auth', 'method' => 'sendLoginCode', 'post' => ['email' => 'oneaddr']]));
$check('只绑一个邮箱时填用户名也不问', empty($r['need_address_choice']), true);

// 填邮箱那条路永远不问（填哪个发哪个）
$r = childJson(runChild('addr_by_email', ['controller' => 'auth', 'method' => 'sendLoginCode', 'post' => ['email' => 'second@mail.com']]));
$check('填邮箱时不问发到哪个', empty($r['need_address_choice']), true);

// =====================================================================
echo "\n==== 7) 源码守卫 ====\n";
// =====================================================================
$auth = (string)file_get_contents(PROJ . '/controllers/AuthController.php');
$family = (string)file_get_contents(PROJ . '/controllers/FamilyController.php');
$recovery = (string)file_get_contents(PROJ . '/controllers/RecoveryController.php');
$dash = (string)file_get_contents(PROJ . '/controllers/DashboardController.php');
$twofa = (string)file_get_contents(PROJ . '/controllers/TwoFactorController.php');

$check('注册页两处邮箱都过黑名单', substr_count($auth, 'EmailPolicy::isBlocked'), 2);
$check('注册完登记首选邮箱', str_contains($auth, 'EmailSet::syncPrimaryFromUsers($userId)'), true);
$check('家庭三个入口都拦（家长/邀请/孩子邀请）', substr_count($family, 'EmailPolicy::isBlocked'), 3);
$check('恢复的联系邮箱也拦', substr_count($recovery, 'EmailPolicy::isBlocked'), 1);
$check('登录码 / 二验码 / 重置码 都过了收件人解析', substr_count($auth . $dash . $twofa, 'EmailSet::resolveRecipient'), 3);
// 登录标识：带 @ 的邮箱走候选，**不许再出现「一条 SQL 加 LIMIT 1 静默挑一个」**
$check('登录标识走候选列表（不再静默挑一个）', str_contains($auth, 'EmailSet::candidatesForEmail'), true);
$check('  ^ 且旧的 UNION + LIMIT 1 已经去掉', str_contains($auth, 'u.email = :e1'), false);
$check('密码登录会逐个账号试密码', str_contains($auth, 'foreach ($accounts as $candidate)'), true);
$check('验证码登录会带上选中的账号', str_contains($auth, "account_id'] ?? 0"), true);
$check('删号时会清 user_emails', str_contains($auth, "'user_emails']"), true);

$routes = (string)file_get_contents(PROJ . '/public/index.php');
$emailCtl = (string)file_get_contents(PROJ . '/controllers/EmailController.php');
$check('旧的改邮箱端点已经下线', str_contains($routes, 'changeEmail') || str_contains($routes, 'verifyPendingEmail'), false);
// 验证链接可能在任何浏览器点开，必须落在**不要求登录**的控制器上。
// 放进 DashboardController 会被它的构造函数先弹去 /login（这坑踩过）。
$check('验证链接落在独立的 EmailController', str_contains($routes, "[EmailController::class, 'verify']"), true);
$check('DashboardController 里已经不再有 verifyAddedEmail', str_contains($dash, 'verifyAddedEmail'), false);
$check('EmailController 自己没有「打开就要求登录」那一下', str_contains($emailCtl, '/login?redirect_to='), false);
$check('新的邮箱端点在路由表里', str_contains($routes, "'/settings/emails/verify'") && str_contains($routes, "'/settings/emails/primary'"), true);
$check('邮箱验证链接在恢复模式的放行名单里', str_contains($routes, "str_starts_with(\$currentUri, '/settings/emails/verify')"), true);

// =====================================================================
echo "\n";
if ($fails === 0) {
    echo "全部通过。\n";
    exit(0);
}
echo "有 {$fails} 条没过。\n";
exit(1);
} // namespace

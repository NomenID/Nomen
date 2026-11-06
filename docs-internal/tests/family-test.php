<?php
/**
 * 家庭组（未成年账号 + 监护人）测试。跑法：
 *
 *     php docs-internal/tests/family-test.php
 *
 * 覆盖：
 *   1. 收信地址：自己的邮箱优先，未成年回退到监护人邮箱，孤儿账号返回 null
 *   2. 年龄与各地区的同意年龄（含未知国家码回退默认值）
 *   3. 建未认领组 / 同一个监护人邮箱的第二个孩子被挡
 *   4. 监护人点同意：成员转 active、consent_at 落库
 *   5. 登录方式计数：没有自有邮箱的未成年不能靠「家长同意」白赚一个「邮箱」
 *   6. 家长认领：接上账号、组转 active、能管到组里的孩子
 *   7. 解除监护的每个条件分支
 *   8. 满 18 岁 + 账号满 6 个月 → 登录时惰性自动解除
 *   9. 退组 → 组成员移除、组转 released
 *  10. 家长在组里新建孩子账号（真跑 FamilyController::childCreate）
 *  11. 家庭组模板三种视角都渲染得出来
 *
 * 会 exit 的控制器方法（Response::redirect）都在子进程里跑，父进程只读结果 JSON + 同一个 SQLite 文件。
 */
namespace {
error_reporting(E_ALL);
ini_set('display_errors', '1');
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';
$_SESSION = [];

// 家庭与未成年监护属于可选功能，测试按开启状态跑
putenv('FEATURE_GUARDIANS=1');
putenv('FEATURE_COUNTRY=1');

define('PROJ', dirname(__DIR__, 2));
define('TEST_DB', sys_get_temp_dir() . '/nomen-family-test.sqlite');
define('TEST_OUT', sys_get_temp_dir() . '/nomen-family-test-out.json');

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

/** SQLite 顶 MySQL：NOW() 换 localtime，去掉 FOR UPDATE，INSERT IGNORE 换 SQLite 写法 */
class FamilyTestPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_ireplace(['FOR UPDATE', 'INSERT IGNORE'], ['', 'INSERT OR IGNORE'], $query);
        $query = str_ireplace('ON DUPLICATE KEY UPDATE is_primary = 1', 'ON CONFLICT(user_id, email) DO UPDATE SET is_primary = 1', $query);
        $query = preg_replace('/\bNOW\(\)/i', "datetime('now','localtime')", $query);
        return parent::prepare($query, $options);
    }
}

function db(): PDO
{
    // 每次新建：freshDb() 会把文件删掉重建，缓存连接会指向已删除的 inode
    $pdo = new FamilyTestPdo('sqlite:' . TEST_DB);
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

/** 松开注入的连接：不松开的话 Windows 上删不掉 SQLite 文件 */
function releaseDb(): void
{
    $prop = (new ReflectionClass(\Nomen\Src\Database::class))->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
}

/** FamilyHelper 的联系邮箱是进程内缓存的，重建库时要一起清掉，否则会读到上一段的账号 */
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
        display_name TEXT, avatar TEXT, country TEXT, birth_date TEXT, gender TEXT, locale TEXT, skin TEXT,
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
    $pdo->exec('CREATE TABLE user_recovery_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, used_at TEXT)');
    $pdo->exec('CREATE TABLE user_totp_secrets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, verified INTEGER DEFAULT 0)');
    $pdo->exec('CREATE TABLE user_biometrics (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, revoked INTEGER DEFAULT 0)');
    // 换了库就把注入的连接和进程内缓存一起换上，否则会读到上一段的账号
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

/** 造一个用户，返回 id。
 *
 * id 不沿用 SQLite 的 1、2、3：线上 Redis 的限流键是按 user_id 记的
 * （family:child:{id} 一天 5 次、email:{id} 一天 5 封），固定小 id 会被上一轮跑剩下的桶挡住。
 */
function addUser(array $o): int
{
    if (!isset($GLOBALS['UID_BASE'])) {
        $GLOBALS['UID_BASE'] = random_int(100000, 900000);
    }
    $row = array_merge([
        'id' => ++$GLOBALS['UID_BASE'],
        'username' => 'u' . random_int(1000, 9999),
        'email' => null,
        'password_hash' => 'x',
        'country' => 'CN',
        'birth_date' => '2010-01-01',
        'gender' => 'male',
        'email_verified' => 1,
        'created_at' => date('Y-m-d H:i:s', time() - 400 * 86400),
    ], $o);
    $pdo = db();
    $pdo->prepare('INSERT INTO users (id, username, email, password_hash, country, birth_date, gender, email_verified, created_at)
             VALUES (:id, :username, :email, :password_hash, :country, :birth_date, :gender, :email_verified, :created_at)')->execute($row);
    return (int)$row['id'];
}

// =====================================================================
// 子进程入口
// =====================================================================
function childMain(string $case, array $args, string $ip): void
{
    ob_start();
    $result = ['emails' => [], 'flash' => [], 'error' => null];
    register_shutdown_function(function () use (&$result) {
        $result['emails'] = \Nomen\Src\Email::$sent;
        $result['flash']  = $_SESSION['_flash'] ?? [];
        ob_end_clean();
        file_put_contents(TEST_OUT, json_encode($result, JSON_UNESCAPED_UNICODE));
    });

    bindDb();
    $_SERVER['REMOTE_ADDR'] = $ip;
    $_SESSION = ['csrf_token' => 'testtoken'] + ($args['session'] ?? []);
    $_POST = ['_csrf' => 'testtoken'] + ($args['post'] ?? []);

    try {
        $c = new \Nomen\Controllers\FamilyController();
        $method = $args['method'] ?? '';
        if (($args['controller'] ?? 'family') === 'auth') {
            $c = new \Nomen\Controllers\AuthController();
        }
        $c->$method();
    } catch (\Throwable $e) {
        $result['error'] = get_class($e) . ': ' . $e->getMessage();
    }
    exit(0);
}

if (($argv[1] ?? '') === 'case') {
    childMain((string)($argv[2] ?? ''), json_decode(base64_decode((string)($argv[3] ?? '')), true) ?: [], (string)($argv[4] ?? '203.0.113.9'));
}

// =====================================================================
// 父进程
// =====================================================================

use Nomen\Src\FamilyHelper;
use Nomen\Src\RecoveryHelper;

$fails = 0;
$diag = '';
$check = function (string $label, $got, $want) use (&$fails, &$diag) {
    if ($got === $want) {
        printf("[OK  ] %-46s %s\n", $label, var_export($got, true));
    } else {
        $fails++;
        printf("[FAIL] %-46s got=%s want=%s\n", $label, var_export($got, true), var_export($want, true));
        if ($diag !== '') { echo "       ^ 子进程输出: " . trim($diag) . "\n"; }
    }
    $diag = '';
};

function runChild(string $case, array $args = []): array
{
    // 每个 case 换一个合成 IP：线上 Redis 是真在计数的，同 IP 连着跑会被限流挡住
    static $n = 0;
    $n++;
    $ip = ($n % 2 ? '198.51.100.' : '203.0.113.') . ((random_int(1, 200) + $n) % 250 + 1);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
        . ' case ' . escapeshellarg($case) . ' ' . escapeshellarg(base64_encode(json_encode($args)))
        . ' ' . escapeshellarg($ip);
    @unlink(TEST_OUT);
    $raw = (string)shell_exec($cmd . ' 2>&1');
    $json = is_file(TEST_OUT) ? json_decode((string)file_get_contents(TEST_OUT), true) : null;
    if (!is_array($json)) {
        $json = ['emails' => [], 'flash' => [], 'error' => 'no-result'];
    }
    $GLOBALS['diag'] = $raw;
    return $json;
}

$renderFamily = function (array $data): string {
    extract($data);
    ob_start();
    require __DIR__ . '/fixtures/family.php';
    return (string)ob_get_clean();
};

bindDb();
$_SESSION = ['csrf_token' => 'testtoken'];

// =====================================================================
echo "==== 1) 收信地址 ====\n";
// =====================================================================
freshDb();
$guardianEmail = 'guardian@caellab-test.com';
$childId = addUser(['username' => 'kid', 'email' => null, 'birth_date' => '2015-01-01']);
$groupId = FamilyHelper::createUnclaimedGroup('Guardian@Caellab-Test.COM', 'mother', $childId);

$childRow = db()->query("SELECT * FROM users WHERE id = $childId")->fetch();
$check('未成年：回退到监护人邮箱', FamilyHelper::contactEmail($childRow), $guardianEmail);
$check('监护人邮箱归一成小写', one("SELECT email FROM family_members WHERE kind='guardian'"), $guardianEmail);
$check('监护人关系落库', one("SELECT relationship FROM family_members WHERE kind='guardian'"), 'mother');
$check('这条监护人还没有账号', one("SELECT user_id FROM family_members WHERE kind='guardian'"), null);

$adultId = addUser(['username' => 'dad', 'email' => 'dad@caellab-test.com']);
$adultRow = db()->query("SELECT * FROM users WHERE id = $adultId")->fetch();
$check('成人：用自己的邮箱', FamilyHelper::contactEmail($adultRow), 'dad@caellab-test.com');

$orphanId = addUser(['username' => 'orphan', 'email' => null]);
$orphanRow = db()->query("SELECT * FROM users WHERE id = $orphanId")->fetch();
$check('孤儿账号（无邮箱无组）→ null', FamilyHelper::contactEmail($orphanRow), null);

$unknownRelGroup = FamilyHelper::createUnclaimedGroup('other@caellab-test.com', 'stranger', $orphanId);
$check('陌生的关系值回退成 other', one("SELECT relationship FROM family_members WHERE group_id = $unknownRelGroup AND kind = 'guardian'"), 'other');

// =====================================================================
echo "\n==== 2) 年龄与各地区的同意年龄 ====\n";
// =====================================================================
$check('中国 14 岁', FamilyHelper::consentAgeFor('CN'), 14);
$check('美国 13 岁', FamilyHelper::consentAgeFor('US'), 13);
$check('未列出的国家回退默认 16 岁', FamilyHelper::consentAgeFor('DE'), 16);
$check('未知国家码回退默认', FamilyHelper::consentAgeFor('ZZ'), 16);
$check('空国家码回退默认', FamilyHelper::consentAgeFor(null), 16);
$check('小写国家码也认', FamilyHelper::consentAgeFor('cn'), 14);
$check('年份差不多的年龄算得对', FamilyHelper::ageOf(date('Y-m-d', strtotime('-20 years'))), 20);
$check('没生日 → null', FamilyHelper::ageOf(null), null);
$check('生日格式错 → null', FamilyHelper::ageOf('not-a-date'), null);
$check('未来生日 → 0 岁', FamilyHelper::ageOf(date('Y-m-d', strtotime('+1 year'))), 0);
$check('13 岁（中国门槛 14）算未成年', FamilyHelper::isMinorRow(['birth_date' => date('Y-m-d', strtotime('-13 years')), 'country' => 'CN']), true);
$check('15 岁（德国门槛 16）算未成年', FamilyHelper::isMinorRow(['birth_date' => date('Y-m-d', strtotime('-15 years')), 'country' => 'DE']), true);
$check('17 岁不算未成年', FamilyHelper::isMinorRow(['birth_date' => date('Y-m-d', strtotime('-17 years')), 'country' => 'CN']), false);
$check('没生日按成年处理', FamilyHelper::isMinorRow(['birth_date' => null, 'country' => 'CN']), false);

// =====================================================================
echo "\n==== 3) 同一个监护人邮箱的第二个孩子 ====\n";
// =====================================================================
$found = FamilyHelper::existingGroupForGuardianEmail('GUARDIAN@caellab-test.com');
$check('大小写不同也算同一个邮箱', $found !== null && (int)$found['id'] === $groupId, true);
$check('没登记过的邮箱 → null', FamilyHelper::existingGroupForGuardianEmail('nobody@caellab-test.com'), null);

// =====================================================================
echo "\n==== 4) 家长点了确认邮件：只记时间，不改状态 ====\n";
// =====================================================================
$check('家长还没确认 → guardianConfirmed=false', FamilyHelper::guardianConfirmed($childId), false);
$check('孤儿账号没有家长确认', FamilyHelper::guardianConfirmed($orphanId), false);
FamilyHelper::recordConsent($childId);
$check('家长点了确认 → guardianConfirmed=true', FamilyHelper::guardianConfirmed($childId), true);
$check('确认时间落库', (int)one("SELECT consent_at IS NOT NULL FROM family_members WHERE kind='child' AND user_id=$childId"), 1);
$check('监护人那条也记了时间', (int)one("SELECT consent_at IS NOT NULL FROM family_members WHERE kind='guardian'"), 1);
$check('孩子仍是邀请中（家长还没加入）', one("SELECT status FROM family_members WHERE kind='child' AND user_id=$childId"), 'pending');
$check('家长那条也仍是邀请中', one("SELECT status FROM family_members WHERE kind='guardian'"), 'pending');
$check('家庭仍是邀请中', one('SELECT status FROM family_groups WHERE id=' . $groupId), 'unclaimed');

// =====================================================================
echo "\n==== 5) 登录方式计数不认「没有邮箱」 ====\n";
// =====================================================================
$methods = RecoveryHelper::methods($childId);
$check('没有自己的邮箱不算「邮箱」这种登录方式', $methods['email'], false);
$check('密码仍然算', $methods['password'], true);
execSql('UPDATE users SET email = :e WHERE id = :id', [':e' => 'kid@caellab-test.com', ':id' => $childId]);
$check('绑了自己的邮箱后算「邮箱」', RecoveryHelper::methods($childId)['email'], true);
execSql('UPDATE users SET email = NULL WHERE id = :id', [':id' => $childId]);

// =====================================================================
echo "\n==== 6) 家长接受邀请 ====\n";
// =====================================================================
$claimable = FamilyHelper::claimableGroupsForEmail($guardianEmail);
$check('有一条待接受的邀请', count($claimable), 1);
$check('邀请里带上了孩子数', (int)$claimable[0]['child_count'], 1);
$check('邀请里带上了孩子用户名', (string)$claimable[0]['child_names'], 'kid');
$check('接受了 1 个邀请', FamilyHelper::claimByGuardianEmail($guardianEmail, $adultId), 1);
$check('家长那条接上了账号', (int)one("SELECT user_id FROM family_members WHERE kind='guardian'"), $adultId);
$check('家长那条转 active', one("SELECT status FROM family_members WHERE kind='guardian'"), 'active');
$check('孩子那条也转 active（这才叫加入）', one("SELECT status FROM family_members WHERE kind='child' AND user_id=$childId"), 'active');
$check('家庭转 active', one('SELECT status FROM family_groups WHERE id=' . $groupId), 'active');
$check('加入时间落库', (int)one('SELECT claimed_at IS NOT NULL FROM family_groups WHERE id=' . $groupId), 1);
$check('没有待接受的邀请了', count(FamilyHelper::claimableGroupsForEmail($guardianEmail)), 0);
$check('家长能列出自己的家庭', count(FamilyHelper::groupsOfGuardian($adultId)), 1);
$check('家长能管到这个孩子', FamilyHelper::childOfGuardian($adultId, $childId) !== null, true);
$check('别人管不到这个孩子', FamilyHelper::childOfGuardian($orphanId, $childId), null);

// =====================================================================
echo "\n==== 7) 停止监护的每个条件 ====\n";
// =====================================================================
$childRow = db()->query("SELECT * FROM users WHERE id = $childId")->fetch();
$check('没自己的邮箱：先绑邮箱', FamilyHelper::canRelease($childRow)['reason'], 'no_own_email');

execSql('UPDATE users SET email = :e WHERE id = :id', [':e' => 'kid@caellab-test.com', ':id' => $childId]);
$childRow = db()->query("SELECT * FROM users WHERE id = $childId")->fetch();
$still = FamilyHelper::canRelease($childRow);
$check('15 岁：还没到年龄', $still['reason'], 'too_young');
// 门槛不写死数字：唯一出处是同意年龄表，问 FamilyHelper 要。
// （写死过一次 16，2026-10-11 把 CN 改成 14 之后这条就一直红着没人发现）
$check('顺带告诉他门槛是几岁（取自账号所在地区的同意年龄）', $still['need'], FamilyHelper::consentAgeFor((string)$childRow['country']));

execSql('UPDATE users SET birth_date = :bd, created_at = :ca WHERE id = :id', [
    ':bd' => date('Y-m-d', strtotime('-17 years')),
    ':ca' => date('Y-m-d H:i:s'),
    ':id' => $childId,
]);
$childRow = db()->query("SELECT * FROM users WHERE id = $childId")->fetch();
$check('账号太新：拒绝', FamilyHelper::canRelease($childRow)['reason'], 'account_too_new');

execSql('UPDATE users SET created_at = :ca WHERE id = :id', [
    ':ca' => date('Y-m-d H:i:s', time() - 200 * 86400),
    ':id' => $childId,
]);
$childRow = db()->query("SELECT * FROM users WHERE id = $childId")->fetch();
$check('条件齐了：可以解除', FamilyHelper::canRelease($childRow)['ok'], true);

execSql("UPDATE family_members SET user_id = NULL WHERE kind = 'guardian'");
execSql("UPDATE family_groups SET status = 'unclaimed', claimed_at = NULL WHERE id = $groupId");
$check('组没被认领：拒绝', FamilyHelper::canRelease($childRow)['reason'], 'group_unclaimed');
FamilyHelper::claimByGuardianEmail($guardianEmail, $adultId);

FamilyHelper::markIndependent($childId, 'self');
$check('解除后成员转 independent', one("SELECT status FROM family_members WHERE user_id=$childId"), 'independent');
$check('解除原因落库', one("SELECT independent_reason FROM family_members WHERE user_id=$childId"), 'self');
$check('再申请：已经是独立账号', FamilyHelper::canRelease($childRow)['reason'], 'already_independent');
$check('组里没有待处理的孩子 → released', one('SELECT status FROM family_groups WHERE id=' . $groupId), 'released');

// =====================================================================
echo "\n==== 8) 满 18 岁惰性自动停止监护 / 邀请作废 ====\n";
// =====================================================================
freshDb();
$g2 = 'g2@caellab-test.com';
$kid2 = addUser(['username' => 'kid2', 'email' => null, 'birth_date' => date('Y-m-d', strtotime('-19 years')), 'created_at' => date('Y-m-d H:i:s', time() - 400 * 86400)]);
FamilyHelper::createUnclaimedGroup($g2, 'father', $kid2);
FamilyHelper::recordConsent($kid2);
FamilyHelper::claimByGuardianEmail($g2, addUser(['username' => 'dad2', 'email' => $g2]));
$check('满 18 + 账号满 6 个月：自动停止监护', FamilyHelper::syncDueAutonomy($kid2), 'auto');
$check('原因记成 auto', one("SELECT independent_reason FROM family_members WHERE user_id=$kid2"), 'auto');
$check('第二次不再触发', FamilyHelper::syncDueAutonomy($kid2), null);

$g3 = 'g3@caellab-test.com';
$kid3 = addUser(['username' => 'kid3', 'email' => null, 'birth_date' => date('Y-m-d', strtotime('-17 years')), 'created_at' => date('Y-m-d H:i:s', time() - 400 * 86400)]);
$group3 = FamilyHelper::createUnclaimedGroup($g3, 'mother', $kid3);
FamilyHelper::recordConsent($kid3);
FamilyHelper::claimByGuardianEmail($g3, addUser(['username' => 'mom3', 'email' => $g3]));
$check('17 岁不自动停止', FamilyHelper::syncDueAutonomy($kid3), null);
$check('17 岁仍是已加入', one("SELECT status FROM family_members WHERE user_id=$kid3"), 'active');

$g4 = 'g4@caellab-test.com';
$kid4 = addUser(['username' => 'kid4', 'email' => null, 'birth_date' => date('Y-m-d', strtotime('-19 years')), 'created_at' => date('Y-m-d H:i:s')]);
FamilyHelper::createUnclaimedGroup($g4, 'mother', $kid4);
FamilyHelper::recordConsent($kid4);
FamilyHelper::claimByGuardianEmail($g4, addUser(['username' => 'mom4', 'email' => $g4]));
$check('刚注册的 19 岁账号不自动停止（账号未满 6 个月）', FamilyHelper::syncDueAutonomy($kid4), null);

// 没人接受的邀请，到 18 岁直接作废 —— 别留一条能对成年人行使家长权力的邀请
$g5b = 'g5b@caellab-test.com';
$kid5 = addUser(['username' => 'kid5', 'email' => null, 'birth_date' => date('Y-m-d', strtotime('-19 years')), 'created_at' => date('Y-m-d H:i:s', time() - 400 * 86400)]);
FamilyHelper::createUnclaimedGroup($g5b, 'mother', $kid5);
$check('没人接受的邀请：成年后作废', FamilyHelper::syncDueAutonomy($kid5), 'expired');
$check('邀请作废后成员转 removed', one("SELECT status FROM family_members WHERE user_id=$kid5"), 'removed');

// =====================================================================
echo "\n==== 9) 退出家庭 ====\n";
// =====================================================================
FamilyHelper::removeChildMember($kid3);
$check('退组后成员转 removed', one("SELECT status FROM family_members WHERE user_id=$kid3"), 'removed');
$check('退组时间落库', (int)one("SELECT left_at IS NOT NULL FROM family_members WHERE user_id=$kid3"), 1);
$check('组里没孩子了 → released', one('SELECT status FROM family_groups WHERE id=' . $group3), 'released');
$check('退组后不再回退监护人邮箱', FamilyHelper::guardianEmailFor($kid3), null);

// =====================================================================
echo "\n==== 10) 家长在组里新建孩子账号（真跑控制器） ====\n";
// =====================================================================
freshDb();
$parentId = addUser(['username' => 'parent', 'email' => 'parent@caellab-test.com', 'birth_date' => '1990-01-01', 'created_at' => date('Y-m-d H:i:s', time() - 100 * 86400)]);
$kidA = addUser(['username' => 'kida', 'email' => null, 'birth_date' => '2014-01-01']);
$gid = FamilyHelper::createUnclaimedGroup('parent@caellab-test.com', 'father', $kidA);
FamilyHelper::recordConsent($kidA);
FamilyHelper::claimByGuardianEmail('parent@caellab-test.com', $parentId);

$createArgs = static fn(array $over) => [
    'session' => ['user_id' => $parentId],
    'method'  => 'childCreate',
    'post'    => array_merge([
        'group_id' => $gid, 'username' => 'kidb', 'password' => 'Passw0rd1',
        'password_confirm' => 'Passw0rd1', 'birth_date' => '2016-05-05', 'gender' => 'female',
    ], $over),
];

$r = runChild('child_create', $createArgs([]));
$check('家长建号：没抛异常', $r['error'], null);
$check('家长建号：没有报错提示', $r['flash']['error'] ?? null, null);
$newKid = (int)(one("SELECT id FROM users WHERE username = 'kidb'") ?? 0);
$check('孩子账号建出来了', $newKid > 0, true);
$check('孩子账号不带自己的邮箱', one("SELECT email FROM users WHERE username = 'kidb'"), null);
$check('孩子账号直接算已验证（家长就是同意人）', (int)one("SELECT email_verified FROM users WHERE username = 'kidb'"), 1);
$check('直接进组且 active', one("SELECT status FROM family_members WHERE kind='child' AND user_id=$newKid"), 'active');
$check('同意时间直接记上', (int)one("SELECT consent_at IS NOT NULL FROM family_members WHERE user_id=$newKid"), 1);
$check('组里现在两个孩子', (int)one("SELECT COUNT(*) FROM family_members WHERE kind='child' AND group_id=$gid"), 2);
$check('家长收到「账号已创建」的提示', str_contains($r['flash']['success'] ?? '', '已创建孩子账号'), true);

$r = runChild('child_create', $createArgs(['username' => 'kidc', 'birth_date' => '2000-01-01']));
$check('成年人不让家长代建', str_contains($r['flash']['error'] ?? '', '自己注册'), true);
$check('成年人：没建号', one("SELECT id FROM users WHERE username = 'kidc'"), null);

$r = runChild('child_create', $createArgs(['username' => 'kida']));
$check('用户名被占：报占用', str_contains($r['flash']['error'] ?? '', '已经被占用'), true);

$r = runChild('child_create', $createArgs(['username' => 'kidd', 'password_confirm' => 'nope1234']));
$check('两次密码不一致：拒绝', str_contains($r['flash']['error'] ?? '', '不一致'), true);

$r = runChild('child_create', $createArgs(['username' => 'kide', 'birth_date' => '2016-13-45']));
$check('生日非法：拒绝', str_contains($r['flash']['error'] ?? '', '出生日期'), true);

$r = runChild('child_create', ['session' => ['user_id' => $kidA], 'method' => 'childCreate', 'post' => ['group_id' => $gid, 'username' => 'kidf', 'password' => 'Passw0rd1', 'password_confirm' => 'Passw0rd1', 'birth_date' => '2016-05-05', 'gender' => 'male']]);
$check('孩子自己不能建号（不是监护人）', str_contains($r['flash']['error'] ?? '', '没有权限'), true);

// =====================================================================
echo "\n==== 11) 家庭页三种视角 ====\n";
// =====================================================================
$base = [
    'releaseReasons' => FamilyHelper::RELEASE_REASONS,
    'consentAge'     => FamilyHelper::consentAgeFor('CN'),
    'minDays'        => FamilyHelper::MIN_ACCOUNT_DAYS,
    'relationships'  => FamilyHelper::RELATIONSHIPS,
    'claimPending'   => '',
    'resetChild'     => 0,
    'invitations'    => [],
    'error'          => null,
    'success'        => null,
];

// 2026-11-05 起是 Vue 壳：页面只输出挂载点 + props JSON，可见文案都在 FamilyApp.vue 里
$familySrc = (string)file_get_contents(PROJ . '/frontend/src/pages/family/FamilyApp.vue');

// 孩子视角：已经加入家庭
$html = $renderFamily(array_merge($base, [
    'user' => ['id' => 1, 'username' => 'kid', 'email' => null, 'birth_date' => date('Y-m-d', strtotime('-13 years')), 'country' => 'CN', 'created_at' => date('Y-m-d H:i:s', time() - 10 * 86400), 'status' => 1],
    'membership' => ['id' => 1, 'group_id' => 1, 'user_id' => 1, 'status' => 'active', 'group_status' => 'active'],
    'guardians' => [['username' => 'mom', 'email' => 'g@caellab-test.com', 'relationship' => 'mother', 'user_id' => 9]],
    'groups' => [],
    'childrenByGroup' => [],
    'releaseCheck' => ['ok' => false, 'reason' => 'no_own_email'],
]));
preg_match('/<script type="application\/json" id="family-props">(.*?)<\/script>/s', $html, $famPropsMatch);
$famProps = json_decode($famPropsMatch[1] ?? '', true);
$check('孩子视角：props 能解析', is_array($famProps), true);
$check('孩子视角：membership 进 props', ($famProps['membership']['status'] ?? ''), 'active');
$check('孩子视角：家长列进 props', ($famProps['guardians'][0]['username'] ?? ''), 'mom');
$check('孩子视角：releaseCheck 进 props', ($famProps['releaseCheck']['reason'] ?? ''), 'no_own_email');
$check('孩子视角：有「你的家长」（组件）', str_contains($familySrc, '你的家长'), true);
$check('孩子视角：列出停止监护的条件（组件）', str_contains($familySrc, '绑定一个属于你自己的邮箱'), true);
$check('孩子视角：条件没齐时按钮禁用（组件）', str_contains($familySrc, '!releaseCheck.ok'), true);
$check('孩子视角：写出家长做不到的事（组件）', str_contains($familySrc, '以你的身份登录'), true);
$check('孩子视角：不出现「认领」', str_contains($html . $familySrc, '认领'), false);
$check('孩子视角：不出现「家庭组」', str_contains($html . $familySrc, '家庭组'), false);

// 家长视角：有一个家庭 + 一个发给自己的邀请
$html = $renderFamily(array_merge($base, [
    'user' => ['id' => 2, 'username' => 'parent', 'email' => 'p@caellab-test.com', 'birth_date' => '1990-01-01', 'country' => 'CN', 'created_at' => '2020-01-01 00:00:00', 'status' => 1],
    'membership' => null,
    'guardians' => [],
    'groups' => [['id' => 7, 'status' => 'active', 'guardian_email' => 'p@caellab-test.com', 'child_count' => 1, 'relationship' => 'father']],
    'childrenByGroup' => [7 => [['user_id' => 11, 'username' => 'kidb', 'status' => 'active', 'user_status' => 1, 'birth_date' => '2016-05-05', 'own_email' => null]]],
    'invitations' => [['id' => 8, 'email' => 'p@caellab-test.com', 'child_names' => 'kidc', 'child_count' => 1, 'relationship' => 'other']],
    'releaseCheck' => null,
]));
preg_match('/<script type="application\/json" id="family-props">(.*?)<\/script>/s', $html, $famPropsMatch);
$famProps = json_decode($famPropsMatch[1] ?? '', true);
$check('家长视角：邀请进了 props', ($famProps['invitations'][0]['child_names'] ?? ''), 'kidc');
$check('家长视角：孩子进了 props', ($famProps['groups'][0]['children'][0]['username'] ?? ''), 'kidb');
$check('家长视角：看到发给自己的邀请（组件）', str_contains($familySrc, '发给你的邀请'), true);
$check('家长视角：有加入按钮（组件）', str_contains($familySrc, '/family/accept'), true);
$check('家长视角：有「你的家庭」（组件）', str_contains($familySrc, '你的家庭'), true);
$check('家长视角：有锁定按钮（组件）', str_contains($familySrc, '锁定'), true);
$check('家长视角：有创建孩子账号的表单（组件）', str_contains($familySrc, '创建孩子账号'), true);
$check('家长视角：有邀请另一位家长的表单（组件）', str_contains($familySrc, '邀请另一位家长'), true);

// 既不在家庭里也没邀请的人
$html = $renderFamily(array_merge($base, [
    'user' => ['id' => 3, 'username' => 'solo', 'email' => 's@caellab-test.com', 'birth_date' => '1990-01-01', 'country' => 'CN', 'created_at' => '2020-01-01 00:00:00', 'status' => 1],
    'membership' => null,
    'guardians' => [],
    'groups' => [],
    'childrenByGroup' => [],
    'releaseCheck' => null,
]));
$check('普通人：有家长入口（组件）', str_contains($familySrc, '家长入口'), true);
$check('普通人：不会显示别人的家长（组件）', str_contains($familySrc, '你的家长'), true);

// =====================================================================
echo "\n==== 12) 未成年也能用邮箱验证码登录（填用户名）====\n";
// =====================================================================
freshDb();
$g5 = 'g5@caellab-test.com';
$kidLogin = addUser(['username' => 'kidlogin', 'email' => null, 'birth_date' => '2015-01-01']);
FamilyHelper::createUnclaimedGroup($g5, 'mother', $kidLogin);
FamilyHelper::recordConsent($kidLogin);

// 返回的是**候选数组**：邮箱可能命中 2 个账号（一主一备），所以不挑，交给调用方消歧
$resolver = (new ReflectionClass(\Nomen\Controllers\AuthController::class))->getMethod('findAccountsByIdentifier');
$resolver->setAccessible(true);
$auth = new \Nomen\Controllers\AuthController();

$check('用户名能找到账号', (int)($resolver->invoke($auth, 'kidlogin')[0]['id'] ?? 0), $kidLogin);
$check('没绑邮箱时按邮箱找 → 空', $resolver->invoke($auth, 'kidlogin@caellab-test.com'), []);
$check('空标识 → 空', $resolver->invoke($auth, '   '), []);
$check('不存在的用户名 → 空', $resolver->invoke($auth, 'nobody_here'), []);

execSql('UPDATE users SET email = :e WHERE id = :id', [':e' => 'kidlogin@caellab-test.com', ':id' => $kidLogin]);
$check('绑了自己的邮箱后也能按邮箱找', (int)($resolver->invoke($auth, 'kidlogin@caellab-test.com')[0]['id'] ?? 0), $kidLogin);
execSql('UPDATE users SET email = NULL WHERE id = :id', [':id' => $kidLogin]);

unset($auth);   // AuthController 握着 PDO，不松开就删不掉 SQLite 文件

// 真走一遍 sendLoginCode（本地没 Redis 会停在发码那一步，线上会真发信）：
// 关键是「填用户名」不再被判成找不到账号
$r = runChild('send_login_code', ['controller' => 'auth', 'method' => 'sendLoginCode',
                                  'post' => ['email' => 'kidlogin', 'redirect_to' => '/dashboard']]);
$msg = (string)($r['flash']['error'] ?? '') . (string)($r['flash']['success'] ?? '');
$check('填用户名不再报「不存在」', str_contains($msg, '不存在'), false);
$check('填用户名有实际结果', $msg !== '' || count($r['emails']) > 0, true);

// 填监护人邮箱：一个家长邮箱可能带多个孩子，得指路让 TA 填用户名
$r = runChild('send_login_code', ['controller' => 'auth', 'method' => 'sendLoginCode',
                                  'post' => ['email' => $g5, 'redirect_to' => '/dashboard']]);
$check('填监护人邮箱：指路改填用户名', str_contains((string)($r['flash']['error'] ?? ''), '用户名'), true);

// =====================================================================
echo "\n==== 13) 注册两条路 + 孩子邀请家长 ====\n";
// =====================================================================

// A) 未成年填了自己的邮箱：就是普通账号，一点家庭痕迹都不该有
freshDb();
$form = [
    'controller' => 'auth', 'method' => 'handleRegister',
    'session' => ['captcha_code' => 'abcd'],
    'post' => [
        'username' => 'kidown', 'password' => 'Passw0rd1', 'password_confirm' => 'Passw0rd1',
        'country' => 'CN', 'birth_date' => '2015-01-01', 'gender' => 'male',
        'email' => 'kidown@caellab-test.com', 'parent_email' => '',
        'agree_terms' => '1', 'agree_service' => '1', 'captcha' => 'ABCD', 'current_step' => '5',
    ],
];
$r = runChild('register', $form);
$check('填自己邮箱：没抛异常', $r['error'], null);
$check('填自己邮箱：邮箱落库了', one("SELECT email FROM users WHERE username = 'kidown'"), 'kidown@caellab-test.com');
$check('填自己邮箱：不建家庭', (int)one('SELECT COUNT(*) FROM family_groups'), 0);
$check('填自己邮箱：不建成员行', (int)one('SELECT COUNT(*) FROM family_members'), 0);

// B) 未成年没填自己的邮箱、填了家长邮箱：账号立刻可用（不卡激活闸），家庭是"邀请中"
freshDb();
$formB = $form;
$formB['post'] = array_merge($form['post'], [
    'username' => 'kidmom', 'email' => '', 'parent_email' => 'mom@caellab-test.com',
]);
$r = runChild('register', $formB);
$check('用家长邮箱：没抛异常', $r['error'], null);
$check('用家长邮箱：账号没有自己的邮箱', one("SELECT email FROM users WHERE username = 'kidmom'"), null);
$check('用家长邮箱：建了 1 个家庭', (int)one('SELECT COUNT(*) FROM family_groups'), 1);
$check('用家长邮箱：家庭是邀请中', one('SELECT status FROM family_groups'), 'unclaimed');
$check('用家长邮箱：家长那条记的是家长邮箱', one("SELECT email FROM family_members WHERE kind='guardian'"), 'mom@caellab-test.com');
$check('用家长邮箱：孩子那条是邀请中', one("SELECT status FROM family_members WHERE kind='child'"), 'pending');
$check('用家长邮箱：提示要等家长确认后才能用',
    str_contains((string)($r['flash']['success'] ?? ''), '家长点确认后账号才能开始使用'), true);

// C) 邮箱已有账号时，家长点确认链接会被自动接进家庭
freshDb();
$momId = addUser(['username' => 'momacc', 'email' => 'mom@caellab-test.com']);
$kidC = addUser(['username' => 'kidc2', 'email' => null, 'birth_date' => '2015-01-01']);
FamilyHelper::createUnclaimedGroup('mom@caellab-test.com', 'mother', $kidC);
$check('确认前：家长还没接上', one("SELECT user_id FROM family_members WHERE kind='guardian'"), null);
$check('确认时自动接进 1 个家庭', FamilyHelper::linkExistingGuardianAccounts($kidC), 1);
$check('家长那条接上了账号', (int)one("SELECT user_id FROM family_members WHERE kind='guardian'"), $momId);
$check('孩子那条跟着转 active', one("SELECT status FROM family_members WHERE kind='child'"), 'active');

// D) 孩子主动邀请家长（填自己的邮箱注册的那些孩子靠这个）
freshDb();
$kidD = addUser(['username' => 'kidd2', 'email' => 'kidd2@caellab-test.com', 'birth_date' => '2015-01-01']);
$r = runChild('invite_parent', ['controller' => 'family', 'method' => 'inviteParent',
                                'session' => ['user_id' => $kidD],
                                'post' => ['guardian_email' => 'dad@caellab-test.com', 'relationship' => 'father']]);
$check('孩子邀请家长：没抛异常', $r['error'], null);
$check('孩子邀请家长：建了家庭', (int)one('SELECT COUNT(*) FROM family_groups'), 1);
$check('孩子邀请家长：记的是家长邮箱', one("SELECT email FROM family_members WHERE kind='guardian'"), 'dad@caellab-test.com');
$check('孩子邀请家长：家长那条是邀请中', one("SELECT status FROM family_members WHERE kind='guardian'"), 'pending');
$check('孩子邀请家长：提示已发邀请', str_contains((string)($r['flash']['success'] ?? ''), '邀请'), true);

$r = runChild('invite_parent', ['controller' => 'family', 'method' => 'inviteParent',
                                'session' => ['user_id' => $kidD],
                                'post' => ['guardian_email' => 'dad@caellab-test.com', 'relationship' => 'father']]);
$check('同一条邮箱重复邀请：提示已邀请过', str_contains((string)($r['flash']['error'] ?? ''), '已经邀请过'), true);

// =====================================================================
echo "\n" . ($fails === 0 ? "全部通过。\n" : "失败 {$fails} 项。\n");
exit($fails === 0 ? 0 : 1);

} // namespace


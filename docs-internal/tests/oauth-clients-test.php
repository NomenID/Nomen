<?php
/**
 * OAuth 客户端服务测试。跑法：
 *
 *     php docs-internal/tests/oauth-clients-test.php
 *
 * 覆盖：创建、校验、更新、重置密钥、统计、删除。
 * 数据库用 SQLite 内存库顶 MySQL。
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('PROJ', dirname(__DIR__, 2));

spl_autoload_register(function (string $class) {
    $prefixes = [
        'Nomen\\Src\\'         => PROJ . '/src/',
        'Nomen\\Controllers\\' => PROJ . '/controllers/',
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

require PROJ . '/src/I18n.php';

use Nomen\Src\{Database, OAuthClients};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)');
$pdo->exec('CREATE TABLE developers (id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT, icon TEXT, status INTEGER, created_at TEXT)');
$pdo->exec('CREATE TABLE oauth_clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id TEXT NOT NULL UNIQUE,
    client_secret TEXT NOT NULL,
    client_type TEXT NOT NULL DEFAULT "confidential",
    pkce_required INTEGER NOT NULL DEFAULT 0,
    name TEXT NOT NULL,
    icon TEXT,
    description TEXT,
    homepage_url TEXT,
    redirect_uris TEXT NOT NULL,
    scopes TEXT NOT NULL,
    owner_id INTEGER,
    developer_id INTEGER,
    status INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT "",
    updated_at TEXT NOT NULL DEFAULT ""
)');
$pdo->exec('CREATE TABLE user_authorizations (user_id INTEGER, client_id TEXT)');
$pdo->exec('CREATE TABLE oauth_access_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT, client_id TEXT, user_id INTEGER, scopes TEXT, expires_at TEXT, revoked INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE oauth_refresh_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT, client_id TEXT, revoked INTEGER NOT NULL DEFAULT 0)');

$pdo->exec("INSERT INTO users (id, username) VALUES (1, 'admin')");
$pdo->exec("INSERT INTO developers (id, user_id, name, status, created_at) VALUES (1, 1, 'Test Dev', 1, '2026-11-07 00:00:00')");

$prop = (new ReflectionClass(Database::class))->getProperty('instance');
$prop->setAccessible(true);
$prop->setValue(null, $pdo);

$fails = 0;
$check = function (string $label, $got, $want) use (&$fails) {
    $ok = $got === $want;
    if (!$ok) { $fails++; }
    printf("[%s] %-45s got=%s\n", $ok ? 'OK ' : 'FAIL', $label, var_export($got, true));
};

echo "==== 创建与校验 ====\n";
$created = OAuthClients::create([
    'name' => 'Test App',
    'description' => 'A test app',
    'homepage' => 'https://example.com',
    'redirect_uris' => "https://example.com/cb\nhttps://example.com/cb2",
    'scopes' => ['openid', 'profile', 'email'],
    'client_type' => 'confidential',
], 1, 1);

$check('返回 client_id', str_starts_with($created['client_id'], 'app_'), true);
$check('返回明文 secret', strlen($created['client_secret']), 64);

$app = OAuthClients::find($created['client_id']);
$check('查得到应用', $app['name'] ?? null, 'Test App');
$check('secret 以哈希入库', strlen((string)$app['client_secret']), 128);
$check('secret 不是明文', $app['client_secret'] === $created['client_secret'], false);
$check('回调地址存成 JSON', count(json_decode((string)$app['redirect_uris'], true)), 2);
$check('scope 归一化', $app['scopes'], 'openid profile email');

try {
    OAuthClients::create([
        'name' => 'Bad',
        'redirect_uris' => 'not-a-url',
        'scopes' => ['openid'],
    ], 1, 1);
    $check('非法回调被拒', false, true);
} catch (InvalidArgumentException $e) {
    $check('非法回调被拒', str_contains($e->getMessage(), '回调 URL'), true);
}

echo "\n==== 更新与统计 ====\n";
OAuthClients::update($created['client_id'], [
    'name' => 'Test App v2',
    'redirect_uris' => 'https://example.com/cb',
    'scopes' => ['openid', 'email'],
    'client_type' => 'public',
], null);

$app = OAuthClients::find($created['client_id']);
$check('名称已更新', $app['name'] ?? null, 'Test App v2');
$check('公共客户端强制 PKCE', (int)$app['pkce_required'], 1);
$check('公共客户端类型', $app['client_type'], 'public');

$pdo->exec("INSERT INTO user_authorizations (user_id, client_id) VALUES (1, '{$created['client_id']}')");
$pdo->exec("INSERT INTO oauth_access_tokens (token_hash, client_id, user_id, scopes, expires_at, revoked) VALUES ('h', '{$created['client_id']}', 1, 'openid', '2099-01-01 00:00:00', 0)");
$stats = OAuthClients::stats($created['client_id']);
$check('授权用户数', $stats['authorized_users'], 1);
$check('活跃 Token 数', $stats['active_tokens'], 1);

echo "\n==== 重置密钥与删除 ====\n";
$oldHash = $app['client_secret'];
$newSecret = OAuthClients::resetSecret($created['client_id']);
$app = OAuthClients::find($created['client_id']);
$check('新 secret 与旧的不同', $app['client_secret'] === $oldHash, false);
$check('新 secret 是明文返回', strlen($newSecret), 64);
$check('旧 Token 全部作废', (int)$pdo->query("SELECT COUNT(*) FROM oauth_access_tokens WHERE revoked = 0")->fetchColumn(), 0);

OAuthClients::delete($created['client_id']);
$check('应用已删除', OAuthClients::find($created['client_id']), null);

echo $fails === 0 ? "\nALL PASS\n" : "\n{$fails} FAILED\n";
exit($fails === 0 ? 0 : 1);

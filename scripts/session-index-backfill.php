<?php
/**
 * 把升级前就存在的会话补进「用户 → 会话 id」索引，并打上索引就绪标记。
 *
 * 用法（项目根目录，可在服务器上直接跑）：php scripts/session-index-backfill.php
 *
 * 没跑这个脚本功能也是对的：Session::destroyOtherSessionsFor() 在索引就绪之前
 * 会退回「扫全库」那条慢路，只是慢。跑完走索引，几十次往返搞定。
 * 可重复执行：索引是集合，重复写入没有副作用。
 */

require_once __DIR__ . '/../src/RedisClient.php';
require_once __DIR__ . '/../src/Session.php';

use Nomen\Src\RedisClient;
use Nomen\Src\Session;

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value, " \t\n\r\0\x0B\"'"));
    }
}

$redis = RedisClient::get();
if ($redis === null) {
    fwrite(STDERR, "Redis 连不上，什么都没改\n");
    exit(1);
}

$prefix   = Session::sessionKeyPrefix();
$scanned  = 0;
$indexed  = 0;
$iterator = null;
$guard    = 0;

do {
    $keys = $redis->scan($iterator, $prefix . '*', 500);
    if (!is_array($keys) || !$keys) {
        continue;
    }

    // 一批一次取完，避免每个键一次往返
    $pipe = $redis->multi(Redis::PIPELINE);
    foreach ($keys as $key) {
        $pipe->get($key);
    }
    $values = $pipe->exec();

    foreach ($keys as $i => $key) {
        $scanned++;
        $data = $values[$i] ?? null;
        if (!is_string($data)) {
            continue;
        }

        // 兼容 php 与 php_serialize 两种序列化格式
        if (preg_match('/user_id\|i:(\d+);/', $data, $m)
            || preg_match('/"user_id";i:(\d+);/', $data, $m)) {
            $redis->sAdd(Session::indexKey((int)$m[1]), substr($key, strlen($prefix)));
            $indexed++;
        }
    }

    $guard++;
} while ((int)$iterator !== 0 && $guard < 100000);

Session::markIndexReady();

echo "扫描会话键 {$scanned} 个，写入索引 {$indexed} 条\n";

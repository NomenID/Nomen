<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

use Redis;

class Session
{
    private static int $defaultLifetime = 7200;
    private static int $loginTtl = 30 * 24 * 3600;
    private static int $loginMaxTtl = 90 * 24 * 3600;

    private const INDEX_PREFIX = 'user_sessions:';
    private const INDEX_READY_KEY = 'nomen:session_index_ready';
    /** 索引集合攒到这么多成员就先清一遍已失效的（正常用户远到不了） */
    private const INDEX_PRUNE_AT = 50;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $config = require __DIR__ . '/../config/app.php';
        self::$defaultLifetime = (int)($config['session_lifetime'] ?? 7200);
        self::$loginTtl = (int)($config['login_ttl'] ?? 30 * 24 * 3600);
        self::$loginMaxTtl = max(self::$loginTtl, (int)($config['login_max_ttl'] ?? 90 * 24 * 3600));

        // 服务端回收时间必须覆盖最长登录期，否则 90 天期限没到、session 先被 gc 掉
        ini_set('session.gc_maxlifetime', (string)self::$loginMaxTtl);

        self::useSharedStorage($config);

        // cookie 由 refreshCookie() 自己发：PHP 每次 session_start 都会重发一条同名的，
        // 会和这里的有效期打架（两条 Set-Cookie 谁在后看实现，不可靠）
        ini_set('session.use_cookies', '0');

        session_name($config['session_name']);
        $sid = $_COOKIE[$config['session_name']] ?? '';
        if (is_string($sid) && preg_match('/^[A-Za-z0-9,-]{22,256}$/', $sid)) {
            session_id($sid);
        }

        session_start();

        self::refreshCookie();
    }

    /**
     * 登录成功后登记会话性质：持久（默认）或本次临时
     *
     * 临时会话发的是浏览器会话 cookie（没有 expires），关掉浏览器就没了；
     * 持久会话按 login_ttl 滑动续期，从登录时刻起最长 login_max_ttl。
     */
    public static function markSignedIn(bool $temporary): void
    {
        $_SESSION['_persistent'] = !$temporary;
        $_SESSION['_login_at'] = time();

        self::sendCookie(self::cookieExpiry());
    }

    /** 按当前会话状态重发 cookie（PHP 的自动 cookie 已关掉） */
    private static function refreshCookie(): void
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);

        if ($userId <= 0) {
            self::sendCookie(time() + self::$defaultLifetime);
            return;
        }

        if (empty($_SESSION['_persistent'])) {
            self::sendCookie(0);
            return;
        }

        $loginAt = (int)($_SESSION['_login_at'] ?? 0);
        if ($loginAt <= 0) {
            // 升级前就存在的会话：没有登录时刻，按本次访问起算，别把在线用户直接踢掉
            $loginAt = time();
            $_SESSION['_login_at'] = $loginAt;
        }

        if (time() >= $loginAt + self::$loginMaxTtl) {
            // 90 天上限到了：会话作废，下次请求就是一个干净的匿名 session
            $_SESSION = [];
            self::sendCookie(time() + self::$defaultLifetime);
            return;
        }

        self::sendCookie(self::cookieExpiry());
    }

    /**
     * 当前会话该用的 cookie 到期时间戳（0 = 浏览器会话 cookie）
     */
    private static function cookieExpiry(): int
    {
        if (empty($_SESSION['user_id']) || empty($_SESSION['_persistent'])) {
            return 0;
        }

        $loginAt = (int)($_SESSION['_login_at'] ?? time());
        $sliding = time() + self::$loginTtl;
        $absolute = $loginAt + self::$loginMaxTtl;

        return min($sliding, $absolute);
    }

    private static function sendCookie(int $expires): void
    {
        if (session_id() === '') {
            return;
        }

        setcookie(session_name(), session_id(), [
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => '',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * 优先使用 Redis 存储 session，不可用时回退到文件存储
     *
     * 回退而非中断：Redis 是性能优化，不该成为单点故障。
     * 探活直接复用 RedisClient，不再为检查多开一条连接。
     *
     * 回退必须把 save_handler 显式改回 files：本机 PHP 8.3 的 php.ini 里全局写着
     * session.save_handler = redis，只记条日志就 return 的话，后面的 session_start()
     * 照样会去连那个已经挂掉的 Redis —— 「回退」会变成一句空话，表现是整站登不进去。
     */
    private static function useSharedStorage(array $config): void
    {
        $redis = $config['redis'] ?? [];
        if (empty($redis['host']) || RedisClient::get() === null) {
            error_log('[Session] Redis 不可用，回退文件存储');
            ini_set('session.save_handler', 'files');
            ini_set('session.save_path', sys_get_temp_dir());
            return;
        }

        $host = $redis['host'];
        $port = (int)($redis['port'] ?? 6379);
        $db   = (int)($redis['db'] ?? 0);
        $pass = (string)($redis['password'] ?? '');

        $params = [
            'database=' . $db,
            'timeout=2.5',
            'prefix=' . self::sessionKeyPrefix($config),
        ];
        if ($pass !== '') {
            $params[] = 'auth=' . urlencode($pass);
        }

        ini_set('session.save_handler', 'redis');
        ini_set('session.save_path', "tcp://{$host}:{$port}?" . implode('&', $params));
    }

    /**
     * Redis 里 session 键的前缀，也是索引成员对应的键名
     */
    public static function sessionKeyPrefix(?array $config = null): string
    {
        $config ??= require __DIR__ . '/../config/app.php';
        return ($config['session_name'] ?? 'NOMEN_SESSION') . ':';
    }

    public static function indexKey(int $userId): string
    {
        return self::INDEX_PREFIX . $userId;
    }

    /**
     * 把某个会话登记到「用户 → 会话 id」的索引集合上
     *
     * 索引存在的唯一理由是登出其他设备时不用扫全库：老写法是 SCAN 出所有 session 键
     * 再逐个 GET 翻序列化内容找 user_id，4 万个会话就是 4 万多次往返、同步卡在请求里。
     *
     * @param string $sessionId 传空则用当前会话
     */
    public static function indexSession(int $userId, string $sessionId = ''): void
    {
        $sessionId = $sessionId !== '' ? $sessionId : session_id();
        if ($userId <= 0 || $sessionId === '') {
            return;
        }

        $redis = RedisClient::get();
        if ($redis === null) {
            return;
        }

        try {
            $key = self::indexKey($userId);
            // 先清后加：刚建立的会话此刻还没落盘，清了再补上才不会被自己误删
            if ($redis->sCard($key) > self::INDEX_PRUNE_AT) {
                self::pruneIndex($redis, $key);
            }
            $redis->sAdd($key, $sessionId);
        } catch (\Throwable $e) {
            error_log('[Session] 写入会话索引失败: ' . $e->getMessage());
        }
    }

    public static function unindexSession(int $userId, string $sessionId = ''): void
    {
        $sessionId = $sessionId !== '' ? $sessionId : session_id();
        if ($userId <= 0 || $sessionId === '') {
            return;
        }

        $redis = RedisClient::get();
        if ($redis === null) {
            return;
        }

        try {
            $key = self::indexKey($userId);
            $redis->sRem($key, $sessionId);
            if ($redis->sCard($key) === 0) {
                $redis->del($key);
            }
        } catch (\Throwable $e) {
            error_log('[Session] 清理会话索引失败: ' . $e->getMessage());
        }
    }

    /**
     * 索引里早就不再存在的会话键（过期、被踢、登出）顺手清掉，不然成员只增不减
     */
    private static function pruneIndex(Redis $redis, string $key): void
    {
        $ids = $redis->sMembers($key);
        if (!is_array($ids) || !$ids) {
            return;
        }

        $prefix = self::sessionKeyPrefix();
        $pipe = $redis->multi(Redis::PIPELINE);
        foreach ($ids as $id) {
            $pipe->exists($prefix . $id);
        }
        $exists = $pipe->exec();
        if (!is_array($exists)) {
            return;
        }

        $dead = [];
        foreach ($ids as $index => $id) {
            if (empty($exists[$index])) {
                $dead[] = $id;
            }
        }
        if ($dead) {
            $redis->sRem($key, ...$dead);
        }
    }

    /**
     * 索引是否已经覆盖全部会话。没回填完之前不能信它，
     * 否则升级前就存在的会话会被漏掉 —— 那是安全相关的漏，宁可退回慢的扫全库。
     */
    public static function indexReady(): bool
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return false;
        }

        try {
            return (bool)$redis->exists(self::INDEX_READY_KEY);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function markIndexReady(): void
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return;
        }

        try {
            $redis->set(self::INDEX_READY_KEY, '1');
        } catch (\Throwable $e) {
            error_log('[Session] 写入索引就绪标记失败: ' . $e->getMessage());
        }
    }

    /**
     * 登出该用户的其他所有会话，保留当前会话
     *
     * 用于密码变更等场景。仅在 session 存于 Redis 时生效，
     * 文件存储下无法安全枚举其他会话，直接跳过并记日志
     */
    public static function destroyOtherSessionsFor(int $userId): int
    {
        if (session_status() !== PHP_SESSION_ACTIVE || ini_get('session.save_handler') !== 'redis') {
            error_log('[Session] 非 Redis 存储，跳过其他会话清理');
            return 0;
        }

        if (!self::indexReady()) {
            return self::destroyOtherSessionsByScan($userId);
        }

        $redis = RedisClient::get();
        if ($redis === null) {
            return 0;
        }

        $current = session_id();
        $deleted = 0;

        try {
            $key = self::indexKey($userId);
            $ids = $redis->sMembers($key);
            if (!is_array($ids)) {
                return 0;
            }

            $others = array_values(array_diff($ids, [$current]));
            if (!$others) {
                return 0;
            }

            $prefix = self::sessionKeyPrefix();
            $pipe = $redis->multi(Redis::PIPELINE);
            foreach ($others as $id) {
                $pipe->del($prefix . $id);
            }
            $results = $pipe->exec();
            if (is_array($results)) {
                $deleted = count(array_filter($results));
            }

            // 索引收敛成「只剩当前会话」，顺手把已经失效的成员清干净
            $redis->del($key);
            if ($current !== '') {
                $redis->sAdd($key, $current);
            }
        } catch (\Throwable $e) {
            error_log('[Session] 清理其他会话失败: ' . $e->getMessage());
        }

        return $deleted;
    }

    /**
     * 索引未就绪时的兜底：扫全库。慢（每个会话一次往返），但不会漏。
     */
    private static function destroyOtherSessionsByScan(int $userId): int
    {
        $config = require __DIR__ . '/../config/app.php';
        $prefix = self::sessionKeyPrefix($config);
        $currentKey = $prefix . session_id();

        $client = RedisClient::get();
        if ($client === null) {
            return 0;
        }

        $deleted = 0;

        try {
            $iterator = null;
            $guard = 0;
            do {
                $keys = $client->scan($iterator, $prefix . '*', 200);
                if (!is_array($keys)) {
                    break;
                }

                foreach ($keys as $key) {
                    if ($key === $currentKey) {
                        continue;
                    }

                    $data = $client->get($key);
                    if (!is_string($data)) {
                        continue;
                    }

                    // 兼容 php 与 php_serialize 两种序列化格式
                    if (preg_match('/user_id\|i:' . $userId . ';/', $data)
                        || preg_match('/"user_id";i:' . $userId . ';/', $data)) {
                        $client->del($key);
                        $deleted++;
                    }
                }

                $guard++;
            } while ((int)$iterator !== 0 && $guard < 1000);
        } catch (\Throwable $e) {
            error_log('[Session] 清理其他会话失败: ' . $e->getMessage());
        }

        return $deleted;
    }

    public static function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, $value): void
    {
        // user_id 是「这个会话属于谁」的唯一标记，钩子挂在这里就能保证有会话必有索引，
        // 不用指望每个登录入口都记得登记一次
        if ($key === 'user_id') {
            $previous = (int)($_SESSION['user_id'] ?? 0);
            $current  = (int)$value;
            if ($current > 0 && $current !== $previous) {
                if ($previous > 0) {
                    self::unindexSession($previous);
                }
                self::indexSession($current);
            }
        }

        $_SESSION[$key] = $value;
    }

    public static function remove(string $key): void
    {
        if ($key === 'user_id') {
            self::unindexSession((int)($_SESSION['user_id'] ?? 0));
        }

        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        self::unindexSession((int)($_SESSION['user_id'] ?? 0));

        session_destroy();
        $_SESSION = [];

        // PHP 的自动 cookie 已关，销毁时得自己把它撤掉
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => '',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function flash(string $key, $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public static function getFlash(string $key, $default = null)
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    public static function regenerate(): void
    {
        $oldId  = session_id();
        $userId = (int)($_SESSION['user_id'] ?? 0);

        session_regenerate_id(true);

        // 换了 id 就得把索引跟着改，否则索引里留的是个已经不存在的会话键
        if ($userId > 0) {
            self::unindexSession($userId, $oldId);
            self::indexSession($userId);
        }

        self::sendCookie((int)($_SESSION['user_id'] ?? 0) > 0 ? self::cookieExpiry() : time() + self::$defaultLifetime);
    }
}

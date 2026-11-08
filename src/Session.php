<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

use Redis;

class Session
{
    private static int $defaultLifetime = 7200;

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
        self::$defaultLifetime = $config['session_lifetime'] ?? 7200;

        // gc_maxlifetime 必须覆盖「记住我」的最长有效期，否则服务端会提前回收 session，
        // 长有效期 cookie 的后半段就是空壳了
        $rememberTtl = (int)($config['remember_me_ttl'] ?? 30 * 24 * 3600);
        $maxLifetime = max(self::$defaultLifetime, $rememberTtl);
        ini_set('session.gc_maxlifetime', (string)$maxLifetime);

        self::useSharedStorage($config);

        session_name($config['session_name']);
        session_set_cookie_params([
            'lifetime' => self::$defaultLifetime,
            'path'     => '/',
            'domain'   => '',
            'secure'   => true,
            'httponly'  => true,
            'samesite'  => 'Lax',
        ]);

        session_start();
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
        $db   = (int)($redis['db'] ?? 1);
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

    /**
     * 延长 session 有效期（在 session 已启动后用 setcookie 刷新）
     */
    public static function extendLifetime(int $seconds): void
    {
        $lifetime = max(self::$defaultLifetime, $seconds);

        // session_set_cookie_params / ini_set 只在 session_start 之前生效
        // 已启动的 session 需要用 setcookie 直接刷新 cookie
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            session_id(),
            [
                'expires'  => time() + $lifetime,
                'path'     => $params['path'] ?? '/',
                'domain'   => $params['domain'] ?? '',
                'secure'   => $params['secure'] ?? true,
                'httponly'  => $params['httponly'] ?? true,
                'samesite'  => $params['samesite'] ?? 'Lax',
            ]
        );
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

        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            session_id(),
            [
                'expires'  => time() + self::$defaultLifetime,
                'path'     => $params['path'] ?? '/',
                'domain'   => $params['domain'] ?? '',
                'secure'   => $params['secure'] ?? true,
                'httponly'  => $params['httponly'] ?? true,
                'samesite'  => $params['samesite'] ?? 'Lax',
            ]
        );
    }
}

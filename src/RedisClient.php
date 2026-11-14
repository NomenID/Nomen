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

/**
 * 全项目共用的 Redis 连接，每个请求懒连接一次。
 *
 * 连不上就返回 null，让调用方各自决定怎么降级（限流放行、会话索引用文件存储兜底）；
 * 首次失败后不再重试 —— 一次请求里反复去连一个已经挂掉的 Redis 只会拖慢响应。
 */
class RedisClient
{
    private static ?Redis $client = null;
    private static bool $resolved = false;

    public static function get(): ?Redis
    {
        if (self::$resolved) {
            return self::$client;
        }
        self::$resolved = true;

        if (!extension_loaded('redis')) {
            error_log('[redis] phpredis 扩展未加载');
            return null;
        }

        $config = require __DIR__ . '/../config/app.php';
        $redis = $config['redis'] ?? [];

        try {
            $client = new Redis();
            $client->connect($redis['host'] ?? '127.0.0.1', (int)($redis['port'] ?? 6379), 2.0);
            if (!empty($redis['password'])) {
                $client->auth($redis['password']);
            }
            $client->select((int)($redis['db'] ?? 0));
            self::$client = $client;
        } catch (\Throwable $e) {
            error_log('[redis] 连接失败: ' . $e->getMessage());
        }

        return self::$client;
    }
}

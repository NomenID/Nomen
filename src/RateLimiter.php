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

class RateLimiter
{
    /**
     * 滑动窗口限流。清理窗口、计数、判断、写入必须在一个脚本里完成 ——
     * 拆成「先 ZCARD 判断、再 ZADD 记录」两次往返的话，并发请求会同时读到未超限然后都放行。
     *
     * ARGV: 1 当前时间戳 2 窗口秒数 3 上限 4 本次请求的成员名
     * 返回: {是否允许, 还需等待秒数, 窗口内已计次数}
     */
    private const LUA_CHECK = <<<'LUA'
local key = KEYS[1]
local now = tonumber(ARGV[1])
local window = tonumber(ARGV[2])
local max = tonumber(ARGV[3])

redis.call('ZREMRANGEBYSCORE', key, 0, now - window)
local count = redis.call('ZCARD', key)

if count >= max then
    local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
    local retry = window
    if oldest[2] then
        retry = window - (now - tonumber(oldest[2]))
        if retry < 1 then retry = 1 end
    end
    -- 键上万一没有 TTL（老数据 / 被别的工具写过）就补一个，免得变成永久垃圾键
    if redis.call('TTL', key) < 0 then
        redis.call('EXPIRE', key, window)
    end
    return {0, retry, count}
end

-- 成员名带上随机后缀：同秒内的并发请求得各自占一个成员，
-- 光用时间戳当成员的话同秒的第二次记录会覆盖掉第一次，计数就少了
redis.call('ZADD', key, now, ARGV[4])
redis.call('EXPIRE', key, window)
return {1, 0, count + 1}
LUA;

    /**
     * 检查并记录请求
     * @return array ['allowed' => bool, 'retry_after' => int, 'remaining' => array]
     */
    public static function check(string $key, int $maxAttempts, int $windowSeconds): array
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            // Redis 不可用时放行。这是有意的取舍：限流是加固，不该在 Redis 抖动时
            // 把所有人挡在门外；代价是此时限流形同不存在，后台概览的 Redis 状态卡片会提示这一点
            return ['allowed' => true, 'retry_after' => 0, 'remaining' => []];
        }

        $now = time();
        $windowKey = "rate_limit:{$key}";
        $member = $now . '-' . bin2hex(random_bytes(6));

        try {
            $result = $redis->eval(self::LUA_CHECK, [$windowKey, $now, $windowSeconds, $maxAttempts, $member], 1);
            if (!is_array($result) || count($result) < 3) {
                throw new \RuntimeException('限流脚本返回异常');
            }

            [$allowed, $retryAfter, $count] = [(int)$result[0], (int)$result[1], (int)$result[2]];

            if ($allowed !== 1) {
                return [
                    'allowed'     => false,
                    'retry_after' => max(1, $retryAfter),
                    'remaining'   => ['total' => $maxAttempts, 'used' => $count],
                ];
            }

            return [
                'allowed'     => true,
                'retry_after' => 0,
                'remaining'   => ['total' => $maxAttempts, 'used' => $count, 'left' => max(0, $maxAttempts - $count)],
            ];
        } catch (\Throwable $e) {
            error_log('[RateLimiter] 操作失败: ' . $e->getMessage());
            return ['allowed' => true, 'retry_after' => 0, 'remaining' => []];
        }
    }

    /**
     * 只读查询：窗口内已记录的次数（不自增、不拦截）
     *
     * 用于「先判断是否需要图片验证码，再调用 check() 记录本次」这类场景。
     * Redis 不可用时返回 0，与 check() 的 fail-open 行为保持一致。
     */
    public static function count(string $key, int $windowSeconds): int
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return 0;
        }

        $now = time();
        $windowKey = "rate_limit:{$key}";

        try {
            // 先清掉滑出窗口的记录，再统计
            $redis->zRemRangeByScore($windowKey, 0, $now - $windowSeconds);
            return (int)$redis->zCard($windowKey);
        } catch (\Throwable $e) {
            error_log('[RateLimiter] count 失败: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 邮件发送频率限制
     * 每分钟 1 封，每小时 3 封，每天 5 封
     */
    public static function checkEmailRate(int $userId): array
    {
        $checks = [
            ['key' => "email:{$userId}:minute",  'max' => 1,  'window' => 60],
            ['key' => "email:{$userId}:hour",    'max' => 3,  'window' => 3600],
            ['key' => "email:{$userId}:day",     'max' => 5,  'window' => 86400],
        ];

        foreach ($checks as $check) {
            $result = self::check($check['key'], $check['max'], $check['window']);
            if (!$result['allowed']) {
                $labels = ['minute' => '分钟', 'hour' => '小时', 'day' => '天'];
                $period = explode(':', $check['key'])[2];
                return [
                    'allowed'     => false,
                    'retry_after' => $result['retry_after'],
                    'message'     => "发送太频繁，{$labels[$period]}内最多 {$check['max']} 封，请 {$result['retry_after']} 秒后重试",
                ];
            }
        }

        return ['allowed' => true, 'retry_after' => 0];
    }
}

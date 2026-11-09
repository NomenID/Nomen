<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

/**
 * 一次性邮箱验证码：第一因素登录、第二因素 2FA 各用一套键。
 *
 * 放 Redis 而不是 users 表的列：这东西只活 10 分钟，TTL 到点自己消失，
 * 不用为它维护列，也不会留下要手工清的残值。值存 sha256，不存明文 ——
 * Redis 里不该躺着一份可以直接拿去用的凭据。
 *
 * ⚠️ LOGIN 与 TWO_FACTOR 必须是两套键，绝不能合成一个：合成之后
 * 「当第二因素用的邮箱码」可以直接拿去 /login/code 当第一因素，两个因素就退化成一个了。
 */
class VerifyCode
{
    public const LOGIN = 'login_code';
    public const TWO_FACTOR = 'two_factor_code';
    /** 家长认领家庭组：证明他控制着那条监护人邮箱（键是家长账号） */
    public const FAMILY_CLAIM = 'family_claim';
    /** 家长帮孩子重置密码（键是孩子的账号） */
    public const FAMILY_RESET = 'family_reset';

    /** 键活 30 分钟、码只认 10 分钟：超时的码还读得出来，才好区分「已过期」和「验证码错误」 */
    private const KEY_TTL = 1800;
    private const VALID_SECONDS = 600;

    public static function issue(string $kind, int $userId, string $code): bool
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return false;
        }

        $payload = (time() + self::VALID_SECONDS) . '|' . hash('sha256', $code);

        try {
            return (bool)$redis->setex(self::key($kind, $userId), self::KEY_TTL, $payload);
        } catch (\Throwable $e) {
            error_log("[verify_code] {$kind} 写入失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @return string ok | mismatch | expired | unavailable
     */
    public static function check(string $kind, int $userId, string $code): string
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return 'unavailable';
        }

        try {
            $raw = $redis->get(self::key($kind, $userId));
        } catch (\Throwable $e) {
            error_log("[verify_code] {$kind} 读取失败: " . $e->getMessage());
            return 'unavailable';
        }

        if (!is_string($raw)) {
            return 'mismatch';
        }

        [$expiresAt, $hash] = array_pad(explode('|', $raw, 2), 2, '');
        if ($hash === '' || !hash_equals($hash, hash('sha256', $code))) {
            return 'mismatch';
        }

        return (int)$expiresAt < time() ? 'expired' : 'ok';
    }

    public static function clear(string $kind, int $userId): void
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return;
        }

        try {
            $redis->del(self::key($kind, $userId));
        } catch (\Throwable $e) {
            error_log("[verify_code] {$kind} 清除失败: " . $e->getMessage());
        }
    }

    private static function key(string $kind, int $userId): string
    {
        return "{$kind}:{$userId}";
    }
}

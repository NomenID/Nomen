<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

use RuntimeException;
use Throwable;

/**
 * 密码哈希的唯一入口。
 *
 * 算法和参数在 config/passwords.php 里配，缺文件就用默认（自动选 Argon2id，主机
 * 不支持时退到 bcrypt）。库里已有的哈希按它自己的算法验证，所以换算法不会让老用户
 * 登不上；登录成功时由 check() 告诉调用方要不要就地改写。
 */
final class Password
{
    /** bcrypt 的默认代价；低配主机可以在配置里调低 */
    private const BCRYPT_DEFAULT_COST = 12;

    /** PHP 的 Argon2id 默认内存开销（KiB） */
    private const ARGON_DEFAULT_MEMORY = 65536;

    /** 给 PHP 自己留的余量：memory_limit 至少要比 Argon2 多这么多 */
    private const MEMORY_HEADROOM = 50331648; // 48 MiB

    /** @var array<string, mixed>|null 测试注入的原始配置 */
    private static ?array $raw = null;

    /** @var array<string, mixed>|null 解析后的配置，一次请求只算一次 */
    private static ?array $resolved = null;

    /**
     * 当前生效的哈希配置。
     *
     * @return array{configured: string, algorithm: string, options: array<string, int>, upgrade: string, degraded: string|null}
     */
    public static function config(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $raw = self::$raw ?? self::readFile();
        $configured = strtolower(trim((string)($raw['algorithm'] ?? 'auto')));
        $options = is_array($raw['options'] ?? null) ? $raw['options'] : [];
        $upgrade = (string)($raw['upgrade'] ?? 'on_login') === 'off' ? 'off' : 'on_login';

        $result = self::resolve($configured, $options, defined('PASSWORD_ARGON2ID'), self::memoryLimitBytes());
        if ($result['degraded'] !== null) {
            error_log('[SECURITY] 密码哈希配置已降级（' . $result['degraded'] . '），实际使用 ' . $result['algorithm']);
        }

        return self::$resolved = $result + ['configured' => $configured, 'upgrade' => $upgrade];
    }

    /**
     * 纯函数：把配置解析成实际要用的算法。测试直接调这个，不依赖 ini 和文件。
     *
     * @param array<string, mixed> $options
     * @return array{algorithm: string, options: array<string, int>, degraded: string|null}
     */
    public static function resolve(string $algorithm, array $options, bool $argonAvailable, int $memoryLimitBytes): array
    {
        $algorithm = strtolower(trim($algorithm));
        $degraded = null;

        if (!in_array($algorithm, ['auto', 'argon2id', 'bcrypt'], true)) {
            $algorithm = 'auto';
            $degraded = 'invalid-config';
        }

        if ($algorithm === 'auto' || $algorithm === 'argon2id') {
            if (!$argonAvailable) {
                $algorithm = 'bcrypt';
                $degraded = $degraded ?? 'no-argon2';
            } elseif (!self::memoryFits($options, $memoryLimitBytes)) {
                $algorithm = 'bcrypt';
                $degraded = $degraded ?? 'low-memory';
            } else {
                $algorithm = 'argon2id';
            }
        }

        if ($algorithm === 'bcrypt') {
            $cost = isset($options['cost']) && is_numeric($options['cost'])
                ? (int)$options['cost']
                : self::BCRYPT_DEFAULT_COST;
            if ($cost < 4 || $cost > 31) {
                $cost = self::BCRYPT_DEFAULT_COST;
                $degraded = $degraded ?? 'invalid-config';
            }

            return ['algorithm' => 'bcrypt', 'options' => ['cost' => $cost], 'degraded' => $degraded];
        }

        $clean = [];
        foreach (['memory_cost', 'time_cost', 'threads'] as $key) {
            if (!array_key_exists($key, $options)) {
                continue;
            }
            if (!is_numeric($options[$key]) || (int)$options[$key] <= 0) {
                $degraded = $degraded ?? 'invalid-config';
                continue;
            }
            $clean[$key] = (int)$options[$key];
        }

        return ['algorithm' => 'argon2id', 'options' => $clean, 'degraded' => $degraded];
    }

    /**
     * 生成新哈希。pepper 必传，没有 pepper 的地方（临时密钥、恢复码）显式传空串。
     */
    public static function hash(string $plain, string $pepper): string
    {
        $config = self::config();

        try {
            $hash = password_hash(
                self::inputForNew($plain, $pepper, $config['algorithm']),
                self::phpAlgo($config['algorithm']),
                $config['options']
            );
        } catch (Throwable $e) {
            $hash = false;
        }
        if (is_string($hash) && $hash !== '') {
            return $hash;
        }

        // 配置的算法在运行时失败（参数非法、扩展缺失等），别再往库里写空哈希
        error_log('[SECURITY] password_hash() 失败，改用 bcrypt');
        try {
            $fallback = password_hash(self::hmac($plain, $pepper), PASSWORD_BCRYPT, ['cost' => self::BCRYPT_DEFAULT_COST]);
        } catch (Throwable $e) {
            $fallback = false;
        }
        if (is_string($fallback) && $fallback !== '') {
            return $fallback;
        }

        throw new RuntimeException('password_hash() failed');
    }

    /** 只验证，不关心要不要升级（改密、二验确认、恢复码等） */
    public static function verify(string $plain, string $hash, string $pepper): bool
    {
        $result = self::check($plain, $hash, $pepper);
        return $result['ok'];
    }

    /**
     * 登录专用：验证并判断这条哈希要不要按当前配置改写。
     *
     * @return array{ok: bool, upgrade: bool}
     */
    public static function check(string $plain, string $hash, string $pepper): array
    {
        $hash = trim($hash);
        if ($hash === '') {
            return ['ok' => false, 'upgrade' => false];
        }

        // 老哈希是 argon2id 拼接 pepper；bcrypt 是新方案，pepper 走 HMAC 预哈希
        $input = self::algorithmOf($hash) === 'bcrypt' ? self::hmac($plain, $pepper) : $plain . $pepper;

        try {
            $ok = password_verify($input, $hash);
        } catch (Throwable $e) {
            $ok = false;
        }

        $legacy = false;
        if (!$ok) {
            try {
                $legacy = password_verify($plain, $hash);
            } catch (Throwable $e) {
                $legacy = false;
            }
        }

        if (!$ok && !$legacy) {
            return ['ok' => false, 'upgrade' => false];
        }

        $config = self::config();
        $upgrade = false;
        if ($config['upgrade'] === 'on_login') {
            try {
                $upgrade = $legacy || password_needs_rehash($hash, self::phpAlgo($config['algorithm']), $config['options']);
            } catch (Throwable $e) {
                $upgrade = $legacy;
            }
        }

        return ['ok' => true, 'upgrade' => $upgrade];
    }

    /**
     * 健康检查用：用一个便宜的参数集实际算一次，确认当前算法真的可用。
     * 可用返回 null，否则返回原因（不会抛异常，检查页不能因为探测自己 500）。
     */
    public static function problem(): ?string
    {
        $config = self::config();
        $algorithm = $config['algorithm'];
        $options = $algorithm === 'bcrypt'
            ? ['cost' => 4]
            : ['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1];

        try {
            $input = self::inputForNew('password-probe', 'password-probe', $algorithm);
            $probe = password_hash($input, self::phpAlgo($algorithm), $options);
            if (!is_string($probe) || $probe === '') {
                return 'password_hash() returned an empty hash';
            }
            if (!password_verify($input, $probe)) {
                return 'the generated hash does not verify';
            }
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * 给站点健康和 doctor 用的摘要。
     *
     * @return array{configured: string, algorithm: string, options: array<string, int>, upgrade: string, degraded: string|null}
     */
    public static function describe(): array
    {
        return self::config();
    }

    /**
     * 测试用：临时替换配置，不读 config/passwords.php。
     *
     * @internal
     * @param array<string, mixed> $config
     */
    public static function override(array $config): void
    {
        self::$raw = $config;
        self::$resolved = null;
    }

    /**
     * 测试用：恢复成读配置文件。
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$raw = null;
        self::$resolved = null;
    }

    /** @return array<string, mixed> */
    private static function readFile(): array
    {
        $file = dirname(__DIR__) . '/config/passwords.php';
        if (!is_file($file)) {
            return [];
        }

        $config = require $file;
        return is_array($config) ? $config : [];
    }

    private static function inputForNew(string $plain, string $pepper, string $algorithm): string
    {
        // bcrypt 只看前 72 字节，而 pepper 有 64 个字符；先 HMAC 成固定 64 字符
        return $algorithm === 'bcrypt' ? self::hmac($plain, $pepper) : $plain . $pepper;
    }

    private static function hmac(string $plain, string $pepper): string
    {
        return base64_encode(hash_hmac('sha384', $plain, $pepper, true));
    }

    /** @return string|int */
    private static function phpAlgo(string $algorithm)
    {
        return $algorithm === 'bcrypt' ? PASSWORD_BCRYPT : PASSWORD_ARGON2ID;
    }

    private static function algorithmOf(string $hash): string
    {
        try {
            return (string)(password_get_info($hash)['algoName'] ?? 'unknown');
        } catch (Throwable $e) {
            return 'unknown';
        }
    }

    /** @param array<string, mixed> $options */
    private static function memoryFits(array $options, int $memoryLimitBytes): bool
    {
        if ($memoryLimitBytes <= 0) {
            return true;
        }

        $memoryCost = isset($options['memory_cost']) && is_numeric($options['memory_cost'])
            ? max(1, (int)$options['memory_cost'])
            : self::ARGON_DEFAULT_MEMORY;

        return $memoryLimitBytes >= $memoryCost * 1024 + self::MEMORY_HEADROOM;
    }

    /** -1 表示不限制（CLI 常见） */
    private static function memoryLimitBytes(): int
    {
        $raw = trim((string)ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $value = (int)$raw;
        if ($value <= 0) {
            return -1;
        }

        $multiplier = match (strtolower(substr($raw, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return $value * $multiplier;
    }
}

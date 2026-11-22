<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

use Nomen\Src\Password;

test('密码算法：auto 在主机没有 Argon2 时降级 bcrypt', static function (): void {
    $result = Password::resolve('auto', [], false, -1);
    assertSame('bcrypt', $result['algorithm']);
    assertSame('no-argon2', $result['degraded']);
    assertSame(['cost' => 12], $result['options']);
});

test('密码算法：auto 在 memory_limit 不够时降级 bcrypt', static function (): void {
    $result = Password::resolve('auto', [], true, 32 * 1024 * 1024);
    assertSame('bcrypt', $result['algorithm']);
    assertSame('low-memory', $result['degraded']);
});

test('密码算法：auto 在内存足够时用 argon2id', static function (): void {
    $result = Password::resolve('auto', [], true, -1);
    assertSame('argon2id', $result['algorithm']);
    assertSame(null, $result['degraded']);
    assertSame([], $result['options']);
});

test('密码算法：非法算法回到 auto，非法 cost 回默认', static function (): void {
    $result = Password::resolve('md5', [], false, -1);
    assertSame('bcrypt', $result['algorithm']);
    assertSame('invalid-config', $result['degraded']);

    $result = Password::resolve('bcrypt', ['cost' => 99], true, -1);
    assertSame(['cost' => 12], $result['options']);
    assertSame('invalid-config', $result['degraded']);
});

test('密码算法：显式 argon2id 但主机不支持时降级而不是 fatal', static function (): void {
    $result = Password::resolve('argon2id', [], false, -1);
    assertSame('bcrypt', $result['algorithm']);
    assertSame('no-argon2', $result['degraded']);
});

test('bcrypt：哈希能验证，错密码和错 pepper 都不通过', static function (): void {
    Password::override(['algorithm' => 'bcrypt', 'options' => ['cost' => 4]]);
    try {
        $hash = Password::hash('CorrectHorse1', 'pepper-123');
        assertSame(true, str_starts_with($hash, '$2y$'));
        assertSame(true, Password::verify('CorrectHorse1', $hash, 'pepper-123'));
        assertSame(false, Password::verify('wrong', $hash, 'pepper-123'));
        assertSame(false, Password::verify('CorrectHorse1', $hash, 'other-pepper'));
    } finally {
        Password::reset();
    }
});

test('bcrypt：pepper 不会把密码挤到 72 字节截断区', static function (): void {
    Password::override(['algorithm' => 'bcrypt', 'options' => ['cost' => 4]]);
    try {
        $pepper = str_repeat('p', 64);
        $long = str_repeat('a', 100);
        $hash = Password::hash($long, $pepper);

        assertSame(true, Password::verify($long, $hash, $pepper));
        // 前 72 字节相同、后面不同：朴素拼接会被 bcrypt 截成同一个输入
        assertSame(false, Password::verify(str_repeat('a', 72) . 'b', $hash, $pepper));
    } finally {
        Password::reset();
    }
});

test('argon2id：哈希与验证（主机不支持时跳过）', static function (): void {
    if (!defined('PASSWORD_ARGON2ID')) {
        return;
    }

    Password::override(['algorithm' => 'argon2id']);
    try {
        $hash = Password::hash('CorrectHorse1', 'pepper-123');
        assertSame(true, str_starts_with($hash, '$argon2id$'));
        assertSame(true, Password::verify('CorrectHorse1', $hash, 'pepper-123'));
        assertSame(false, Password::verify('wrong', $hash, 'pepper-123'));
    } finally {
        Password::reset();
    }
});

test('老的无 pepper 哈希：能登录并标记升级', static function (): void {
    if (!defined('PASSWORD_ARGON2ID')) {
        return;
    }

    Password::override(['algorithm' => 'argon2id']);
    try {
        $legacy = password_hash('CorrectHorse1', PASSWORD_ARGON2ID);
        $result = Password::check('CorrectHorse1', $legacy, 'pepper-123');
        assertSame(true, $result['ok']);
        assertSame(true, $result['upgrade']);
    } finally {
        Password::reset();
    }
});

test('参数一致时不产生升级波', static function (): void {
    if (!defined('PASSWORD_ARGON2ID')) {
        return;
    }

    Password::override(['algorithm' => 'argon2id']);
    try {
        $hash = Password::hash('CorrectHorse1', 'pepper-123');
        $result = Password::check('CorrectHorse1', $hash, 'pepper-123');
        assertSame(true, $result['ok']);
        assertSame(false, $result['upgrade']);
    } finally {
        Password::reset();
    }
});

test('切到 bcrypt 后 argon2id 老哈希依然能验证并标记升级', static function (): void {
    if (!defined('PASSWORD_ARGON2ID')) {
        return;
    }

    $legacy = password_hash('CorrectHorse1' . 'pepper-123', PASSWORD_ARGON2ID);
    Password::override(['algorithm' => 'bcrypt', 'options' => ['cost' => 4]]);
    try {
        assertSame(true, Password::verify('CorrectHorse1', $legacy, 'pepper-123'));
        assertSame(true, Password::check('CorrectHorse1', $legacy, 'pepper-123')['upgrade']);
    } finally {
        Password::reset();
    }
});

test('upgrade=off 时老哈希不标记升级', static function (): void {
    if (!defined('PASSWORD_ARGON2ID')) {
        return;
    }

    Password::override(['algorithm' => 'argon2id', 'upgrade' => 'off']);
    try {
        $legacy = password_hash('CorrectHorse1', PASSWORD_ARGON2ID);
        $result = Password::check('CorrectHorse1', $legacy, 'pepper-123');
        assertSame(true, $result['ok']);
        assertSame(false, $result['upgrade']);
    } finally {
        Password::reset();
    }
});

test('临时密钥 / 恢复码：无 pepper 的哈希也能验证', static function (): void {
    Password::override(['algorithm' => 'bcrypt', 'options' => ['cost' => 4]]);
    try {
        $hash = Password::hash('ABC-123-XYZ', '');
        assertSame(true, Password::verify('ABC-123-XYZ', $hash, ''));
        assertSame(false, Password::verify('ABC-123-XY', $hash, ''));
    } finally {
        Password::reset();
    }
});

test('空哈希永远不通过', static function (): void {
    assertSame(false, Password::verify('x', '', 'pepper'));
    assertSame(false, Password::check('x', '  ', 'pepper')['ok']);
});

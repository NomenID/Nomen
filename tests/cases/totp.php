<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

use Nomen\Src\TOTP;

// RFC 6238 附录 B 的 SHA1 向量（密钥是 ASCII "12345678901234567890"），
// 这里取后 6 位，和本项目生成的长度一致。
$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

$vectors = [
    1          => '287082',
    37037036   => '081804',
    37037037   => '050471',
    41152263   => '005924',
    66666666   => '279037',
    666666666  => '353130',
];

test('TOTP 对齐 RFC 6238 向量', static function () use ($secret, $vectors): void {
    foreach ($vectors as $slice => $expected) {
        assertSame($expected, TOTP::getCode($secret, $slice), '时间片 ' . $slice);
    }
});

test('TOTP 生成的密钥是 32 位 Base32', static function (): void {
    $secret = TOTP::generateSecret();

    assertSame(32, strlen($secret));
    assertMatches('/^[A-Z2-7]{32}$/', $secret);
});

test('TOTP 校验当前码、拒绝错码和过期码', static function () use ($secret, $vectors): void {
    assertTrue(TOTP::verify($secret, '287082', 0) === false, '空时间片不该命中时间片 1');
    assertTrue(TOTP::verify($secret, '000000', 0) === false, '错码必须拒绝');

    $now = intdiv(time(), 30);
    assertTrue(TOTP::verify($secret, TOTP::getCode($secret, $now), 0), '当前码必须通过');
});

test('TOTP 开通链接带 issuer 和密钥', static function (): void {
    $uri = TOTP::getProvisioningUri('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'alice', 'Nomen');

    assertMatches('#^otpauth://totp/#', $uri);
    assertTrue(str_contains($uri, 'secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'));
    assertTrue(str_contains($uri, 'issuer=Nomen'));
});

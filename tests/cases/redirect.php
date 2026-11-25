<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

use Nomen\Controllers\AuthController;

test('合法站内路径原样保留', static function (): void {
    assertSame('/oauth/authorize?client_id=app_x&state=1', AuthController::safeRedirectPath('/oauth/authorize?client_id=app_x&state=1'));
    assertSame('/', AuthController::safeRedirectPath('/'));
    assertSame('/settings/security#sec-password', AuthController::safeRedirectPath('/settings/security#sec-password'));
});

test('站外地址一律回落到默认值', static function (): void {
    assertSame('/dashboard', AuthController::safeRedirectPath('https://evil.example/'));
    assertSame('/dashboard', AuthController::safeRedirectPath('//evil.example/'));
    assertSame('/dashboard', AuthController::safeRedirectPath('javascript:alert(1)'));
    assertSame('/dashboard', AuthController::safeRedirectPath('/\\evil.example'));
    assertSame('/dashboard', AuthController::safeRedirectPath("/foo\nbar"));
    assertSame('/dashboard', AuthController::safeRedirectPath('/a://b'));
});

test('空值用调用方给的回落值', static function (): void {
    assertSame('', AuthController::safeRedirectPath('', ''));
    assertSame('', AuthController::safeRedirectPath(null, ''));
    assertSame('/dashboard', AuthController::safeRedirectPath('   '));
});

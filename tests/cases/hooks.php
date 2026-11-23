<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

require_once dirname(__DIR__, 2) . '/src/I18n.php';

use Nomen\Controllers\AuthController;
use Nomen\Src\Hooks;
use Nomen\Src\Plugins;

test('auth.login.pre 返回 block / challenge / allow 时的约定', function (): void {
    Hooks::add('auth.login.pre', static function (array $screen, array $context): array {
        return match ($context['identifier']) {
            'blocked'   => ['decision' => 'block', 'message' => 'stop', 'payload' => ['x' => 1]],
            'challenge' => ['decision' => 'challenge'],
            'weird'     => ['decision' => 'whatever'],
            default     => $screen,
        };
    }, 1);

    $blocked = AuthController::screenLoginRequest('password', 'blocked', [
        ['id' => 3, 'username' => 'alice', 'email' => 'a@example.com', 'password_hash' => 'SECRET'],
    ], true);

    assertSame('block', $blocked['decision']);
    assertSame('stop', $blocked['message']);
    assertSame(['x' => 1], $blocked['payload']);
    assertSame(3, $blocked['user_id']);
    assertTrue(!str_contains(json_encode($blocked['context']), 'SECRET'), '密码哈希不能交给插件');

    $challenge = AuthController::screenLoginRequest('password', 'challenge', []);
    assertSame('challenge', $challenge['decision']);
    assertSame('Please complete the human check', $challenge['message']);

    assertSame(null, AuthController::screenLoginRequest('password', 'fine', []));
    assertSame(null, AuthController::screenLoginRequest('password', 'weird', []), '未知 decision 按放行处理');
});

test('auth.register.pre 的 degraded 用兜底文案', function (): void {
    Hooks::add('auth.register.pre', static fn(array $screen, array $context): array => [
        'decision' => 'degraded',
    ], 1);

    $result = AuthController::screenRegisterRequest(['username' => 'newbie', 'email' => 'n@example.com']);
    assertSame('degraded', $result['decision']);
    assertSame('Service temporarily unavailable, try again later', $result['message']);
});

test('插件 provides 只收自己 slug 前缀的钩子', function (): void {
    $method = new ReflectionMethod(Plugins::class, 'registerProvides');
    $method->setAccessible(true);
    $method->invoke(null, 'bookmarks', ['provides' => [
        'bookmarks.saved' => '书签保存之后',
        'bookmarks.page.rendered' => '书签页渲染完之后',
        'pages.saved' => '别人的命名空间',
    ]]);

    $provided = Plugins::providedHooks()['bookmarks'] ?? [];
    assertTrue(isset($provided['bookmarks.saved']));
    assertTrue(isset($provided['bookmarks.page.rendered']));
    assertTrue(!isset($provided['pages.saved']));
});

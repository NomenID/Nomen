<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

use Nomen\Src\Session;

test('flash 只取一次', static function (): void {
    $_SESSION = [];

    Session::flash('success', '已保存');
    assertSame('已保存', Session::getFlash('success'));
    assertSame(null, Session::getFlash('success'), '第二次不该再拿到');
});

test('放凉的 flash 不再显示', static function (): void {
    $_SESSION = [];

    Session::flash('success', '设置成功');
    // 模拟「下一次页面渲染」拖了很久：后台页面没消费，第二天才进应用区
    $_SESSION['_flash_at']['success'] = time() - 3600;

    assertSame('none', Session::getFlash('success', 'none'), '过期了就该当没有');
    assertTrue(!isset($_SESSION['_flash']['success']), '取过就清掉，别留在会话里');
});

test('老会话里没有时间戳的 flash 按过期处理', static function (): void {
    $_SESSION = ['_flash' => ['success' => '旧的']];

    assertSame(null, Session::getFlash('success'));
    assertTrue(!isset($_SESSION['_flash']['success']));
});

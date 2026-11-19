<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

use Nomen\Src\AutoUpdate;
use Nomen\Src\UpdateCheck;
use Nomen\Src\UpdatePackage;

test('自动更新只默认覆盖最后一位，第二位/第一位要显式放开', static function (): void {
    assertSame('patch', AutoUpdate::updateKind('1.7.0', '1.7.1'));
    assertSame('minor', AutoUpdate::updateKind('1.7.0', '1.8.0'));
    assertSame('major', AutoUpdate::updateKind('1.7.0', '2.0.0'));
    assertSame('none', AutoUpdate::updateKind('1.7.0', '1.7.0'));

    // 默认 scope = patch
    assertTrue(AutoUpdate::scopeAllows('1.7.0', '1.7.1'));
    assertTrue(!AutoUpdate::scopeAllows('1.7.0', '1.8.0'));
    assertTrue(!AutoUpdate::scopeAllows('1.7.0', '2.0.0'));
    assertTrue(AutoUpdate::scopeAllows('1.7.0+qb.20261118', '1.7.1'), 'quickbuild 后缀不参与比较');
});

test('优先级阈值只挡住更低级别', static function (): void {
    assertTrue(AutoUpdate::priorityAllows('security'));
    assertTrue(AutoUpdate::priorityAllows('critical'));
    assertTrue(AutoUpdate::priorityAllows('normal'));
    assertTrue(!AutoUpdate::priorityAllows('feature'), '默认最低优先级是 normal');
    assertTrue(AutoUpdate::priorityBypassesWindow('security'));
    assertTrue(AutoUpdate::priorityBypassesWindow('critical'));
    assertTrue(!AutoUpdate::priorityBypassesWindow('normal'));
});

test('维护窗口支持普通段和跨午夜', static function (): void {
    assertTrue(AutoUpdate::windowAllows('03:00-05:00', 4 * 60));
    assertTrue(!AutoUpdate::windowAllows('03:00-05:00', 5 * 60));
    assertTrue(!AutoUpdate::windowAllows('03:00-05:00', 2 * 60));

    assertTrue(AutoUpdate::windowAllows('23:00-02:00', 23 * 60 + 30));
    assertTrue(AutoUpdate::windowAllows('23:00-02:00', 60));
    assertTrue(!AutoUpdate::windowAllows('23:00-02:00', 12 * 60));
    assertTrue(AutoUpdate::windowAllows('', 12 * 60), '空窗口 = 不限');
});

test('维护窗口支持白名单和黑名单两种模式', static function (): void {
    // 白名单：只在窗口内放行
    assertTrue(AutoUpdate::windowAllowsMode('03:00-05:00', 'allow', 4 * 60));
    assertTrue(!AutoUpdate::windowAllowsMode('03:00-05:00', 'allow', 12 * 60));

    // 黑名单：窗口内不放行，其余时间放行
    assertTrue(!AutoUpdate::windowAllowsMode('09:00-18:00', 'deny', 12 * 60));
    assertTrue(AutoUpdate::windowAllowsMode('09:00-18:00', 'deny', 2 * 60));
    assertTrue(!AutoUpdate::windowAllowsMode('23:00-02:00', 'deny', 23 * 60 + 30));
    assertTrue(AutoUpdate::windowAllowsMode('23:00-02:00', 'deny', 12 * 60));

    // 空窗口：两种模式都不限制
    assertTrue(AutoUpdate::windowAllowsMode('', 'allow', 12 * 60));
    assertTrue(AutoUpdate::windowAllowsMode('', 'deny', 12 * 60));
});

test('更新包路径不允许跳出站点根目录', static function (): void {
    assertTrue(UpdatePackage::isSafeEntry('src/AutoUpdate.php'));
    assertTrue(UpdatePackage::isSafeEntry('public/assets/dist/app.js'));

    assertTrue(!UpdatePackage::isSafeEntry(''));
    assertTrue(!UpdatePackage::isSafeEntry('/etc/passwd'));
    assertTrue(!UpdatePackage::isSafeEntry('../config/installed.php'));
    assertTrue(!UpdatePackage::isSafeEntry('src/../../etc/passwd'));
    assertTrue(!UpdatePackage::isSafeEntry('C:/Windows/win.ini'));
    assertTrue(!UpdatePackage::isSafeEntry("src/a\0b.php"));
});

test('版本比较只看前三段数字', static function (): void {
    assertTrue(UpdateCheck::isNewer('1.7.1', '1.7.0'));
    assertTrue(UpdateCheck::isNewer('1.8.0', '1.7.9'));
    assertTrue(!UpdateCheck::isNewer('1.7.0', '1.7.0'));
    assertTrue(!UpdateCheck::isNewer('1.7.0', '1.7.1'));
});

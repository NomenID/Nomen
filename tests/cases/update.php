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

test('自动更新通道按大版本 / 小版本筛', static function (): void {
    assertTrue(AutoUpdate::channelAllows('1.7.0', '1.7.1', 'patch'));
    assertTrue(AutoUpdate::channelAllows('1.7.0', '1.7.1', 'minor'));
    assertTrue(AutoUpdate::channelAllows('1.7.0', '1.7.1', 'all'));

    assertTrue(!AutoUpdate::channelAllows('1.7.0', '1.8.0', 'patch'));
    assertTrue(AutoUpdate::channelAllows('1.7.0', '1.8.0', 'minor'));
    assertTrue(AutoUpdate::channelAllows('1.7.0', '1.8.0', 'all'));

    assertTrue(!AutoUpdate::channelAllows('1.7.0', '2.0.0', 'patch'));
    assertTrue(!AutoUpdate::channelAllows('1.7.0', '2.0.0', 'minor'));
    assertTrue(AutoUpdate::channelAllows('1.7.0', '2.0.0', 'all'));

    assertTrue(!AutoUpdate::channelAllows('1.7.0', '1.6.9', 'all'), '不比当前版本旧');
    assertTrue(AutoUpdate::channelAllows('1.7.0+qb.20261118', '1.7.1', 'patch'), 'quickbuild 后缀不参与比较');
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

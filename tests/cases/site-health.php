<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

use Nomen\Src\SiteHealth;

test('站点健康总体状态取最严重的一条', static function (): void {
    assertSame('good', SiteHealth::worstStatus(['good', 'good']));
    assertSame('recommended', SiteHealth::worstStatus(['good', 'recommended', 'good']));
    assertSame('critical', SiteHealth::worstStatus(['recommended', 'critical', 'good']));
    assertSame('good', SiteHealth::worstStatus([]));
    assertSame('recommended', SiteHealth::worstStatus(['unknown', 'recommended']));
});

test('PHP2Shell 按各分支的修复版本判定', static function (): void {
    assertSame(['affected' => true, 'fix' => '8.2.35'], SiteHealth::php2shell('8.2.34'));
    assertSame(['affected' => false, 'fix' => '8.2.35'], SiteHealth::php2shell('8.2.35'));
    assertSame(['affected' => true, 'fix' => '8.3.36'], SiteHealth::php2shell('8.3.35'));
    assertSame(['affected' => false, 'fix' => '8.3.36'], SiteHealth::php2shell('8.3.36'));
    assertSame(['affected' => true, 'fix' => '8.4.27'], SiteHealth::php2shell('8.4.26'));
    assertSame(['affected' => false, 'fix' => '8.4.27'], SiteHealth::php2shell('8.4.27'));
    assertSame(['affected' => true, 'fix' => '8.5.12'], SiteHealth::php2shell('8.5.11'));
    assertSame(['affected' => false, 'fix' => '8.5.12'], SiteHealth::php2shell('8.5.12'));
});

test('PHP2Shell：已停止维护的分支只给源码补丁，8.6 起不受影响', static function (): void {
    assertSame(['affected' => true, 'fix' => null], SiteHealth::php2shell('8.1.33'));
    assertSame(['affected' => true, 'fix' => null], SiteHealth::php2shell('7.4.33'));
    assertSame(['affected' => false, 'fix' => null], SiteHealth::php2shell('8.6.0'));
});

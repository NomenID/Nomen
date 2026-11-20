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

    assertSame('1.9', UpdateCheck::branch('1.9.0'));
    assertSame('1.9', UpdateCheck::branch('1.9.0+qb.20261120'));
    assertSame('2.0', UpdateCheck::branch('2.0.0'));
});

test('多分支发布源：优先挑同一分支的补丁', static function (): void {
    $payload = [
        'releases' => [
            ['version' => '1.9.0', 'branch' => '1.9'],
            ['version' => '1.8.5', 'branch' => '1.8'],
        ],
        'support' => [
            '1.9' => ['status' => 'active'],
            '1.8' => ['status' => 'maintenance'],
        ],
    ];

    // 1.8.4 该拿 1.8.5，不能被 1.9.0 盖掉
    $pick = UpdateCheck::select($payload, '1.8.4');
    assertSame('same_branch', $pick['reason']);
    assertSame('1.8.5', $pick['offer']['version']);
    assertSame('maintenance', $pick['branchStatus']);

    // 1.9.0 已经最新
    $pick = UpdateCheck::select($payload, '1.9.0');
    assertSame('up_to_date', $pick['reason']);
    assertSame(null, $pick['offer']);

    // 1.9.1 比发布源还新（自建版本）也不该回退
    $pick = UpdateCheck::select($payload, '1.9.1');
    assertSame('up_to_date', $pick['reason']);
});

test('多分支发布源：停更分支给升级目标与路径', static function (): void {
    $payload = [
        'releases' => [
            ['version' => '1.9.0', 'branch' => '1.9'],
            ['version' => '1.8.5', 'branch' => '1.8'],
            ['version' => '1.7.3', 'branch' => '1.7'],
        ],
        'support' => [
            '1.9' => ['status' => 'active'],
            '1.8' => ['status' => 'maintenance'],
            '1.7' => ['status' => 'eol', 'last' => '1.7.3'],
        ],
    ];

    // 1.7.1：自己分支虽然停更，但还有最后一版 1.7.3，先把同分支的补丁装上
    $pick = UpdateCheck::select($payload, '1.7.1');
    assertSame('same_branch', $pick['reason']);
    assertSame('1.7.3', $pick['offer']['version']);

    // 到 1.7.3 之后才提示跨分支，给最近的高一级分支（1.8）和路径
    $pick = UpdateCheck::select($payload, '1.7.3');
    assertSame('eol_branch', $pick['reason']);
    assertSame('1.8.5', $pick['offer']['version']);
    assertSame(['1.8.5'], $pick['path'], '路径要按版本顺序列');

    // 1.6.x 完全不在支持表里；1.7 已经停更，直接指到 1.8
    $pick = UpdateCheck::select($payload, '1.6.2');
    assertSame('unknown_branch', $pick['reason']);
    assertSame('1.8.5', $pick['offer']['version'], '只往受支持的分支引导');
    assertSame(['1.8.5'], $pick['path']);

    // 已经停更且没有更高分支可用：只报停更，不给版本
    $only = ['releases' => [['version' => '1.7.3', 'branch' => '1.7']], 'support' => ['1.7' => ['status' => 'eol']]];
    $pick = UpdateCheck::select($only, '1.7.3');
    assertSame('up_to_date', $pick['reason']);
    assertSame('eol', $pick['branchStatus']);
});

test('多分支发布源：维护中的分支也会提示更高的次要版本', static function (): void {
    $payload = [
        'releases' => [['version' => '1.9.0', 'branch' => '1.9']],
        'support' => [
            '1.9' => ['status' => 'active'],
            '1.8' => ['status' => 'maintenance'],
        ],
    ];

    // 1.8 分支没有新补丁，但 1.9.0 是更高版本：不能装死说「已是最新」
    $pick = UpdateCheck::select($payload, '1.8.4');
    assertSame('cross_branch', $pick['reason']);
    assertSame('1.9.0', $pick['offer']['version']);
    assertSame('maintenance', $pick['branchStatus']);
    assertSame([], $pick['path'], '维护中的分支不缺中间版本，不用给路径');

    // 1.9.0 自己才是最新
    $pick = UpdateCheck::select($payload, '1.9.0');
    assertSame('up_to_date', $pick['reason']);
});

test('老格式单条发布源行为不变', static function (): void {
    $payload = ['releases' => [['version' => '1.9.0']], 'support' => []];

    $pick = UpdateCheck::select($payload, '1.8.4');
    assertSame('cross_branch', $pick['reason'], '老格式没有分支表，按全局最高走');
    assertSame('1.9.0', $pick['offer']['version']);
    assertSame('active', $pick['branchStatus']);

    $pick = UpdateCheck::select($payload, '1.9.0');
    assertSame('up_to_date', $pick['reason']);
});

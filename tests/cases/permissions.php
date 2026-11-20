<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

use Nomen\Src\Database;
use Nomen\Src\Gate;
use Nomen\Src\Permissions;

/**
 * 权限判定的核心语义：组并集、deny 覆盖、默认拒绝、通配、checker、role 兼容。
 *
 * 每次造一个干净的内存库：整套逻辑都走 Database::getInstance()，测试没有站点配置可读。
 */
$permFixture = static function (): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        unid TEXT NOT NULL,
        username TEXT NOT NULL,
        email TEXT,
        status INTEGER NOT NULL DEFAULT 1,
        role TEXT NOT NULL DEFAULT "user"
    )');
    $pdo->exec('CREATE TABLE user_groups (
        user_id INTEGER NOT NULL,
        grp TEXT NOT NULL,
        granted_by INTEGER NOT NULL DEFAULT 0,
        granted_at INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, grp)
    )');
    $pdo->exec('CREATE TABLE group_rules (
        grp TEXT NOT NULL,
        ability TEXT NOT NULL,
        allowed INTEGER NOT NULL DEFAULT 1,
        updated_at INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (grp, ability)
    )');

    // 1 普通用户、2 旧版管理员、3 停用账号
    $pdo->exec("INSERT INTO users (unid, username, email, status, role) VALUES
        ('U1', 'alice', 'a@example.com', 1, 'user'),
        ('U2', 'bob',   'b@example.com', 1, 'admin'),
        ('U3', 'carol', 'c@example.com', 0, 'user')");

    Database::setInstance($pdo);
    Gate::flush();

    return $pdo;
};

test('默认拒绝：没组的用户和匿名都没有任何能力', static function () use ($permFixture): void {
    $permFixture();

    assertTrue(!Gate::userCan(1, 'users.manage'), '没授权就该拒绝');
    assertTrue(!Gate::userCan(1, 'admin.access'), '普通用户不该进后台');
    assertTrue(!Gate::userCan(0, 'admin.access'), '匿名不该有任何能力');
    assertTrue(!Gate::userCan(999, 'users.manage'), '不存在的用户也拒绝');
});

test('组授权进门，撤权出门，都在同一请求内生效', static function () use ($permFixture): void {
    $permFixture();

    Permissions::setRule('support', 'users.manage', true);
    Permissions::assignGroup(1, 'support');
    Gate::flush();

    assertTrue(Gate::userCan(1, 'users.manage'), '加组后应立刻有权限');
    assertTrue(!Gate::userCan(1, 'logs.view'), '没给的能力不能有');

    Permissions::assignGroups(1, []);
    Gate::flush();

    assertTrue(!Gate::userCan(1, 'users.manage'), '移出组后应立刻失去权限');
});

test('deny 盖过其它任何组的 allow', static function () use ($permFixture): void {
    $permFixture();

    // bob 在 administrator（通配 *），再进一个拒绝 logs.view 的组
    assertTrue(Gate::userCan(2, 'logs.view'), 'administrator 的 * 应覆盖全部能力');

    Permissions::setRule('auditor', 'logs.view', false);
    Permissions::assignGroup(2, 'auditor');
    Gate::flush();

    assertTrue(!Gate::userCan(2, 'logs.view'), '一组拒绝就该拒绝，哪怕别的组允许');
    assertTrue(Gate::userCan(2, 'users.manage'), '只拒绝了一个能力，其它不受影响');
});

test('组规则支持前缀通配和全通配', static function () use ($permFixture): void {
    $permFixture();

    Permissions::register('docs.manage', ['label' => 'Manage docs']);
    Permissions::register('docs.edit', ['label' => 'Edit docs']);
    Permissions::setRule('writer', 'docs.*', true);
    Permissions::assignGroup(1, 'writer');
    Gate::flush();

    assertTrue(Gate::userCan(1, 'docs.manage'));
    assertTrue(Gate::userCan(1, 'docs.edit'));
    assertTrue(!Gate::userCan(1, 'apps.manage'), '通配不该外溢到别的命名空间');
});

test('注册的 checker 只放行资源本人', static function () use ($permFixture): void {
    $permFixture();

    Permissions::register(
        'docs.edit',
        ['label' => 'Edit docs'],
        static fn(int $userId, array $doc): bool => (int)$doc['author_id'] === $userId
    );
    Permissions::setRule('writer', 'docs.edit', true);
    Permissions::assignGroup(1, 'writer');
    Gate::flush();

    assertTrue(Gate::userCan(1, 'docs.edit', ['author_id' => 1]), '作者本人应通过');
    assertTrue(!Gate::userCan(1, 'docs.edit', ['author_id' => 99]), '别人写的应拒绝');
});

test('role=admin 的旧库兜底；停用账号不吃授权', static function () use ($permFixture): void {
    $permFixture();

    // 没有组行，只有 role 快照——迁移前的老站点行为必须不变
    assertTrue(Gate::userCan(2, 'admin.access'), 'role=admin 应视同在 administrator 组');
    assertTrue(Gate::userCan(2, 'users.manage'), 'role=admin 应拿到通配能力');

    // 停用账号（status=0）即使被指派了组也不生效
    Permissions::setRule('support', 'users.manage', true);
    Permissions::assignGroup(3, 'support');
    Gate::flush();

    assertTrue(!Gate::userCan(3, 'users.manage'), '停用账号不该吃授权');
});

test('assignGroups 同步 users.role，隐式组不可指派', static function () use ($permFixture): void {
    $pdo = $permFixture();

    Permissions::assignGroups(1, ['support', 'administrator'], 2);
    Gate::flush();
    assertSame('admin', $pdo->query('SELECT role FROM users WHERE id = 1')->fetchColumn(), '进 administrator 组要同步 role');

    Permissions::assignGroups(1, ['*', 'user', 'support'], 2);
    Gate::flush();
    assertSame('user', $pdo->query('SELECT role FROM users WHERE id = 1')->fetchColumn(), '出 administrator 组要同步回 user');
    assertSame(['support'], Permissions::assignedGroups(1), '隐式组不能被写入 user_groups');
});

test('删掉后台规则就回落到 config 默认', static function () use ($permFixture): void {
    $permFixture();

    // support 在 config 里没定义 → 默认没有 users.manage
    Permissions::setRule('support', 'users.manage', true);
    Permissions::assignGroup(1, 'support');
    Gate::flush();
    assertTrue(Gate::userCan(1, 'users.manage'));

    Permissions::setRule('support', 'users.manage', null);
    Gate::flush();
    assertTrue(!Gate::userCan(1, 'users.manage'), '删掉覆盖规则应回落到默认（拒绝）');
    assertTrue(!Permissions::hasDbRule('support', 'users.manage'), '规则行应被删掉');
});

test('无效组名与隐式组不能写规则', static function () use ($permFixture): void {
    $permFixture();

    assertTrue(!Permissions::setRule('*', 'users.manage', true), '隐式组不可写规则');
    assertTrue(!Permissions::setRule('user', 'users.manage', true), '隐式组不可写规则');
    assertTrue(!Permissions::setRule('Bad Group', 'users.manage', true), '组名不合法应拒绝');
    assertTrue(Permissions::setRule('support', 'users.manage', true), '合法组名应通过');
});

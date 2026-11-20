<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Cli;

use Nomen\Src\Database;
use Nomen\Src\Permissions;
use PDO;

/**
 * 命令行管权限：把自己锁在门外时，这里随时能救。
 *
 *   php bin/nomen group:assign  <账号> <组>     把用户加进组
 *   php bin/nomen group:remove  <账号> <组>     把用户移出组
 *   php bin/nomen group:list    [账号]          列组与成员数；给账号时列它所在的组
 *   php bin/nomen perms:list                   列出全部已注册能力
 */
class Groups
{
    public static function run(array $args): int
    {
        $action = $args[0] ?? '';

        $config = require dirname(__DIR__, 2) . '/config/app.php';

        try {
            $db = Database::connect($config['db']);
        } catch (\Throwable $e) {
            fwrite(STDERR, '连不上数据库：' . $e->getMessage() . PHP_EOL);
            return 1;
        }

        // 两张表不存在：迁移还没跑，先提示而不是硬失败
        try {
            $db->query('SELECT 1 FROM user_groups LIMIT 1');
            $db->query('SELECT 1 FROM group_rules LIMIT 1');
        } catch (\Throwable $e) {
            fwrite(STDERR, '权限表还不存在，先跑一次迁移：php bin/nomen migrate' . PHP_EOL);
            return 1;
        }

        switch ($action) {
            case 'assign':
            case 'remove':
                return self::change($db, $action, $args[1] ?? '', $args[2] ?? '');

            case 'list':
                return self::listing($db, $args[1] ?? '');

            case 'perms-list':
                return self::abilities();

            default:
                fwrite(STDERR, '用法：php bin/nomen group:<assign|remove|list> / perms:list' . PHP_EOL);
                return 1;
        }
    }

    private static function change(PDO $db, string $action, string $account, string $group): int
    {
        if ($account === '' || $group === '') {
            fwrite(STDERR, '用法：php bin/nomen group:' . $action . ' <用户名或邮箱> <组名>' . PHP_EOL);
            return 1;
        }
        if (!Permissions::validGroupName($group) || in_array($group, Permissions::IMPLICIT_GROUPS, true)) {
            fwrite(STDERR, '组名不合法（隐式组 * / user 不可指派）：' . $group . PHP_EOL);
            return 1;
        }

        $stmt = $db->prepare(
            'SELECT id, username FROM users WHERE LOWER(username) = LOWER(:a) OR LOWER(email) = LOWER(:a) LIMIT 1'
        );
        $stmt->execute([':a' => $account]);
        $user = $stmt->fetch();

        if (!$user) {
            fwrite(STDERR, '找不到账号：' . $account . PHP_EOL);
            return 1;
        }

        $userId = (int)$user['id'];
        $db->beginTransaction();

        try {
            if ($action === 'assign') {
                $upsert = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                    ? 'INSERT INTO user_groups (user_id, grp) VALUES (:u, :g) ON DUPLICATE KEY UPDATE grp = grp'
                    : 'INSERT INTO user_groups (user_id, grp) VALUES (:u, :g) ON CONFLICT(user_id, grp) DO NOTHING';
                $db->prepare($upsert)->execute([':u' => $userId, ':g' => $group]);
            } else {
                $db->prepare('DELETE FROM user_groups WHERE user_id = :u AND grp = :g')
                    ->execute([':u' => $userId, ':g' => $group]);
            }

            // role 快照与组保持一致（OAuth 令牌等旧路径还在看它）
            $isAdmin = false;
            $stmt = $db->prepare('SELECT 1 FROM user_groups WHERE user_id = :u AND grp = :g');
            $stmt->execute([':u' => $userId, ':g' => Permissions::ADMIN_GROUP]);
            $isAdmin = $stmt->fetchColumn() !== false;
            $db->prepare('UPDATE users SET role = :r WHERE id = :u')
                ->execute([':r' => $isAdmin ? 'admin' : 'user', ':u' => $userId]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            fwrite(STDERR, '写库失败：' . $e->getMessage() . PHP_EOL);
            return 1;
        }

        echo $action === 'assign'
            ? '已把 ' . $user['username'] . ' 加进 ' . $group . ' 组。' . PHP_EOL
            : '已把 ' . $user['username'] . ' 移出 ' . $group . ' 组。' . PHP_EOL;
        return 0;
    }

    private static function listing(PDO $db, string $account): int
    {
        if ($account !== '') {
            $stmt = $db->prepare(
                'SELECT id, username FROM users WHERE LOWER(username) = LOWER(:a) OR LOWER(email) = LOWER(:a) LIMIT 1'
            );
            $stmt->execute([':a' => $account]);
            $user = $stmt->fetch();

            if (!$user) {
                fwrite(STDERR, '找不到账号：' . $account . PHP_EOL);
                return 1;
            }

            $stmt = $db->prepare('SELECT grp, granted_at FROM user_groups WHERE user_id = :u ORDER BY grp');
            $stmt->execute([':u' => (int)$user['id']]);

            echo $user['username'], ' 的组：', PHP_EOL;
            echo '  *（隐式，所有人）', PHP_EOL;
            echo '  user（隐式，已登录）', PHP_EOL;
            foreach ($stmt->fetchAll() as $row) {
                echo '  ', $row['grp'], PHP_EOL;
            }
            return 0;
        }

        $defined = Permissions::groups();

        echo '组清单（members = 显式指派人数）：', PHP_EOL;
        foreach ($defined as $group) {
            $count = $group['implicit'] ? '隐式' : (string)Permissions::groupMemberCount($group['name']);
            $abilities = $group['implicit'] ? [] : array_keys(self::effectiveAbilities($group['name']));
            echo '  ', str_pad($group['name'], 20), str_pad((string)$count, 8),
                $abilities === [] ? '（无授权）' : implode(', ', $abilities), PHP_EOL;
        }

        return 0;
    }

    /** 组的生效能力（展开通配后按注册表过滤，仅展示用）。 */
    private static function effectiveAbilities(string $group): array
    {
        $out = [];
        foreach (array_keys(Permissions::abilities()) as $ability) {
            if (Permissions::groupAbility($group, $ability) === true) {
                $out[$ability] = true;
            }
        }

        return $out;
    }

    private static function abilities(): int
    {
        echo '已注册的能力（* = 核心）：', PHP_EOL;
        foreach (Permissions::abilities() as $name => $meta) {
            echo '  ', str_pad($name, 24), $meta['core'] ? '* ' : '  ', $meta['description'], PHP_EOL;
        }

        return 0;
    }
}

<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

/**
 * 权限判定（WordPress 的 current_user_can 思路：判定只认能力，不认角色）。
 *
 * 用法：
 *   Gate::can('users.manage')                 当前会话用户能不能管用户
 *   Gate::can('pages.edit', $page)             带上下文，插件注册的 checker 接管归属判定
 *   Gate::userCan($userId, 'logs.view')       指定用户（OAuth 令牌、后台、CLI）
 *   Gate::allows(...)                          模板友好别名
 *
 * 合并次序（MediaWiki 式组叠加 + WordPress 式动态能力）：
 *   1. 用户的所有组取并集；任一组 deny 该能力即拒绝，否则任一组 allow 即通过。
 *   2. 没有任何组提到这个能力 → 默认拒绝。
 *   3. 注册了 checker 的能力，组判定通过后还要过 checker（「是不是自己的记录」这类）。
 *   4. gate.check 过滤钩子最后再给插件一票（可否决，可放行）。
 *
 * 没跑权限迁移的库：users.role = 'admin' 的用户自动视同在 administrator 组，
 * 旧站点行为不变。判定按请求缓存，不进 session —— 撤权当前会话立即生效，
 * 这一点继承旧版 Router::isAdmin 的语义。
 */
final class Gate
{
    /** @var array<string, bool> 判定结果缓存，key = uid:ability:contextHash */
    private static array $cache = [];
    /** @var array<int, string[]|null> 用户组列表缓存（null 表示没查过） */
    private static array $groupCache = [];

    public static function can(string $ability, mixed ...$context): bool
    {
        return self::userCan((int)(Session::get('user_id') ?? 0), $ability, ...$context);
    }

    public static function allows(string $ability, mixed ...$context): bool
    {
        return self::can($ability, ...$context);
    }

    public static function denies(string $ability, mixed ...$context): bool
    {
        return !self::can($ability, ...$context);
    }

    public static function userCan(int $userId, string $ability, mixed ...$context): bool
    {
        $key = $userId . ':' . $ability . ':' . hash('sha256', serialize($context));
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $result = self::evaluate($userId, $ability, $context);

        // gate.check：最后允许插件改判（比如按 IP 段、按站点开关）
        $result = (bool)Hooks::filter('gate.check', $result, $ability, $userId, $context);

        return self::$cache[$key] = $result;
    }

    /**
     * 用户参与判定的全部组：
     * '*' 恒在（匿名也有）；有效登录再给 'user'；user_groups 表里的显式指派；
     * 旧库没跑迁移时 users.role = 'admin' 视同 administrator（兼容兜底，迁移后由行主导）。
     *
     * @return string[]
     */
    public static function userGroups(int $userId): array
    {
        if ($userId <= 0) {
            return ['*'];
        }

        if (array_key_exists($userId, self::$groupCache)) {
            return self::$groupCache[$userId];
        }

        $groups = ['*'];

        try {
            $db = Database::getInstance();

            $stmt = $db->prepare('SELECT role, status FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();

            if ($user !== false && (int)$user['status'] === 1) {
                $groups[] = 'user';

                // 停用的账号不再吃任何授权
                $stmt = $db->prepare('SELECT grp FROM user_groups WHERE user_id = :id');
                $stmt->execute([':id' => $userId]);
                foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $group) {
                    $groups[] = (string)$group;
                }

                // 兼容兜底：没跑迁移 / 老习惯只改 role 的站点
                if ($user['role'] === 'admin' && !in_array(Permissions::ADMIN_GROUP, $groups, true)) {
                    $groups[] = Permissions::ADMIN_GROUP;
                }
            }
        } catch (\Throwable $e) {
            // 两表不存在且 users 也不可用：只剩 '*'
        }

        return self::$groupCache[$userId] = array_values(array_unique($groups));
    }

    /** 清判定缓存（改组、改规则后调，单请求内也就几处）。 */
    public static function flush(): void
    {
        self::$cache = [];
        self::$groupCache = [];
    }

    private static function evaluate(int $userId, string $ability, array $context): bool
    {
        $allowed = false;

        foreach (self::userGroups($userId) as $group) {
            $verdict = Permissions::groupAbility($group, $ability);
            if ($verdict === false) {
                return false; // deny 盖过一切 allow
            }
            if ($verdict === true) {
                $allowed = true;
            }
        }

        if (!$allowed) {
            return false;
        }

        // 注册过 checker 的能力：带上下文判定「这条资源归不归我」
        if ($context !== []) {
            $checker = Permissions::checker($ability);
            if ($checker !== null && !$checker($userId, ...$context)) {
                return false;
            }
        }

        return true;
    }
}

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
 * 权限体系的登记处：能力注册、组定义合并、user_groups / group_rules 两张表的数据访问。
 *
 * 设计对应三个参考系统：
 * - 能力由核心和插件声明式注册（Flarum 式，Permissions::register）
 * - 组与默认授权写在 config/permissions.php（MediaWiki 式 $wgGroupPermissions）
 * - 判定只认能力不认角色（WordPress 式），判定本身在 Gate 里
 *
 * 合并次序：后台写进 group_rules 的规则覆盖 config 默认；判定取用户所有组的并集，
 * 任一组显式 deny 一律拒绝（Gate 负责）。本类只管数据，不做判定。
 */
final class Permissions
{
    /** @var array<string, array{label: string, description: string, checker: ?callable, core: bool}> */
    private static array $abilities = [];
    private static bool $coreRegistered = false;
    /** @var array<string, array<string, bool>>|null grp => ability => allowed（数据库规则，按请求缓存） */
    private static ?array $dbRules = null;

    /** 组名的规矩和插件 slug 一致：小写字母、数字、连字符 */
    private const GROUP_RE = '/^[a-z][a-z0-9-]{0,63}$/';
    public const IMPLICIT_GROUPS = ['*', 'user'];
    public const ADMIN_GROUP = 'administrator';

    /**
     * 插件注册一个能力（boot() 里调）。
     *
     * $meta: label（词典键）、description（词典键）。
     * $checker: fn(int $userId, mixed ...$context): bool —— 带上下文的判定回调，
     *           比如「这条记录是不是当前用户的」。不传就只看组授权。
     */
    public static function register(string $ability, array $meta = [], ?callable $checker = null): void
    {
        if (!preg_match('/^[a-z][a-z0-9.]*$/', $ability) || strlen($ability) > 128) {
            error_log('[Permissions] 能力名只能用小写字母、数字和点：' . $ability);
            return;
        }

        self::$abilities[$ability] = [
            'label'       => (string)($meta['label'] ?? $ability),
            'description' => (string)($meta['description'] ?? ''),
            'checker'     => $checker,
            'core'        => false,
        ];
    }

    /** @return array<string, array{label: string, description: string, checker: ?callable, core: bool}> */
    public static function abilities(): array
    {
        self::registerCore();
        return self::$abilities;
    }

    public static function has(string $ability): bool
    {
        return isset(self::abilities()[$ability]);
    }

    /** 某能力注册过的动态判定回调；没注册返回 null。 */
    public static function checker(string $ability): ?callable
    {
        return self::abilities()[$ability]['checker'] ?? null;
    }

    /** config/permissions.php 原文。文件缺失时给最小默认（administrator 全通）。 */
    public static function config(): array
    {
        $file = dirname(__DIR__) . '/config/permissions.php';
        $config = is_file($file) ? require $file : [];

        $groups = (array)($config['groups'] ?? []);
        $groups += [
            '*'            => ['label' => 'Everyone', 'implicit' => true, 'abilities' => []],
            'user'         => ['label' => 'Signed-in users', 'implicit' => true, 'abilities' => []],
            self::ADMIN_GROUP => ['label' => 'Administrators', 'abilities' => ['*']],
        ];

        return ['groups' => $groups];
    }

    /**
     * 网格与判定共用的组清单：config 里的组 + 数据库里出现过但 config 没写的组
     * （站长在后台建了组却忘了写配置，也能正常工作）。
     *
     * @return array<string, array{name: string, label: string, implicit: bool, abilities: array<string, bool>}>
     */
    public static function groups(): array
    {
        $out = [];

        foreach (self::config()['groups'] as $name => $def) {
            $out[(string)$name] = [
                'name'      => (string)$name,
                'label'     => (string)($def['label'] ?? $name),
                'implicit'  => !empty($def['implicit']) || in_array($name, self::IMPLICIT_GROUPS, true),
                'abilities' => self::normalizeAbilities($def['abilities'] ?? []),
            ];
        }

        foreach (array_keys(self::dbRules()) as $grp) {
            if (!isset($out[$grp])) {
                $out[$grp] = ['name' => $grp, 'label' => $grp, 'implicit' => false, 'abilities' => []];
            }
        }

        foreach (self::distinctAssignedGroups() as $grp) {
            if (!isset($out[$grp])) {
                $out[$grp] = ['name' => $grp, 'label' => $grp, 'implicit' => false, 'abilities' => []];
            }
        }

        ksort($out);
        return $out;
    }

    /**
     * 某组对某能力的授权，没规定过返回 null。
     * 数据库规则优先于 config 默认。
     */
    public static function groupAbility(string $group, string $ability): ?bool
    {
        $db = self::dbRules()[$group] ?? [];

        // 数据库里有这条就听数据库的（覆盖 config，后台是显式操作）
        if (array_key_exists($ability, $db)) {
            return $db[$ability];
        }

        // 数据库里允许的通配前缀（x.* / *）也按覆盖算
        $wild = self::matchWildcard($db, $ability);
        if ($wild !== null) {
            return $wild;
        }

        $def = self::config()['groups'][$group] ?? null;
        if ($def === null) {
            return null;
        }

        $abilities = self::normalizeAbilities($def['abilities'] ?? []);
        if (array_key_exists($ability, $abilities)) {
            return $abilities[$ability];
        }

        return self::matchWildcard($abilities, $ability);
    }

    /** 后台网格的角标用：这条授权是不是被数据库规则覆盖过（区别于 config 默认）。 */
    public static function hasDbRule(string $group, string $ability): bool
    {
        $db = self::dbRules()[$group] ?? [];

        if (array_key_exists($ability, $db)) {
            return true;
        }

        return self::matchWildcard($db, $ability) !== null;
    }

    /**
     * 后台网格写一条规则；$allowed 传 null 表示删掉规则、回落到 config 默认。
     * 隐式组（* / user）不可写：它们表达的是「谁都在 / 已登录」，不是可指派的身份。
     */
    public static function setRule(string $group, string $ability, ?bool $allowed): bool
    {
        if (!self::validGroupName($group) || in_array($group, self::IMPLICIT_GROUPS, true)) {
            return false;
        }
        if (!preg_match('/^[a-z][a-z0-9.*]*$/', $ability) || strlen($ability) > 128) {
            return false;
        }

        $db = Database::getInstance();

        if ($allowed === null) {
            $stmt = $db->prepare('DELETE FROM group_rules WHERE grp = :g AND ability = :a');
            $stmt->execute([':g' => $group, ':a' => $ability]);
        } else {
            self::upsert($db, $group, $ability, $allowed);
        }

        self::$dbRules = null;
        Gate::flush();

        return true;
    }

    /** =============== 用户与组（user_groups 表） =============== */

    /** @return string[] 用户被显式指派的组（不含隐式组，不含 role 兜底） */
    public static function assignedGroups(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $db = Database::getInstance();
        try {
            $stmt = $db->prepare('SELECT grp FROM user_groups WHERE user_id = :u ORDER BY grp');
            $stmt->execute([':u' => $userId]);
            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            // 迁移还没跑（两表不存在）：当它没有组，别把整个站点拖挂
            return [];
        }
    }

    /**
     * 全量替换某用户的组（后台用户详情页用）。
     * 隐式组不可指派；administrator 组同步进 users.role，保持两套写法一致。
     */
    public static function assignGroups(int $userId, array $groups, int $grantedBy = 0): bool
    {
        $groups = array_values(array_unique(array_filter(
            array_map('strval', $groups),
            static fn(string $g): bool => self::validGroupName($g) && !in_array($g, self::IMPLICIT_GROUPS, true)
        )));

        $isAdmin = in_array(self::ADMIN_GROUP, $groups, true);

        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            $db->prepare('DELETE FROM user_groups WHERE user_id = :u')->execute([':u' => $userId]);

            $stmt = $db->prepare('INSERT INTO user_groups (user_id, grp, granted_by) VALUES (:u, :g, :b)');
            foreach ($groups as $group) {
                $stmt->execute([':u' => $userId, ':g' => $group, ':b' => $grantedBy]);
            }

            // role 是兼容快照：OAuth 令牌路径、老插件都还在看它
            $db->prepare('UPDATE users SET role = :r WHERE id = :u')
                ->execute([':r' => $isAdmin ? 'admin' : 'user', ':u' => $userId]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            return false;
        }

        Gate::flush();
        return true;
    }

    /**
     * 单独加一个组。只加这一行，**不重算 role**（除非加的就是 administrator）——
     * 否则「给管理员加个客服组」会顺手把他降级。
     */
    public static function assignGroup(int $userId, string $group, int $grantedBy = 0): bool
    {
        if (!self::validGroupName($group) || in_array($group, self::IMPLICIT_GROUPS, true)) {
            return false;
        }

        try {
            $db = Database::getInstance();
            self::insertGroupRow($db, $userId, $group, $grantedBy);

            if ($group === self::ADMIN_GROUP) {
                $db->prepare('UPDATE users SET role = :r WHERE id = :u')
                    ->execute([':r' => 'admin', ':u' => $userId]);
            }
        } catch (\Throwable $e) {
            return false;
        }

        Gate::flush();
        return true;
    }

    /** 单独移出一个组；只有移出 administrator 时才把 role 快照降回 user。 */
    public static function removeGroup(int $userId, string $group): bool
    {
        try {
            $db = Database::getInstance();
            $db->prepare('DELETE FROM user_groups WHERE user_id = :u AND grp = :g')
                ->execute([':u' => $userId, ':g' => $group]);

            if ($group === self::ADMIN_GROUP) {
                $db->prepare('UPDATE users SET role = :r WHERE id = :u')
                    ->execute([':r' => 'user', ':u' => $userId]);
            }
        } catch (\Throwable $e) {
            return false;
        }

        Gate::flush();
        return true;
    }

    /** @return string[] 数据库里被指派过的组名（网格与 groups() 用） */
    public static function distinctAssignedGroups(): array
    {
        try {
            return Database::getInstance()
                ->query('SELECT DISTINCT grp FROM user_groups ORDER BY grp')
                ->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** 某组的成员数（网格页显示用）。 */
    public static function groupMemberCount(string $group): int
    {
        try {
            $stmt = Database::getInstance()->prepare('SELECT COUNT(*) FROM user_groups WHERE grp = :g');
            $stmt->execute([':g' => $group]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function validGroupName(string $group): bool
    {
        return (bool)preg_match(self::GROUP_RE, $group);
    }

    /** 清掉按请求缓存的数据库规则。Gate::flush() 会连带调用；测试也用它隔离用例。 */
    public static function flush(): void
    {
        self::$dbRules = null;
    }

    // =============== 内部 ===============

    /** 核心内置能力：按后台现有功能拆。label 是词典键，渲染时按语言翻。 */
    private static function registerCore(): void
    {
        if (self::$coreRegistered) {
            return;
        }
        self::$coreRegistered = true;

        $core = [
            'admin.access'      => ['Access the admin panel'],
            'users.manage'      => ['Manage users: list, details, enable, disable and roles'],
            'apps.manage'       => ['Manage OAuth apps'],
            'identities.manage' => ['Manage sign-in methods'],
            'mounts.manage'     => ['Manage endpoint paths'],
            'recovery.review'   => ['Review recovery requests'],
            'logs.view'         => ['View and export activity logs'],
            'site-health.view'  => ['View site health'],
            'settings.manage'   => ['Change site settings, clear cache and run scheduled tasks'],
            'updates.manage'    => ['Check, install and reset updates'],
            'homepage.manage'   => ['Edit the home page'],
            'plugins.manage'    => ['Manage plugins'],
        ];

        foreach ($core as $name => [$description]) {
            self::$abilities[$name] = [
                'label'       => $name,
                'description' => $description,
                'checker'     => null,
                'core'        => true,
            ];
        }
    }

    /**
     * @param array<string, bool> $rules
     */
    private static function matchWildcard(array $rules, string $ability): ?bool
    {
        $best = null;
        $bestLen = -1;

        foreach ($rules as $pattern => $allowed) {
            if ($pattern === $ability) {
                continue; // 精确匹配在调用方已处理
            }
            if ($pattern === '*') {
                if ($bestLen < 0) {
                    $best = (bool)$allowed;
                    $bestLen = 0;
                }
                continue;
            }
            if (str_ends_with($pattern, '.*')) {
                $prefix = substr($pattern, 0, -1); // 'users.' 形式
                if (str_starts_with($ability, $prefix) && strlen($prefix) > $bestLen) {
                    $best = (bool)$allowed;
                    $bestLen = strlen($prefix);
                }
            }
        }

        return $best;
    }

    /** @param array<string, bool> $rules */
    private static function normalizeAbilities(mixed $abilities): array
    {
        $out = [];
        foreach ((array)$abilities as $name => $value) {
            if (is_int($name)) {
                // ['users.manage', 'logs.view'] 简写
                $out[(string)$value] = true;
            } elseif (is_string($name)) {
                $out[$name] = (bool)$value;
            }
        }

        return $out;
    }

    /** @return array<string, array<string, bool>> */
    private static function dbRules(): array
    {
        if (self::$dbRules !== null) {
            return self::$dbRules;
        }

        $out = [];
        try {
            foreach (Database::getInstance()->query('SELECT grp, ability, allowed FROM group_rules') as $row) {
                $out[(string)$row['grp']][(string)$row['ability']] = (bool)(int)$row['allowed'];
            }
        } catch (\Throwable $e) {
            // 迁移没跑：当没有覆盖规则
        }

        return self::$dbRules = $out;
    }

    /** 写一行组归属，重复指派不报错。MySQL 与 SQLite 的 upsert 语法不同。 */
    private static function insertGroupRow(\PDO $db, int $userId, string $group, int $grantedBy): void
    {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $db->prepare(
                'INSERT INTO user_groups (user_id, grp, granted_by) VALUES (:u, :g, :b) '
                . 'ON DUPLICATE KEY UPDATE grp = grp'
            );
        } else {
            $stmt = $db->prepare(
                'INSERT INTO user_groups (user_id, grp, granted_by) VALUES (:u, :g, :b) '
                . 'ON CONFLICT(user_id, grp) DO NOTHING'
            );
        }

        $stmt->execute([':u' => $userId, ':g' => $group, ':b' => $grantedBy]);
    }

    /** MySQL 的 upsert 语法（ON DUPLICATE KEY）和 SQLite 的（ON CONFLICT）不一样。 */
    private static function upsert(\PDO $db, string $group, string $ability, bool $allowed): \PDOStatement
    {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $db->prepare(
                'INSERT INTO group_rules (grp, ability, allowed) VALUES (:g, :a, :v) '
                . 'ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)'
            );
            $stmt->execute([':g' => $group, ':a' => $ability, ':v' => (int)$allowed]);
        } else {
            $stmt = $db->prepare(
                'INSERT INTO group_rules (grp, ability, allowed) VALUES (:g, :a, :v) '
                . 'ON CONFLICT(grp, ability) DO UPDATE SET allowed = excluded.allowed'
            );
            $stmt->execute([':g' => $group, ':a' => $ability, ':v' => (int)$allowed]);
        }

        return $stmt;
    }
}

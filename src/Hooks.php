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
 * 钩子注册表：run 是事件、filter 是取值，另外记录「谁订阅了」「谁定义了这个钩子」。
 *
 * 插件可以用 define() 声明自己会触发的钩子（清单 provides 就是这个的语法糖），
 * 核心和插件都能用 alias() 把旧名字映射到新名字，老插件不用跟着改。
 */
class Hooks
{
    /** @var array<string, array<int, array{cb: callable, priority: int, source: string, seq: int}>> */
    private static array $handlers = [];

    /** @var array<string, array{type: string, args: mixed, description: string, owner: string, since: string}> */
    private static array $definitions = [];

    /** @var array<string, string> 旧钩子名 => 新钩子名 */
    private static array $aliases = [];

    /** @var array<string, array{replacement: string, since: string}> */
    private static array $deprecated = [];

    /** @var array<string, true> */
    private static array $warned = [];

    /** @var array<string, true> */
    private static array $typeWarned = [];

    /** @var array<string, array{type: string, args: mixed, description: string, owner: string, since: string}>|null */
    private static ?array $catalog = null;

    private static int $seq = 0;

    public static function add(string $event, callable $callback, int $priority = 10, string $source = 'core'): void
    {
        self::warnIfDeprecated($event);

        self::$handlers[$event][] = [
            'cb'       => $callback,
            'priority' => $priority,
            'source'   => $source,
            'seq'      => ++self::$seq,
        ];
    }

    public static function has(string $event): bool
    {
        return self::sorted($event) !== [];
    }

    /**
     * 事件钩子：按优先级依次调用。回调返回 false 可以中断整条链；
     * $abortable = false 的地方（登录、授权这类关键路径）中断会直接抛异常。
     */
    public static function run(string $event, array $args = [], bool $abortable = true): bool
    {
        self::checkType($event, 'run');

        foreach (self::sorted($event) as $handler) {
            $result = ($handler['cb'])(...$args);

            if ($result === false) {
                if (!$abortable) {
                    throw new \RuntimeException('钩子 ' . $event . ' 不允许中断');
                }
                return false;
            }

            if ($result !== null && $result !== true) {
                throw new \UnexpectedValueException('钩子 ' . $event . ' 的回调只能返回 null / true / false');
            }
        }

        return true;
    }

    /**
     * 取值钩子：回调拿到当前值，返回非 null 就替换它（加 claim、改文案这类用这个）。
     */
    public static function filter(string $event, mixed $value, mixed ...$context): mixed
    {
        self::checkType($event, 'filter');

        foreach (self::sorted($event) as $handler) {
            $result = ($handler['cb'])($value, ...$context);
            if ($result !== null) {
                $value = $result;
            }
        }

        return $value;
    }

    /**
     * 登记钩子的说明（类型、参数、谁提供的）。核心和插件都能调用；
     * 插件必须把钩子名放在自己的 slug 前缀下，避免和核心 / 别的插件撞名。
     *
     * @param array{type?: string, args?: mixed, description?: string, owner?: string, since?: string} $meta
     * @return bool 名字不合法或和别人的定义冲突时返回 false
     */
    public static function define(string $event, array $meta = []): bool
    {
        $event = strtolower(trim($event));
        if (!self::validName($event)) {
            error_log('[Hooks] 钩子名不合法：' . $event);
            return false;
        }

        $owner = (string)($meta['owner'] ?? 'core');
        if ($owner !== 'core' && !str_starts_with($event, $owner . '.')) {
            error_log('[Hooks] ' . $owner . ' 声明的钩子 ' . $event . ' 必须以 ' . $owner . '. 开头');
            return false;
        }

        $type = strtolower(trim((string)($meta['type'] ?? '')));
        if ($type !== '' && !in_array($type, ['run', 'filter'], true)) {
            error_log('[Hooks] 钩子 ' . $event . ' 的 type 只能是 run / filter：' . $type);
            return false;
        }

        $definition = [
            'type'        => $type,
            'args'        => $meta['args'] ?? '',
            'description' => (string)($meta['description'] ?? ''),
            'owner'       => $owner,
            'since'       => (string)($meta['since'] ?? ''),
        ];

        $existing = self::$definitions[$event] ?? null;
        if ($existing !== null && $existing !== $definition && $existing['owner'] !== $owner) {
            error_log('[Hooks] 钩子 ' . $event . ' 已被 ' . $existing['owner'] . ' 定义，' . $owner . ' 不能再定义');
            return false;
        }

        self::$definitions[$event] = $definition;

        return true;
    }

    /**
     * 把旧钩子名映射到新钩子名。两个方向都生效：触发新名字时旧监听者照跑，
     * 触发旧名字时新监听者也照跑。参数必须保持兼容，否则老插件会拿到错位的数据。
     */
    public static function alias(string $old, string $new, string $since = ''): bool
    {
        $old = strtolower(trim($old));
        $new = strtolower(trim($new));

        if (!self::validName($old) || !self::validName($new) || $old === $new) {
            error_log('[Hooks] 别名不合法：' . $old . ' -> ' . $new);
            return false;
        }

        if (self::canonicalName($new) === $old) {
            error_log('[Hooks] 别名会形成环：' . $old . ' -> ' . $new);
            return false;
        }

        self::$aliases[$old] = $new;
        self::$deprecated[$old] = ['replacement' => $new, 'since' => $since];

        return true;
    }

    /** @return array<string, array{type: string, args: mixed, description: string, owner: string, since: string}> */
    public static function definitions(): array
    {
        return array_replace(self::catalog(), self::$definitions);
    }

    /** 某个钩子的定义；旧名字会顺着别名找到新名字的定义。 */
    public static function describe(string $event): ?array
    {
        return self::definitions()[self::canonicalName($event)] ?? null;
    }

    /** @return array<string, array{replacement: string, since: string}> */
    public static function deprecated(): array
    {
        return self::$deprecated;
    }

    /**
     * 已注册的监听者（按事件分组，不含回调本身，给 CLI / 排错用）。
     *
     * @return array<string, array<int, array{priority: int, source: string}>>
     */
    public static function listeners(): array
    {
        $out = [];

        foreach (self::$handlers as $event => $list) {
            $canonical = self::canonicalName($event);
            foreach ($list as $handler) {
                $out[$canonical][] = [
                    'priority' => $handler['priority'],
                    'source'   => $handler['source'],
                ];
            }
        }

        ksort($out);

        return $out;
    }

    /** @return string[] 有监听者或有定义的钩子名（别名已归一到新名字） */
    public static function events(): array
    {
        $names = array_merge(array_keys(self::$handlers), array_keys(self::definitions()));

        foreach (self::$aliases as $old => $new) {
            $names[] = $old;
            $names[] = $new;
        }

        $names = array_map([self::class, 'canonicalName'], $names);
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /** 旧名字顺着别名链找到当前名字；不是别名就原样返回。 */
    public static function canonicalName(string $event): string
    {
        $seen = [];

        while (isset(self::$aliases[$event]) && !isset($seen[$event])) {
            $seen[$event] = true;
            $event = self::$aliases[$event];
        }

        return $event;
    }

    /** @return array<int, array{cb: callable, priority: int, source: string, seq: int}> */
    private static function sorted(string $event): array
    {
        self::warnIfDeprecated($event);

        $canonical = self::canonicalName($event);
        $names = [$canonical => true];

        // 所有映射到同一个当前名字的旧名字，一起收进来
        foreach (self::$aliases as $old => $new) {
            if (self::canonicalName($old) === $canonical) {
                $names[$old] = true;
            }
        }

        $list = [];
        foreach (array_keys($names) as $name) {
            foreach (self::$handlers[$name] ?? [] as $handler) {
                $list[] = $handler;
            }
        }

        usort($list, static fn(array $a, array $b): int => [$a['priority'], $a['seq']] <=> [$b['priority'], $b['seq']]);

        return $list;
    }

    private static function validName(string $event): bool
    {
        return (bool)preg_match('/^[a-z0-9][a-z0-9_-]*(\.[a-z0-9][a-z0-9_-]*)+$/', $event);
    }

    private static function warnIfDeprecated(string $event): void
    {
        $info = self::$deprecated[$event] ?? null;
        if ($info === null || isset(self::$warned[$event])) {
            return;
        }

        self::$warned[$event] = true;
        error_log(
            '[Hooks] 钩子 ' . $event . ' 已弃用，改用 ' . $info['replacement']
            . ($info['since'] !== '' ? '（自 ' . $info['since'] . ' 起）' : '')
        );
    }

    /**
     * 核心钩子目录（由 docgen 从 hooks.json 生成）；首次用到时加载。
     *
     * @return array<string, array{type: string, args: mixed, description: string, owner: string, since: string}>
     */
    private static function catalog(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }

        self::$catalog = [];
        $file = __DIR__ . '/HookCatalog.php';
        if (!is_file($file)) {
            return self::$catalog;
        }

        $data = require $file;
        if (!is_array($data)) {
            return self::$catalog;
        }

        foreach ($data as $event => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            self::$catalog[(string)$event] = [
                'type'        => (string)($meta['type'] ?? ''),
                'args'        => $meta['args'] ?? '',
                'description' => '',
                'owner'       => 'core',
                'since'       => '',
            ];
        }

        return self::$catalog;
    }

    /** 声明了类型（run / filter）的钩子被按另一种方式触发时，记一条日志。 */
    private static function checkType(string $event, string $expected): void
    {
        $definition = self::$definitions[self::canonicalName($event)] ?? null;
        if ($definition === null || $definition['type'] === '' || $definition['type'] === $expected) {
            return;
        }

        $key = $event . ':' . $expected;
        if (isset(self::$typeWarned[$key])) {
            return;
        }

        self::$typeWarned[$key] = true;
        error_log(
            '[Hooks] 钩子 ' . $event . ' 定义成 ' . $definition['type'] . '，却按 ' . $expected . ' 触发'
        );
    }
}

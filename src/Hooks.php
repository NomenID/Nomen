<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

class Hooks
{
    /** @var array<string, array<int, array{cb: callable, priority: int}>> */
    private static array $handlers = [];

    public static function add(string $event, callable $callback, int $priority = 10): void
    {
        self::$handlers[$event][] = ['cb' => $callback, 'priority' => $priority];
    }

    public static function has(string $event): bool
    {
        return !empty(self::$handlers[$event]);
    }

    /**
     * 事件钩子：按优先级依次调用。回调返回 false 可以中断整条链；
     * $abortable = false 的地方（登录、授权这类关键路径）中断会直接抛异常。
     */
    public static function run(string $event, array $args = [], bool $abortable = true): bool
    {
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
        foreach (self::sorted($event) as $handler) {
            $result = ($handler['cb'])($value, ...$context);
            if ($result !== null) {
                $value = $result;
            }
        }

        return $value;
    }

    /** @return array<int, array{cb: callable, priority: int}> */
    private static function sorted(string $event): array
    {
        $list = self::$handlers[$event] ?? [];
        usort($list, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $list;
    }
}

<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Cli;

use Nomen\Src\Hooks as Registry;
use Nomen\Src\Plugins;

/**
 * `php bin/nomen hooks [事件]`：看谁订阅了哪个钩子、插件声明了哪些钩子、哪些钩子已经弃用。
 *
 * 核心钩子的完整清单在 docs/hooks/（由 docgen 生成）；这里看的是**运行时**状态。
 */
class Hooks
{
    public static function run(array $args): int
    {
        Plugins::boot();

        $event = trim((string)($args[0] ?? ''));

        return $event === '' ? self::overview() : self::show($event);
    }

    private static function overview(): int
    {
        $events = Registry::events();
        $definitions = Registry::definitions();
        $listeners = Registry::listeners();

        if ($events === []) {
            echo '没有已注册的钩子。', PHP_EOL;
            return 0;
        }

        echo '钩子（有监听者或有定义的）：', PHP_EOL;

        foreach ($events as $event) {
            $definition = $definitions[$event] ?? null;
            $owner = (string)($definition['owner'] ?? '');
            $description = (string)($definition['description'] ?? '');

            echo '  ', $event;
            if ($owner !== '' && $owner !== 'core') {
                echo '  [由 ', $owner, ' 提供]';
            }
            if ($description !== '') {
                echo '  ', $description;
            }
            echo PHP_EOL;

            foreach ($listeners[$event] ?? [] as $listener) {
                echo '      - ', self::source($listener['source']), '（优先级 ', $listener['priority'], '）', PHP_EOL;
            }
        }

        $deprecated = Registry::deprecated();
        if ($deprecated !== []) {
            echo PHP_EOL, '已弃用的钩子：', PHP_EOL;
            foreach ($deprecated as $old => $info) {
                echo '  ', $old, ' -> ', $info['replacement'];
                if ($info['since'] !== '') {
                    echo '（自 ', $info['since'], ' 起）';
                }
                echo PHP_EOL;
            }
        }

        return 0;
    }

    private static function show(string $event): int
    {
        $canonical = Registry::canonicalName($event);
        $definition = Registry::describe($event);
        $listeners = Registry::listeners()[$canonical] ?? [];

        if ($definition === null && $listeners === [] && !isset(Registry::deprecated()[$event])) {
            fwrite(STDERR, '找不到钩子：' . $event . PHP_EOL);
            return 1;
        }

        if ($canonical !== $event) {
            echo $event, '（已弃用，当前名字：', $canonical, '）', PHP_EOL;
        } else {
            echo $canonical, PHP_EOL;
        }

        if ($definition !== null) {
            if ($definition['type'] !== '') {
                echo '  类型：', $definition['type'], PHP_EOL;
            }
            if ($definition['args'] !== '' && $definition['args'] !== []) {
                echo '  参数：', is_array($definition['args']) ? implode(', ', $definition['args']) : (string)$definition['args'], PHP_EOL;
            }
            if ($definition['description'] !== '') {
                echo '  说明：', $definition['description'], PHP_EOL;
            }
            echo '  提供者：', self::source($definition['owner']), PHP_EOL;
        }

        if ($listeners === []) {
            echo '  监听者：无', PHP_EOL;
            return 0;
        }

        echo '  监听者：', PHP_EOL;
        foreach ($listeners as $listener) {
            echo '    - ', self::source($listener['source']), '（优先级 ', $listener['priority'], '）', PHP_EOL;
        }

        return 0;
    }

    private static function source(string $source): string
    {
        return $source === '' ? 'core' : $source;
    }
}

<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Cli;

use Nomen\Src\PluginMigrator;
use Nomen\Src\Plugins;

class Plugin
{
    public static function run(array $args): int
    {
        Plugins::boot();

        $sub = $args[0] ?? 'list';

        if ($sub === 'run') {
            return self::runCommand((string)($args[1] ?? ''));
        }
        if (isset(Plugins::commands()[$sub])) {
            return self::runCommand($sub);
        }

        return match ($sub) {
            'list'    => self::list(),
            'migrate' => self::migrate($args[1] ?? null),
            default   => self::unknown($sub),
        };
    }

    private static function list(): int
    {
        $all = Plugins::all();
        $enabled = Plugins::enabled();
        $loaded = Plugins::loaded();

        if ($all === []) {
            echo 'plugins/ 下没有插件。', PHP_EOL;
            return 0;
        }

        foreach ($all as $slug => $manifest) {
            $state = in_array($slug, $enabled, true)
                ? (isset($loaded[$slug]) ? '启用' : '启用但加载失败')
                : '未启用';
            echo sprintf('  %-16s %-8s %s', $slug, (string)($manifest['version'] ?? ''), $state), PHP_EOL;
            if (!empty($manifest['description'])) {
                echo '      ', $manifest['description'], PHP_EOL;
            }
        }

        $commands = Plugins::commands();
        if ($commands !== []) {
            echo PHP_EOL, '插件命令：', PHP_EOL;
            foreach ($commands as $name => $command) {
                echo '  ', $name, '  ', $command['description'], PHP_EOL;
            }
        }

        return 0;
    }

    private static function runCommand(string $name): int
    {
        $command = Plugins::commands()[$name] ?? null;
        if ($command === null) {
            fwrite(STDERR, '找不到插件命令：' . $name . PHP_EOL);
            return 1;
        }

        $result = ($command['handler'])();
        if (is_string($result) && $result !== '') {
            echo $result, PHP_EOL;
        } elseif (is_int($result)) {
            echo '完成（' . $result . '）', PHP_EOL;
        }

        return 0;
    }

    private static function migrate(?string $slug): int
    {
        if ($slug !== null && !isset(Plugins::all()[$slug])) {
            fwrite(STDERR, '找不到插件：' . $slug . PHP_EOL);
            return 1;
        }

        $result = PluginMigrator::run($slug);

        if ($result['errors'] !== []) {
            foreach ($result['errors'] as $error) {
                fwrite(STDERR, '  ' . $error . PHP_EOL);
            }
            return 1;
        }

        echo $result['applied'] === 0
            ? '没有待应用的插件迁移。' . PHP_EOL
            : '应用了 ' . $result['applied'] . ' 个插件迁移。' . PHP_EOL;

        return 0;
    }

    private static function unknown(string $sub): int
    {
        fwrite(STDERR, '未知的 plugins 子命令：' . $sub . PHP_EOL);

        return 1;
    }
}

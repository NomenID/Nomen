<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Cli;

class Upgrade
{
    public static function run(array $args): int
    {
        echo '核心迁移：', PHP_EOL;
        $code = Migrate::run($args);
        if ($code !== 0) {
            return $code;
        }

        echo '插件迁移：', PHP_EOL;

        return Plugin::run(['migrate']);
    }
}

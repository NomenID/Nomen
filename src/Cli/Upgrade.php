<?php
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

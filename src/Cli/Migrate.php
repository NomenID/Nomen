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
use Nomen\Src\Installer\Database as InstallDb;
use Nomen\Src\Unid;
use Nomen\Src\Upgrade as AppUpgrade;
use PDO;
use PDOException;

class Migrate
{
    public static function run(array $args): int
    {
        $root = dirname(__DIR__, 2);

        // 与 Web 入口一致：.env 或 config/installed.php 任一在就算装过
        if (!file_exists($root . '/.env') && !file_exists($root . '/config/installed.php')) {
            fwrite(STDERR, '还没装：先跑 php bin/nomen install' . PHP_EOL);
            return 1;
        }

        $config = require $root . '/config/app.php';

        try {
            $pdo = Database::connect($config['db']);
        } catch (\Throwable $e) {
            fwrite(STDERR, '连不上数据库：' . $e->getMessage() . PHP_EOL);
            return 1;
        }

        InstallDb::createMigrationsTable($pdo);

        $driver = $pdo->dialect()->driver();
        $done = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob($root . '/database/migrations/*.sql') ?: [];
        sort($files);

        $applied = 0;
        foreach ($files as $file) {
            $name = basename($file);
            if (!InstallDb::migrationApplies($name, $driver)) {
                continue;
            }

            // 旧版迁移文件不带方言后缀，改名后已经跑过的那条别重复执行
            $legacy = (string)preg_replace('/\.(mysql|sqlite)\.sql$/', '.sql', $name);
            if (in_array($name, $done, true) || in_array($legacy, $done, true)) {
                continue;
            }

            echo '  应用 ', $name, ' ... ';
            try {
                // 迁移里的 DDL 没有跨语句事务可依赖；出错就停，别继续往后跑。
                foreach (Sql::splitStatements((string)file_get_contents($file)) as $statement) {
                    $pdo->exec($statement);
                }
                $pdo->prepare('INSERT INTO schema_migrations (filename, applied_at) VALUES (?, NOW())')
                    ->execute([$name]);
            } catch (PDOException $e) {
                echo '失败', PHP_EOL;
                fwrite(STDERR, '  ' . $e->getMessage() . PHP_EOL);
                fwrite(STDERR, '  这个文件可能只跑了一半，核对后再手动补上。' . PHP_EOL);
                return 1;
            }

            echo 'ok', PHP_EOL;
            $applied++;
        }

        $backfilled = Unid::backfill($pdo);
        if ($backfilled > 0) {
            echo '  补齐 UNID：', $backfilled, ' 行', PHP_EOL;
        }

        AppUpgrade::record($pdo, (string)($config['version'] ?? ''));

        echo $applied === 0 ? '没有待应用的迁移。' : '应用了 ' . $applied . ' 个迁移。', PHP_EOL;
        echo '库记录的应用版本已更新为 ', AppUpgrade::codeVersion(), '。', PHP_EOL;

        return 0;
    }
}

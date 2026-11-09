<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

use Nomen\Src\Db\Connection;
use Nomen\Src\Db\Dialect;
use PDO;
use PDOException;

class Database
{
    // 测试会把一个普通 PDO 塞进来，所以不能收窄成 Connection
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config/app.php';
            // 连接失败就让 PDOException 抛给全局报错页：细节进日志，访客只看到干净的 500
            self::$instance = self::connect($config['db']);
        }

        return self::$instance;
    }

    /** @throws PDOException */
    public static function connect(array $db): Connection
    {
        $driver = Dialect::normalize((string)($db['driver'] ?? Dialect::MYSQL));

        if ($driver === Dialect::SQLITE) {
            $path = self::sqlitePath((string)($db['path'] ?? ''));
            if (!is_file($path)) {
                throw new PDOException('SQLite 数据库文件不存在：' . $path);
            }

            $connection = new Connection('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ], $driver);

            $connection->exec('PRAGMA foreign_keys = ON');
            $connection->exec('PRAGMA busy_timeout = 5000');
            // WAL 写在库文件里，设过一次就一直有效；文件系统不支持就算了
            try {
                $connection->exec('PRAGMA journal_mode = WAL');
            } catch (\Throwable) {
            }

            return $connection;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'],
            (int)$db['port'],
            $db['name']
        );

        return new Connection($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ], $driver);
    }

    public static function dialectFor(PDO $db): Dialect
    {
        if ($db instanceof Connection) {
            return $db->dialect();
        }

        // 测试/外部注入的普通 PDO：按驱动名推断，够用
        return new Dialect(
            $db->getAttribute(PDO::ATTR_DRIVER_NAME) === Dialect::SQLITE ? Dialect::SQLITE : Dialect::MYSQL
        );
    }

    public static function dialect(): Dialect
    {
        return self::dialectFor(self::getInstance());
    }

    /** 相对路径按项目根解析；Windows 盘符和 Unix 绝对路径原样用 */
    public static function sqlitePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            $path = 'database/nomen.sqlite';
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return dirname(__DIR__) . '/' . ltrim($path, '/\\');
    }
}

<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Installer;

use Nomen\Src\Cli\Sql;
use Nomen\Src\Db\Connection;
use Nomen\Src\Upgrade as AppUpgrade;
use Nomen\Src\Db\Dialect;
use Nomen\Src\Database as AppDatabase;
use PDO;
use PDOException;

class Database
{
    /** @throws PDOException */
    public static function connect(array $db, bool $withName = true): Connection
    {
        $driver = Dialect::normalize((string)($db['driver'] ?? Dialect::MYSQL));

        if ($driver === Dialect::SQLITE) {
            $path = AppDatabase::sqlitePath((string)($db['path'] ?? ''));
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new PDOException('建不了目录：' . $dir);
            }
            if (!is_file($path)) {
                touch($path);
            }

            $pdo = new Connection('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ], $driver);
            $pdo->exec('PRAGMA busy_timeout = 5000');

            return $pdo;
        }

        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], (int)$db['port']);
        if ($withName) {
            $dsn .= ';dbname=' . $db['name'];
        }

        return new Connection($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ], $driver);
    }

    /** @return string[] 已有表名 */
    public static function tables(Connection $pdo): array
    {
        return $pdo->dialect()->tables($pdo);
    }

    public static function importSchema(Connection $pdo, string $file): int
    {
        $statements = Sql::splitStatements((string)file_get_contents($file));

        if ($pdo->dialect()->isSqlite()) {
            // 带外键的 DROP/CREATE 顺序不可控，导表期间先关掉检查
            $pdo->exec('PRAGMA foreign_keys = OFF');
        }

        try {
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
        } finally {
            if ($pdo->dialect()->isSqlite()) {
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
        }

        return count($statements);
    }

    public static function createAdmin(Connection $pdo, string $username, string $email, string $password, string $pepper): void
    {
        $pdo->beginTransaction();

        $pdo->prepare(
            'INSERT INTO users (unid, username, email, email_verified, password_hash, display_name, role, status)
             VALUES (?, ?, ?, 1, ?, ?, \'admin\', 1)'
        )->execute([
            \Nomen\Src\Unid::generate(),
            $username,
            $email,
            password_hash($password . $pepper, PASSWORD_ARGON2ID),
            $username,
        ]);

        $userId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO user_emails (user_id, email, is_primary, verified, verified_at)
             VALUES (?, ?, 1, 1, NOW())'
        )->execute([$userId, $email]);

        $pdo->commit();
    }

    public static function createMigrationsTable(Connection $pdo): void
    {
        if ($pdo->dialect()->isSqlite()) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS schema_migrations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    filename TEXT NOT NULL UNIQUE,
                    applied_at TEXT NOT NULL DEFAULT ''
                )"
            );

            return;
        }

        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            id int unsigned NOT NULL AUTO_INCREMENT,
            filename varchar(255) NOT NULL,
            applied_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_filename (filename)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /** 刚装完的库已经是最终结构，把现有迁移都标成已应用，免得 migrate 去重复加列 */
    public static function markMigrationsApplied(Connection $pdo, string $dir): int
    {
        self::createMigrationsTable($pdo);

        $files = glob($dir . '/*.sql') ?: [];
        sort($files);

        $insert = $pdo->prepare('INSERT INTO schema_migrations (filename, applied_at) VALUES (?, NOW())');
        $marked = 0;
        foreach ($files as $file) {
            $name = basename($file);
            if (!self::migrationApplies($name, $pdo->dialect()->driver())) {
                continue;
            }
            try {
                $insert->execute([$name]);
                $marked++;
            } catch (PDOException $e) {
                // 唯一键冲突说明已经记过了
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        // 刚装完的库就是这个版本的最终结构，顺手记下版本，免得后台一上来就提示「需要升级」
        AppUpgrade::record($pdo);

        return $marked;
    }

    /** 迁移文件按后缀挑方言：foo.mysql.sql / foo.sqlite.sql / foo.sql（通用） */
    public static function migrationApplies(string $filename, string $driver): bool
    {
        if (str_ends_with($filename, '.mysql.sql')) {
            return $driver === Dialect::MYSQL;
        }
        if (str_ends_with($filename, '.sqlite.sql')) {
            return $driver === Dialect::SQLITE;
        }

        return str_ends_with($filename, '.sql');
    }
}

<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Db;

use InvalidArgumentException;
use PDO;

/**
 * 每种数据库的差异只在这里出现。业务代码写可移植 SQL，非 portable 的那几句
 * （upsert、INSERT IGNORE、FOR UPDATE、日期函数）向这里要现成片段。
 */
final class Dialect
{
    public const MYSQL = 'mysql';
    public const SQLITE = 'sqlite';

    private string $driver;

    public function __construct(string $driver)
    {
        $this->driver = self::normalize($driver);
    }

    /** mariadb 和 mysql 走同一套 SQL，只是对外名字保留 */
    public static function normalize(string $driver): string
    {
        $driver = strtolower(trim($driver));

        if ($driver === '' || $driver === 'mariadb') {
            return self::MYSQL;
        }
        if ($driver !== self::MYSQL && $driver !== self::SQLITE) {
            throw new InvalidArgumentException('不支持的数据库类型：' . $driver);
        }

        return $driver;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function isSqlite(): bool
    {
        return $this->driver === self::SQLITE;
    }

    public function now(): string
    {
        return $this->isSqlite() ? "datetime('now','localtime')" : 'NOW()';
    }

    public function nowMinusDays(int $days): string
    {
        $days = max(0, $days);

        return $this->isSqlite()
            ? "datetime('now','localtime','-{$days} days')"
            : "NOW() - INTERVAL {$days} DAY";
    }

    /** 当前时间减 $expr 的秒数，时区口径与库里的时间戳保持一致 */
    public function epochDiff(string $expr): string
    {
        return $this->isSqlite()
            ? "CAST(strftime('%s','now','localtime') AS INTEGER) - CAST(strftime('%s',{$expr}) AS INTEGER)"
            : "UNIX_TIMESTAMP() - UNIX_TIMESTAMP({$expr})";
    }

    /** @param string[] $conflictColumns SQLite 必须知道撞的是哪个唯一约束 */
    public function upsert(array $conflictColumns, string $assignments): string
    {
        if ($this->isSqlite()) {
            return 'ON CONFLICT (' . implode(', ', $conflictColumns) . ') DO UPDATE SET ' . $assignments;
        }

        return 'ON DUPLICATE KEY UPDATE ' . $assignments;
    }

    public function insertIgnore(string $sql): string
    {
        if (!$this->isSqlite()) {
            return $sql;
        }

        return (string)preg_replace('/^INSERT\s+IGNORE\s+INTO/i', 'INSERT OR IGNORE INTO', $sql);
    }

    public function forUpdate(): string
    {
        // SQLite 写事务本来就是串行的，没有行锁这回事
        return $this->isSqlite() ? '' : ' FOR UPDATE';
    }

    /** @return string[] 已有表名 */
    public function tables(PDO $pdo): array
    {
        if ($this->isSqlite()) {
            return $pdo->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            )->fetchAll(PDO::FETCH_COLUMN);
        }

        return $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function tableExists(PDO $pdo, string $table): bool
    {
        if ($this->isSqlite()) {
            $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        } else {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
        }
        $stmt->execute([$table]);

        return (bool)$stmt->fetchColumn();
    }

    public function serverVersion(PDO $pdo): string
    {
        return $this->isSqlite()
            ? 'SQLite ' . $pdo->query('SELECT sqlite_version()')->fetchColumn()
            : (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    }

    public function schemaFile(string $root): string
    {
        return $root . ($this->isSqlite() ? '/database/schema.sqlite.sql' : '/database/schema.sql');
    }
}

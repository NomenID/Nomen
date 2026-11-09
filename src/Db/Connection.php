<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Db;

use PDO;
use PDOStatement;

/**
 * 只有一个小魔法：SQLite 上没有 NOW()，按方言换成 datetime('now','localtime')。
 * MySQL / MariaDB 原样保留，SQL 和以前完全一样。
 */
class Connection extends PDO
{
    private Dialect $dialect;

    public function __construct(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
        ?string $driver = null
    ) {
        $this->dialect = new Dialect($driver ?? (str_starts_with($dsn, 'sqlite:') ? Dialect::SQLITE : Dialect::MYSQL));
        parent::__construct($dsn, $username, $password, $options);
    }

    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->rewrite($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return parent::query($this->rewrite($query), $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec($this->rewrite($statement));
    }

    private function rewrite(string $sql): string
    {
        if (!$this->dialect->isSqlite() || stripos($sql, 'NOW()') === false) {
            return $sql;
        }

        return (string)preg_replace('/\bNOW\s*\(\s*\)/i', $this->dialect->now(), $sql);
    }
}

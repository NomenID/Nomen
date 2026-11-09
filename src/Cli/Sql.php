<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src\Cli;

class Sql
{
    /**
     * 把一份 .sql 文件切成单条语句。引号和反引号内的分号不算分隔符。
     * CREATE TRIGGER 的 BEGIN ... END 体内也有分号，只有 END 后面那个才算结束。
     *
     * @return string[]
     */
    public static function splitStatements(string $sql): array
    {
        $out = [];
        $buf = '';
        $quote = null;
        $len = strlen($sql);
        $isTrigger = false;

        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];

            if ($quote !== null) {
                $buf .= $c;
                if ($c === '\\' && $quote !== '`' && $i + 1 < $len) {
                    $buf .= $sql[++$i];
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buf .= $c;
                continue;
            }

            // 行注释（-- 后面跟空白，或 #）整行丢掉。`/*! ... */` 是可执行注释，不能丢。
            if (($c === '-' && $i + 1 < $len && $sql[$i + 1] === '-' &&
                 ($i + 2 >= $len || ctype_space($sql[$i + 2]))) || $c === '#') {
                $nl = strpos($sql, "\n", $i);
                if ($nl === false) {
                    break;
                }
                $i = $nl;
                continue;
            }

            if ($c === ';') {
                if ($isTrigger && !preg_match('/\bEND\s*$/i', $buf)) {
                    $buf .= $c;
                    continue;
                }

                $stmt = trim($buf);
                if ($stmt !== '') {
                    $out[] = $stmt;
                }
                $buf = '';
                $isTrigger = false;
                continue;
            }

            $buf .= $c;

            if (!$isTrigger && preg_match('/^\s*CREATE\s+(?:TEMP\s+|TEMPORARY\s+)?TRIGGER\s/i', $buf)) {
                $isTrigger = true;
            }
        }

        $stmt = trim($buf);
        if ($stmt !== '') {
            $out[] = $stmt;
        }

        return $out;
    }
}

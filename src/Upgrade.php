<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

use PDO;
use PDOException;

/**
 * 代码版本与库里记录的版本。
 *
 * 只提示、不拦请求：覆盖了文件却忘了跑迁移，用户碰到的是某条 SQL 报错，
 * 后台和 CLI 直接说「跑 upgrade」比让人反推快。
 */
class Upgrade
{
    private const KEY = 'app_version';

    public static function codeVersion(): string
    {
        $config = require dirname(__DIR__) . '/config/app.php';

        return (string)($config['version'] ?? '0');
    }

    /** 老库还没有 app_meta 表，读不到就是 null */
    public static function storedVersion(PDO $pdo): ?string
    {
        try {
            $stmt = $pdo->prepare('SELECT v FROM app_meta WHERE k = ?');
            $stmt->execute([self::KEY]);
            $value = $stmt->fetchColumn();
        } catch (PDOException) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function record(PDO $pdo, ?string $version = null): void
    {
        $version ??= self::codeVersion();

        $stmt = $pdo->prepare('SELECT 1 FROM app_meta WHERE k = ?');
        $stmt->execute([self::KEY]);

        if ($stmt->fetchColumn()) {
            $pdo->prepare('UPDATE app_meta SET v = ? WHERE k = ?')->execute([$version, self::KEY]);

            return;
        }

        $pdo->prepare('INSERT INTO app_meta (k, v) VALUES (?, ?)')->execute([self::KEY, $version]);
    }

    /**
     * 两个方向都要报：代码比库新要多跑迁移，代码比库旧是文件被回退了。
     *
     * @return array{code: string, db: string|null}|null 一致时返回 null
     */
    public static function mismatch(PDO $pdo): ?array
    {
        $code = self::codeVersion();
        $stored = self::storedVersion($pdo);

        if ($stored !== null && version_compare($code, $stored) === 0) {
            return null;
        }

        return ['code' => $code, 'db' => $stored];
    }
}

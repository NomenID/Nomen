<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

/**
 * 可选功能开关。默认全关，装在 config/installed.php 的 features 段，
 * 环境变量 FEATURE_* 优先（见 config/app.php）。
 */
final class Features
{
    private static ?array $all = null;

    public static function all(): array
    {
        if (self::$all === null) {
            $config = require __DIR__ . '/../config/app.php';
            $features = (array)($config['features'] ?? []);
            self::$all = [
                'guardians' => (bool)($features['guardians'] ?? false),
                'country'   => (bool)($features['country'] ?? false),
            ];
        }

        return self::$all;
    }

    public static function enabled(string $key): bool
    {
        return (bool)(self::all()[$key] ?? false);
    }

    /** 家庭 / 未成年监护：家长同意、家长邮箱、家庭页这一整套 */
    public static function guardians(): bool
    {
        return self::enabled('guardians');
    }

    /** 注册时填写国家 / 地区（含按 IP 预填） */
    public static function country(): bool
    {
        return self::enabled('country');
    }
}

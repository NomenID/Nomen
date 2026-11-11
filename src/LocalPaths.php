<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

/**
 * 本地开发时的扩展目录覆盖。
 *
 * 插件和皮肤各自是独立仓库，平时放在这个仓库外面（见根目录的 paths.local.php）。
 * 这个文件不进 git，发布包里也不需要：找不到就回落到仓库内的 plugins/、skins/。
 */
final class LocalPaths
{
    /** @var array<string, string>|null */
    private static ?array $data = null;

    public static function get(string $key, string $default): string
    {
        if (self::$data === null) {
            $file = dirname(__DIR__) . '/paths.local.php';
            $loaded = is_file($file) ? require $file : [];
            self::$data = is_array($loaded) ? array_map(static fn($v): string => (string)$v, $loaded) : [];
        }

        $value = trim(str_replace('\\', '/', self::$data[$key] ?? ''));

        return $value === '' ? rtrim(str_replace('\\', '/', $default), '/') : rtrim($value, '/');
    }
}

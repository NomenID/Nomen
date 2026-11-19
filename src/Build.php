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
 * data/build.json：quickbuild 部署时写入，记录这次部署跑的是哪个包。
 * 正式发版的包不带它，版本页也就没有构建行；/api/version 始终报代码版本号。
 *
 * {"version":"1.5.0+qb.20261112","release":"1.4.2","sha256":"<64 位>","built_at":"YYYY-MM-DDTHH:MMZ"}
 * built_at 是 UTC。
 */
final class Build
{
    private static ?array $info = null;

    public static function info(): array
    {
        if (self::$info === null) {
            self::$info = self::read();
        }

        return self::$info;
    }

    /** 版本页那一行：返回「sha 前 8 位 · 构建时间」，没有或对不上当前版本就返回 null */
    public static function summary(string $version): ?string
    {
        $info = self::info();

        return ($info['version'] ?? '') === $version
            && preg_match('/^[0-9a-f]{64}$/', $info['sha256'] ?? '') === 1
            && ($info['built_at'] ?? '') !== ''
                ? substr($info['sha256'], 0, 8) . ' · ' . self::utc($info['built_at'])
                : null;
    }

    /** 构建时间一律存 UTC（ISO 8601，带 Z），显示时补个 UTC，别让人猜时区 */
    private static function utc(string $stamp): string
    {
        return (string)preg_replace('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(?::\d{2})?Z$/', '$1 $2 UTC', $stamp);
    }

    private static function read(): array
    {
        $file = dirname(__DIR__) . '/data/build.json';
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) {
            return [];
        }

        return [
            'version'  => trim((string)($data['version'] ?? '')),
            'release'  => trim((string)($data['release'] ?? '')),
            'sha256'   => strtolower(trim((string)($data['sha256'] ?? ''))),
            'built_at' => mb_substr(trim((string)($data['built_at'] ?? '')), 0, 40),
        ];
    }
}

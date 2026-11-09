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
 * 查最新正式版。更新源是官方站的 /api/version（官方站发版即升级，
 * 报的就是自己的版本号），走 Cloudflare，国内服务器直连可达；
 * 结果和失败都进缓存，避免每次打开后台概览都去请求。
 */
final class UpdateCheck
{
    private const UPDATE_SOURCE = 'https://nomenid.org/api/version';
    private const RELEASE_URL = 'https://nomenid.org/w/download';
    private const CACHE_TTL = 1800;

    /** @return array{version: string, url: string}|null 拉不到返回 null */
    public static function latest(): ?array
    {
        $payload = Cache::remember('update_check.latest', self::CACHE_TTL, static fn(): array => self::fetch() ?? []);
        return $payload === [] ? null : $payload;
    }

    /** 当前程序版本是否比线上正式版旧 */
    public static function isNewer(string $latest, string $current): bool
    {
        $a = self::parts($latest);
        $b = self::parts($current);
        for ($i = 0; $i < 3; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] > $b[$i];
            }
        }
        return false;
    }

    /** @return int[] 三段数字，缺的补 0 */
    private static function parts(string $version): array
    {
        $nums = [0, 0, 0];
        foreach (explode('.', trim($version)) as $i => $part) {
            if ($i > 2) {
                break;
            }
            $nums[$i] = (int)$part;
        }
        return $nums;
    }

    private static function fetch(): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init(self::UPDATE_SOURCE);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Nomen-UpdateCheck',
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $status !== 200) {
            return null;
        }

        $data = json_decode((string)$raw, true);
        $version = trim((string)($data['version'] ?? ''));
        if ($version === '') {
            return null;
        }

        return ['version' => $version, 'url' => self::RELEASE_URL];
    }
}

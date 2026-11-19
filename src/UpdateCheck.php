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
 * 查最新发布。更新源是站点自己配置的 feed（默认留空 = 不检查）：
 * 官方站把最新发布信息放在 /official-api/releases/latest，其它站点可以指向
 * 官方地址，也可以指向任何同格式的静态 JSON。
 *
 * /api/version 只报「这个站点实际在跑的版本」，不再兼职发布源。
 */
final class UpdateCheck
{
    public const PRIORITIES = ['security', 'critical', 'normal', 'feature'];

    private const CACHE_TTL = 1800;

    /**
     * @return array{version: string, url: string, priority: string, download?: string, sha256?: string,
     *               min_php?: string, min_supported?: string, notes?: string, released_at?: string}|null
     */
    public static function latest(bool $force = false): ?array
    {
        $feed = self::feed();
        if ($feed === '') {
            return null;
        }

        if ($force) {
            self::forget();
        }

        $payload = Cache::remember(self::cacheKey(), self::CACHE_TTL, static fn(): array => self::fetch($feed) ?? []);

        return $payload === [] ? null : $payload;
    }

    public static function forget(): void
    {
        Cache::forget(self::cacheKey());
    }

    /** 只读缓存，不发 HTTP；后台概览这种不该为新闻卡等 8 秒的地方用 */
    public static function cached(): ?array
    {
        $payload = Cache::get(self::cacheKey(), null);

        return is_array($payload) && $payload !== [] ? $payload : null;
    }

    /** feed 留空 / 不合法就是不检查更新 */
    public static function feed(): string
    {
        $feed = trim((string)UpdateConfig::get('feed', ''));

        return preg_match('#^https?://#i', $feed) === 1 ? $feed : '';
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

    private static function fetch(string $feed): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($feed);
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
        if (!is_array($data)) {
            return null;
        }

        // 也接受 {"releases":[{...}, ...]} 的历史写法，取版本最高的那条
        $release = $data;
        if (isset($data['releases']) && is_array($data['releases'])) {
            $release = self::pickLatest($data['releases']);
            if ($release === null) {
                return null;
            }
        }

        $version = trim((string)($release['version'] ?? ''));
        if (preg_match('/^\d+(\.\d+){1,3}([+-][0-9A-Za-z._-]+)?$/', $version) !== 1) {
            return null;
        }

        $priority = strtolower(trim((string)($release['priority'] ?? 'normal')));
        if (!in_array($priority, self::PRIORITIES, true)) {
            $priority = 'normal';
        }

        $out = [
            'version'  => $version,
            'url'      => 'https://nomenid.org/w/download',
            'priority' => $priority,
        ];

        $download = trim((string)($release['download'] ?? ''));
        if (preg_match('#^https?://#i', $download) === 1) {
            $out['download'] = $download;
        }

        $sha = strtolower(trim((string)($release['sha256'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $sha) === 1) {
            $out['sha256'] = $sha;
        }

        $minPhp = trim((string)($release['min_php'] ?? ''));
        if (preg_match('/^\d+(\.\d+){1,2}$/', $minPhp) === 1) {
            $out['min_php'] = $minPhp;
        }

        $minSupported = trim((string)($release['min_supported'] ?? ''));
        if (preg_match('/^\d+(\.\d+){1,3}$/', $minSupported) === 1) {
            $out['min_supported'] = $minSupported;
        }

        $notes = trim((string)($release['notes'] ?? ''));
        if (preg_match('#^https?://#i', $notes) === 1) {
            $out['notes'] = $notes;
        }

        $released = trim((string)($release['released_at'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}([T ][0-9:.]+Z?)?$/', $released) === 1) {
            $out['released_at'] = mb_substr($released, 0, 40);
        }

        return $out;
    }

    /** @param array<int, mixed> $releases */
    private static function pickLatest(array $releases): ?array
    {
        $best = null;
        $bestVersion = '';
        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }
            $version = trim((string)($release['version'] ?? ''));
            if (preg_match('/^\d+(\.\d+){1,3}([+-][0-9A-Za-z._-]+)?$/', $version) !== 1) {
                continue;
            }
            if ($best === null || version_compare($version, $bestVersion, '>')) {
                $best = $release;
                $bestVersion = $version;
            }
        }

        return $best;
    }

    private static function cacheKey(): string
    {
        $feed = self::feed();

        return 'update_check.latest.' . substr(hash('sha256', $feed === '' ? 'none' : $feed), 0, 12);
    }
}

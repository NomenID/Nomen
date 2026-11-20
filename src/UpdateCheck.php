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
 * 新客户端查更新走独立的发布源 feed（默认留空 = 不检查）：官方站把最新发布信息放在
 * /official-api/releases/latest，其它站点可以指向官方地址，也可以指向任何同格式的静态 JSON。
 * /api/version 只报本站实际在跑的版本，别和发布源混用；官方站以外默认没有发布接口。
 *
 * 发布源有两种形状：
 *  - 单条（老格式）：{"version": "1.9.0", ...}，只有「最新版」一个信息；
 *  - 列表（新格式）：{"releases": [{version, branch, ...}], "support": {"1.9": {status}}}，
 *    按分支列版本。维护分支的补丁（1.8.5）不会被别的分支的新版（1.9.0）盖掉。
 */
final class UpdateCheck
{
    public const PRIORITIES = ['security', 'critical', 'normal', 'feature'];

    /** 分支状态：正常维护 / 只收安全修复 / 已停止支持 */
    public const BRANCH_STATUSES = ['active', 'maintenance', 'eol'];

    private const CACHE_TTL = 1800;

    /**
     * 按当前版本挑一个更新。返回：
     *  ok=false      取不到发布源
     *  reason        same_branch / cross_branch / eol_branch / unknown_branch / up_to_date / unreachable
     *  offer         挑中的那条（没有就是 null）
     *  branch        当前版本所在分支（1.9.0 → "1.9"）
     *  branchStatus  active / maintenance / eol / unknown
     *  support       发布源给的分支支持表（原样）
     *  path          跨分支升级建议路径（不含当前版本）
     *
     * @return array{ok: bool, reason: string, offer: ?array, branch: string, branchStatus: string,
     *               support: array<string, array>, path: string[]}
     */
    public static function resolve(string $current, bool $force = false): array
    {
        $payload = self::payload($force);
        if ($payload === null) {
            return [
                'ok'           => false,
                'reason'       => 'unreachable',
                'offer'        => null,
                'branch'       => self::branch($current),
                'branchStatus' => 'unknown',
                'support'      => [],
                'path'         => [],
            ];
        }

        return ['ok' => true] + self::select($payload, $current);
    }

    /**
     * 纯选择逻辑，不发 HTTP，便于测试。
     *
     * @param array{releases: array<int, array>, support: array<string, array>} $payload
     * @return array{reason: string, offer: ?array, branch: string, branchStatus: string,
     *               support: array<string, array>, path: string[]}
     */
    public static function select(array $payload, string $current): array
    {
        $releases = array_values(array_filter((array)($payload['releases'] ?? []), 'is_array'));
        $support = (array)($payload['support'] ?? []);
        $legacy = $support === [];
        $branch = self::branch($current);

        $out = [
            'reason'       => 'up_to_date',
            'offer'        => null,
            'branch'       => $branch,
            'branchStatus' => $legacy ? 'active' : self::statusOf($support, $branch),
            'support'      => $support,
            'path'         => [],
        ];

        // 1. 同分支优先：1.8 的补丁不能被 1.9 的新版本盖掉
        $same = [];
        foreach ($releases as $release) {
            $version = (string)($release['version'] ?? '');
            if (self::branch($version) === $branch && self::isNewer($version, $current)) {
                $same[] = $release;
            }
        }
        if ($same !== []) {
            $out['reason'] = 'same_branch';
            $out['offer'] = self::highest($same);

            return $out;
        }

        // 2. 老格式没有 support 表：照旧给全局最高的新版本，装不装交给 scope / 优先级
        if ($legacy) {
            $newer = [];
            foreach ($releases as $release) {
                if (self::isNewer((string)($release['version'] ?? ''), $current)) {
                    $newer[] = $release;
                }
            }
            if ($newer !== []) {
                $out['reason'] = 'cross_branch';
                $out['offer'] = self::highest($newer);
            }

            return $out;
        }

        // 3. 本分支没有补丁：看有没有更高的受支持分支。维护中的分支也要提示新版，
        //    只是「要不要自动装」仍旧交给 scope / 优先级，不在这里判死。
        $supported = in_array($out['branchStatus'], ['active', 'maintenance'], true);
        $target = self::nextBranch($releases, $support, $branch);
        if ($target === null) {
            return $out;
        }

        if ($supported) {
            $out['reason'] = 'cross_branch';
            $out['offer'] = $target;

            return $out;
        }

        // 4. 分支停更：给升级目标和一条能照抄的路径
        $out['reason'] = $out['branchStatus'] === 'eol' ? 'eol_branch' : 'unknown_branch';
        $out['offer'] = $target;
        $out['path'] = self::path($releases, $support, $current, (string)$target['version']);

        return $out;
    }

    /** 兼容旧调用：全局最高版本（不看分支） */
    public static function latest(bool $force = false): ?array
    {
        $payload = self::payload($force);

        return $payload === null ? null : self::highest($payload['releases']);
    }

    public static function forget(): void
    {
        Cache::forget(self::cacheKey());
    }

    /** 只读缓存，不发 HTTP；后台概览这种不该为新闻卡等 8 秒的地方用 */
    public static function cached(): ?array
    {
        $payload = Cache::get(self::cacheKey(), null);
        if (!is_array($payload) || $payload === []) {
            return null;
        }

        return self::highest((array)($payload['releases'] ?? []));
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

    /** 版本所在分支：1.9.0 → "1.9"；只给一位时就是那一位 */
    public static function branch(string $version): string
    {
        $nums = self::parts($version);

        return $nums[0] . '.' . $nums[1];
    }

    /** @return int[] 三段数字，缺的补 0；`+qb.xxx` 这类后缀不参与比较 */
    private static function parts(string $version): array
    {
        $nums = [0, 0, 0];
        $base = (string)(preg_split('/[+-]/', trim($version))[0] ?? $version);
        foreach (explode('.', $base) as $i => $part) {
            if ($i > 2) {
                break;
            }
            $nums[$i] = (int)$part;
        }
        return $nums;
    }

    /** @param array<string, array> $support */
    private static function statusOf(array $support, string $branch): string
    {
        if (!array_key_exists($branch, $support)) {
            return 'unknown';
        }
        $meta = $support[$branch];
        $status = is_array($meta) ? strtolower(trim((string)($meta['status'] ?? ''))) : '';

        return in_array($status, self::BRANCH_STATUSES, true) ? $status : 'unknown';
    }

    /**
     * 最近的高一级受支持分支里、能盖过当前版本的那一条。
     *
     * @param array<int, array> $releases
     * @param array<string, array> $support
     */
    private static function nextBranch(array $releases, array $support, string $branch): ?array
    {
        $now = self::parts($branch);
        $branches = [];
        foreach ($support as $name => $meta) {
            $name = (string)$name;
            if (!in_array(self::statusOf($support, $name), ['active', 'maintenance'], true)) {
                continue;
            }
            $parts = self::parts($name);
            if ($parts[0] > $now[0] || ($parts[0] === $now[0] && $parts[1] > $now[1])) {
                $branches[] = $name;
            }
        }
        usort($branches, static fn(string $a, string $b): int => version_compare($a . '.0', $b . '.0'));

        foreach ($branches as $name) {
            $inBranch = [];
            foreach ($releases as $release) {
                $version = (string)($release['version'] ?? '');
                if (self::branch($version) === $name) {
                    $inBranch[] = $release;
                }
            }
            if ($inBranch !== []) {
                return self::highest($inBranch);
            }
        }

        return null;
    }

    /**
     * 跨分支升级要按顺序走：停更分支的最后一版 → 中间每个受支持分支的最新版 → 目标版本。
     * 迁移是按版本顺序写的，跳版会漏，所以这里给一条能照抄的路径。
     *
     * @param array<int, array> $releases
     * @param array<string, array> $support
     * @return string[]
     */
    private static function path(array $releases, array $support, string $current, string $target): array
    {
        $chain = [];
        $currentBranch = self::branch($current);

        // 停更分支自己的最后一版（发布源给了 last 就用它）
        $last = trim((string)($support[$currentBranch]['last'] ?? ''));
        if ($last !== '' && self::isNewer($last, $current)) {
            $chain[] = $last;
        }

        $targetParts = self::parts($target);
        $nowParts = self::parts($current);
        $middles = [];
        foreach ($support as $name => $meta) {
            $name = (string)$name;
            if ($name === $currentBranch || !in_array(self::statusOf($support, $name), ['active', 'maintenance'], true)) {
                continue;
            }
            $parts = self::parts($name);
            $afterCurrent = $parts[0] > $nowParts[0] || ($parts[0] === $nowParts[0] && $parts[1] > $nowParts[1]);
            $beforeTarget = $parts[0] < $targetParts[0] || ($parts[0] === $targetParts[0] && $parts[1] < $targetParts[1]);
            if ($afterCurrent && $beforeTarget) {
                $middles[] = $name;
            }
        }
        usort($middles, static fn(string $a, string $b): int => version_compare($a . '.0', $b . '.0'));

        foreach ($middles as $name) {
            $inBranch = [];
            foreach ($releases as $release) {
                $version = (string)($release['version'] ?? '');
                if (self::branch($version) === $name) {
                    $inBranch[] = $release;
                }
            }
            $best = $inBranch === [] ? null : self::highest($inBranch);
            if ($best !== null) {
                $chain[] = (string)$best['version'];
            }
        }

        $chain[] = $target;

        return array_values(array_unique($chain));
    }

    /** @param array<int, array> $releases */
    private static function highest(array $releases): ?array
    {
        $best = null;
        $bestVersion = '';
        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }
            $version = (string)($release['version'] ?? '');
            if ($best === null || version_compare($version, $bestVersion, '>')) {
                $best = $release;
                $bestVersion = $version;
            }
        }

        return $best;
    }

    /** @return array{releases: array<int, array>, support: array<string, array>}|null */
    private static function payload(bool $force = false): ?array
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

    /** @return array{releases: array<int, array>, support: array<string, array>}|null */
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

        // 老格式只有一条，新格式是 {"releases": [...], "support": {...}}
        $list = isset($data['releases']) && is_array($data['releases']) ? $data['releases'] : [$data];

        $releases = [];
        foreach ($list as $entry) {
            $release = self::normalize($entry);
            if ($release !== null) {
                $releases[] = $release;
            }
        }
        if ($releases === []) {
            return null;
        }

        $support = [];
        foreach ((array)($data['support'] ?? []) as $branch => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $name = (string)$branch;
            $support[$name] = [
                'status' => self::statusOf([$name => $meta], $name),
                'until'  => trim((string)($meta['until'] ?? '')),
                'last'   => trim((string)($meta['last'] ?? '')),
            ];
        }

        return ['releases' => $releases, 'support' => $support];
    }

    /** @param mixed $release */
    private static function normalize($release): ?array
    {
        if (!is_array($release)) {
            return null;
        }

        $version = trim((string)($release['version'] ?? ''));
        if (preg_match('/^\d+(\.\d+){1,3}([+-][0-9A-Za-z._-]+)?$/', $version) !== 1) {
            return null;
        }

        $priority = strtolower(trim((string)($release['priority'] ?? 'normal')));
        if (!in_array($priority, self::PRIORITIES, true)) {
            $priority = 'normal';
        }

        $branch = trim((string)($release['branch'] ?? ''));
        if (preg_match('/^\d+\.\d+$/', $branch) !== 1) {
            $branch = self::branch($version);
        }

        $out = [
            'version'  => $version,
            'branch'   => $branch,
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

    private static function cacheKey(): string
    {
        $feed = self::feed();

        return 'update_check.latest.' . substr(hash('sha256', $feed === '' ? 'none' : $feed), 0, 12);
    }
}

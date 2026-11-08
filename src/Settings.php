<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

use PDOException;

/**
 * 站点级设置：登录策略、缓存后端这类「站长在后台改、全站生效」的开关。
 *
 * 值都以字符串存进 site_settings，读的时候整表取一次；Redis 可用时在它上面挂
 * 60 秒的副本，后台保存会立刻删掉这个键，所以正常改动不会等过期。
 */
class Settings
{
    private const CACHE_TTL = 60;

    private static ?array $data = null;

    /** Redis 可能被多个站点共用，键里带上站点地址的哈希 */
    private static function cacheKey(): string
    {
        $site = (string)Brand::get('base_url');

        return 'nomen:site_settings:' . substr(hash('sha256', $site === '' ? 'default' : $site), 0, 16);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function set(string $key, string $value): void
    {
        self::setMany([$key => $value]);
    }

    /** 表在不在（老站点升级前后台要提示） */
    public static function ready(): bool
    {
        try {
            Database::getInstance()->query('SELECT 1 FROM site_settings LIMIT 1');

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /** @param array<string, string> $values */
    public static function setMany(array $values): void
    {
        if ($values === []) {
            return;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO site_settings (k, v) VALUES (:k, :v) '
            . Database::dialectFor($db)->upsert(['k'], 'v = :v2')
        );

        foreach ($values as $key => $value) {
            $stmt->execute([
                ':k'  => (string)$key,
                ':v'  => (string)$value,
                ':v2' => (string)$value,
            ]);
        }

        self::$data = null;
        self::forgetCache();
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $redis = RedisClient::get();
        if ($redis !== null) {
            try {
                $raw = $redis->get(self::cacheKey());
                if (is_string($raw) && $raw !== '') {
                    $cached = @unserialize($raw, ['allowed_classes' => false]);
                    if (is_array($cached)) {
                        return self::$data = $cached;
                    }
                }
            } catch (\Throwable $e) {
                error_log('[settings] 读取缓存失败: ' . $e->getMessage());
            }
        }

        $data = [];
        try {
            $stmt = Database::getInstance()->query('SELECT k, v FROM site_settings');
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $data[(string)$row['k']] = (string)$row['v'];
            }
        } catch (PDOException $e) {
            // 老站点还没跑 upgrade：按默认值工作（2FA 默认关闭），别把登录打挂
            error_log('[settings] site_settings 表不可用（可能还没跑升级）: ' . $e->getMessage());
        }

        self::$data = $data;

        if ($redis !== null) {
            try {
                $redis->setex(self::cacheKey(), self::CACHE_TTL, serialize($data));
            } catch (\Throwable $e) {
                error_log('[settings] 写入缓存失败: ' . $e->getMessage());
            }
        }

        return $data;
    }

    public static function forgetCache(): void
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return;
        }

        try {
            $redis->del(self::cacheKey());
        } catch (\Throwable $e) {
            error_log('[settings] 清理缓存失败: ' . $e->getMessage());
        }
    }
}

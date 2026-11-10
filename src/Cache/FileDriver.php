<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Cache;

/**
 * 文件缓存：默认落在 data/cache，站点根不在 web root 里，文件不会被直接访问。
 */
class FileDriver implements Driver
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/\\');
    }

    public function get(string $key): mixed
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        $payload = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($payload) || (int)($payload['expires_at'] ?? 0) < time()) {
            @unlink($file);
            return null;
        }

        return $payload['value'] ?? null;
    }

    public function put(string $key, mixed $value, int $ttl): void
    {
        if (!$this->ensureDir()) {
            return;
        }

        $raw = serialize([
            'expires_at' => time() + max(1, $ttl),
            'value'      => $value,
        ]);

        $file = $this->path($key);
        if (@file_put_contents($file, $raw, LOCK_EX) === false) {
            error_log('[cache] 写入文件缓存失败: ' . $file);
            return;
        }
        @chmod($file, 0644);
    }

    public function forget(string $key): void
    {
        @unlink($this->path($key));
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.cache';
    }

    private function ensureDir(): bool
    {
        if (is_dir($this->dir)) {
            return true;
        }
        if (@mkdir($this->dir, 0775, true) || is_dir($this->dir)) {
            return true;
        }

        error_log('[cache] 建不了缓存目录: ' . $this->dir);

        return false;
    }
}

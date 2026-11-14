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
class FileDriver implements Driver, Flushable
{
    private string $dir;
    private int $maxBytes;

    public function __construct(string $dir, int $maxBytes = 0)
    {
        $this->dir = rtrim($dir, '/\\');
        $this->maxBytes = max(0, $maxBytes);
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

        // 偶尔整理一次：过期文件不必等被读到才删，目录也别无限涨
        if (random_int(1, 50) === 1) {
            $this->prune();
        }
    }

    public function forget(string $key): void
    {
        @unlink($this->path($key));
    }

    public function flush(): int
    {
        $deleted = 0;
        foreach ($this->files() as $file) {
            if (@unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * 先清过期的，再按最旧优先删到上限的 80%。
     *
     * 只反序列化最老的一批（过期判断要看 expires_at），文件大小和修改时间用 stat，
     * 免得几千个文件每次都全文读一遍。
     */
    private function prune(): void
    {
        $entries = [];
        $total = 0;
        foreach ($this->files() as $file) {
            $size = @filesize($file);
            if ($size === false) {
                continue;
            }
            $entries[] = ['file' => $file, 'size' => $size, 'mtime' => (int)@filemtime($file)];
            $total += $size;
        }

        usort($entries, static fn(array $a, array $b): int => $a['mtime'] <=> $b['mtime']);

        $now = time();
        $checked = 0;
        $alive = [];
        foreach ($entries as $entry) {
            if ($checked < 200) {
                $checked++;
                $raw = @file_get_contents($entry['file']);
                $payload = $raw === false ? null : @unserialize($raw, ['allowed_classes' => false]);
                if (!is_array($payload) || (int)($payload['expires_at'] ?? 0) < $now) {
                    if (@unlink($entry['file'])) {
                        $total -= $entry['size'];
                    }
                    continue;
                }
            }
            $alive[] = $entry;
        }

        if ($this->maxBytes <= 0 || $total <= $this->maxBytes) {
            return;
        }

        $target = (int)($this->maxBytes * 0.8);
        foreach ($alive as $entry) {
            if ($total <= $target) {
                break;
            }
            if (@unlink($entry['file'])) {
                $total -= $entry['size'];
            }
        }
    }

    /** @return array<int, string> */
    private function files(): array
    {
        return glob($this->dir . '/*.cache') ?: [];
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

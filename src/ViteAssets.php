<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

final class ViteAssets
{
    private const DIST_URL = '/assets/dist/';

    private static ?array $manifest = null;

    public static function entry(string $entry): string
    {
        $manifest = self::manifest();
        $item = self::findEntry($manifest, $entry);

        if ($item === null) {
            error_log("[ViteAssets] manifest 里找不到入口 {$entry}");
            throw new \RuntimeException("Vite entry {$entry} 不存在");
        }

        $html = '';
        foreach ($item['imports'] ?? [] as $import) {
            $file = $manifest[$import]['file'] ?? null;
            if ($file !== null) {
                $html .= '<link rel="modulepreload" href="' . self::url($file) . '">' . "\n";
            }
        }
        foreach ($item['css'] ?? [] as $css) {
            $html .= '<link rel="stylesheet" href="' . self::url($css) . '">' . "\n";
        }
        $html .= '<script type="module" src="' . self::url((string)$item['file']) . '"></script>' . "\n";

        return $html;
    }

    private static function findEntry(array $manifest, string $entry): ?array
    {
        if (isset($manifest[$entry])) {
            return $manifest[$entry];
        }

        foreach ($manifest as $key => $item) {
            if (!($item['isEntry'] ?? false)) {
                continue;
            }
            if (($item['src'] ?? '') === $entry || basename($key) === basename($entry)) {
                return $item;
            }
        }

        return null;
    }

    private static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $path = __DIR__ . '/../public/assets/dist/.vite/manifest.json';
        if (!is_file($path)) {
            error_log('[ViteAssets] 找不到 manifest：' . $path . '（是不是还没 build / 部署顺序反了？）');
            throw new \RuntimeException('Vite manifest 不存在');
        }

        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            error_log('[ViteAssets] manifest 解析失败：' . $path);
            throw new \RuntimeException('Vite manifest 解析失败');
        }

        return self::$manifest = $data;
    }

    private static function url(string $file): string
    {
        return self::DIST_URL . ltrim($file, '/');
    }
}

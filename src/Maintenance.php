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
 * 维护模式：自动更新复制文件、跑迁移的这段时间里，访客看到 503 而不是半新半旧的页面。
 *
 * 标记写成 data/maintenance.json，带到期时间。进程中途被杀也不会把站永久锁在维护页，
 * 最多等到 until 自己失效。
 */
final class Maintenance
{
    private const FILE = '/data/maintenance.json';

    public static function start(int $ttl, string $reason = ''): void
    {
        $file = self::file();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('[maintenance] 建不了目录: ' . $dir);
            return;
        }
        Ownership::adopt($dir);

        $payload = json_encode([
            'until'      => time() + max(60, $ttl),
            'started_at' => time(),
            'reason'     => $reason,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (@file_put_contents($file, (string)$payload, LOCK_EX) === false) {
            error_log('[maintenance] 写不进维护标记: ' . $file);
        } else {
            Ownership::adopt($file);
        }
    }

    public static function stop(): void
    {
        $file = self::file();
        if (is_file($file) && !@unlink($file)) {
            error_log('[maintenance] 删不掉维护标记: ' . $file);
        }
    }

    public static function active(): bool
    {
        $file = self::file();
        if (!is_file($file)) {
            return false;
        }

        $data = json_decode((string)@file_get_contents($file), true);
        $until = is_array($data) ? (int)($data['until'] ?? 0) : 0;
        if ($until > time()) {
            return true;
        }

        // 到期就顺手清掉，别让它一直躺着
        @unlink($file);

        return false;
    }

    /** 维护页：不加载皮肤、不查库，越简单越不会在升级中途再炸一次 */
    public static function render(): never
    {
        http_response_code(503);
        header('Retry-After: 120');
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex');

        $name = htmlspecialchars((string)Brand::get('name', 'Nomen'), ENT_QUOTES, 'UTF-8');
        $title = function_exists('t') ? t('Under maintenance') : 'Under maintenance';
        $text = function_exists('t')
            ? t('The site is being updated. Please come back in a few minutes.')
            : 'The site is being updated. Please come back in a few minutes.';

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' · ' . $name . '</title>'
            . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f6f8fa;color:#1f2328;'
            . 'font:16px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}'
            . 'main{max-width:420px;padding:32px;text-align:center}h1{margin:0 0 10px;font-size:20px}'
            . 'p{margin:0;color:#59636e}'
            . '@media(prefers-color-scheme:dark){body{background:#0d1117;color:#e6edf3}p{color:#8b949e}}</style>'
            . '</head><body><main><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p></main></body></html>';
        exit;
    }

    private static function file(): string
    {
        return dirname(__DIR__) . self::FILE;
    }
}

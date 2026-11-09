<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

/**
 * 独立报错页：不读语言包、皮肤、session、数据库，
 * 只碰超全局变量和 best-effort 的品牌信息，保证「渲染报错页」这步永远不二次炸掉。
 * 同时负责全局异常 / 致命错误的兜底，调试开关是 .env 里的 APP_DEBUG。
 */
final class ErrorPage
{
    private static ?bool $debug = null;

    /** 页面文案写死在这里：语言包挂了也得能显示，只区分中英，其余语言回英文 */
    private const STRINGS = [
        'zh' => [
            'lang' => 'zh-Hans',
            'titles' => [
                400 => '请求无效',
                401 => '未授权',
                403 => '禁止访问',
                404 => '页面不存在',
                500 => '服务器内部错误',
            ],
            'fallback_title' => '出错了',
            'generic' => '服务器遇到问题，请稍后再试。如果一直是这样，请联系站点管理员。',
            'ref' => '错误编号 {:id}（联系管理员时请附上）',
            'back' => '← 返回首页',
            'details' => '错误详情（调试模式）',
            'type' => '类型',
            'message' => '信息',
            'location' => '位置',
            'trace' => '调用栈',
            'previous' => '前一个异常',
        ],
        'en' => [
            'lang' => 'en',
            'titles' => [
                400 => 'Bad request',
                401 => 'Unauthorized',
                403 => 'Access denied',
                404 => 'Page not found',
                500 => 'Internal error',
            ],
            'fallback_title' => 'Something went wrong',
            'generic' => 'The server had a problem. Please try again later. If it keeps happening, contact the site administrator.',
            'ref' => 'Error ID {:id} (include this when contacting the administrator)',
            'back' => '← Go to homepage',
            'details' => 'Error details (debug mode)',
            'type' => 'Type',
            'message' => 'Message',
            'location' => 'Location',
            'trace' => 'Stack trace',
            'previous' => 'Previous exception',
        ],
    ];

    public static function debug(): bool
    {
        if (self::$debug === null) {
            $v = strtolower((string)getenv('APP_DEBUG'));
            self::$debug = in_array($v, ['1', 'true', 'yes', 'on'], true);
        }

        return self::$debug;
    }

    /** Response::error 的落点：设定状态码、渲染、退出（调用方都依赖它会 exit） */
    public static function show(int $code, string $message = ''): void
    {
        if ($code >= 500) {
            error_log('[nomen] error ' . $code . ': ' . $message . ' @ ' . ($_SERVER['REQUEST_URI'] ?? 'cli'));
        }

        if (PHP_SAPI === 'cli') {
            $s = self::strings();
            $title = $s['titles'][$code] ?? $s['fallback_title'];
            echo '[Nomen] ' . $code . ' ' . $title . ($message !== '' ? ': ' . $message : ''), PHP_EOL;
            exit;
        }

        $id = null;
        if ($code >= 500) {
            $id = bin2hex(random_bytes(4));
            error_log('[nomen] [' . $id . '] error ' . $code . ' @ ' . ($_SERVER['REQUEST_URI'] ?? ''));
        }

        self::render($code, $message, null, $id);
        exit;
    }

    /** set_exception_handler 的落点：永远记日志；页面细节只在 APP_DEBUG 开着时显示 */
    public static function exception(\Throwable $e): void
    {
        $id = bin2hex(random_bytes(4));
        error_log('[nomen] [' . $id . '] ' . self::summarize($e));

        if (PHP_SAPI === 'cli') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            fwrite(STDERR, '[Nomen] uncaught ' . self::summarize($e) . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
            exit(1);
        }

        self::render(500, '', $e, $id);
        exit(1);
    }

    /** register_shutdown_function 的落点：只接真正的致命错误（内存耗尽、解析错误这类） */
    public static function shutdown(): void
    {
        $err = error_get_last();
        if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        self::exception(new \ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']));
    }

    public static function register(): void
    {
        set_exception_handler([self::class, 'exception']);
        register_shutdown_function([self::class, 'shutdown']);
    }

    private static function summarize(\Throwable $e): string
    {
        return get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
            . ' @ ' . ($_SERVER['REQUEST_URI'] ?? 'cli');
    }

    private static function render(int $code, string $message, ?\Throwable $e, ?string $id): void
    {
        // 之前崩掉的半截输出（模板渲染到一半 fatal）整个丢掉，只给干净的报错页
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: text/html; charset=utf-8');
        }

        try {
            echo self::html($code, $message, $e, $id);
        } catch (\Throwable) {
            // 报错页自己也坏了：退回一行纯文本，至少把状态码和原因留下
            echo '<!doctype html><meta charset="utf-8"><p>' . $code . ' error.</p>';
        }
    }

    private static function strings(): array
    {
        $slug = (string)($_COOKIE['nomen_lang'] ?? '');
        if (str_starts_with($slug, 'zh')) {
            return self::STRINGS['zh'];
        }
        if (str_starts_with($slug, 'en')) {
            return self::STRINGS['en'];
        }
        if (str_contains((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 'zh')) {
            return self::STRINGS['zh'];
        }

        return self::STRINGS['en'];
    }

    /** brand.php 读得到就用（logo + 站名），读不到静默降级成纯文字 */
    private static function brand(): array
    {
        try {
            $b = @include dirname(__DIR__) . '/config/brand.php';
            return is_array($b) ? $b : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private static function logoTag(array $brand, string $site): string
    {
        $logo = (string)($brand['logo'] ?? '');
        if ($logo === '') {
            return '<span class="mark">' . htmlspecialchars($site) . '</span>';
        }

        // 根相对路径先确认文件真的在，避免在坏掉的站上再叠一个碎图标
        $ok = !str_starts_with($logo, '/')
            || is_file(dirname(__DIR__) . '/public' . parse_url($logo, PHP_URL_PATH));
        if (!$ok) {
            return '<span class="mark">' . htmlspecialchars($site) . '</span>';
        }

        return '<img src="' . htmlspecialchars($logo) . '" alt="" width="28" height="28">'
            . '<span class="mark">' . htmlspecialchars($site) . '</span>';
    }

    private static function html(int $code, string $message, ?\Throwable $e, ?string $id): string
    {
        $s = self::strings();
        $brand = self::brand();
        $site = trim((string)($brand['name'] ?? '')) ?: 'Nomen';
        $title = $s['titles'][$code] ?? $s['fallback_title'];

        if ($code >= 500) {
            // 5xx 的原始 message 可能带模板名、路径这类内部信息，调试关着就不给访客看
            $shown = self::debug()
                ? ($message !== '' ? $message : ($e ? $e->getMessage() : ''))
                : $s['generic'];
        } else {
            $shown = $message !== '' ? $message : $title;
        }

        $refLine = '';
        if ($code >= 500 && !self::debug() && $id !== null) {
            $refLine = '<p class="ref">' . str_replace('{:id}', htmlspecialchars($id), $s['ref']) . '</p>';
        }

        $details = '';
        if (self::debug() && ($e !== null || ($code >= 500 && $message !== ''))) {
            $details = '<section class="details"><h2>' . htmlspecialchars($s['details']) . '</h2>'
                . ($message !== '' && $e === null
                    ? '<dl><dt>' . htmlspecialchars($s['message']) . '</dt><dd><code>' . htmlspecialchars($message) . '</code></dd></dl>'
                    : self::exceptionBlock($e, $s, 0))
                . '</section>';
        }

        $favicon = '';
        $logo = (string)($brand['logo'] ?? '');
        if ($logo !== '') {
            $favicon = '<link rel="icon" href="' . htmlspecialchars($logo) . '">';
        }

        return '<!DOCTYPE html>
<html lang="' . $s['lang'] . '" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . (int)$code . ' ' . htmlspecialchars($title) . ' — ' . htmlspecialchars($site) . '</title>
' . $favicon . '<style>
:root { color-scheme: light dark; }
* { box-sizing: border-box; }
body {
    margin: 0; min-height: 100vh; display: flex; flex-direction: column;
    font-family: system-ui, -apple-system, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
    background: #fafafa; color: #1f2328;
}
header { padding: 20px 28px; }
header a { display: inline-flex; align-items: center; gap: 10px; text-decoration: none; color: inherit; }
header img { border-radius: 6px; }
header .mark { font-weight: 700; font-size: 17px; }
main { flex: 1; display: flex; flex-direction: column; justify-content: center; max-width: 640px; width: 100%; margin: 0 auto; padding: 24px 28px 64px; }
h1 { font-size: 26px; margin: 0 0 8px; }
h1 small { font-weight: 400; color: #6b7280; margin-right: 10px; }
p.msg { color: #4b5563; line-height: 1.7; margin: 0 0 8px; }
p.ref { color: #9ca3af; font-size: 13px; margin: 0 0 8px; }
.details { margin-top: 28px; border: 1px solid #d1d5db; border-radius: 8px; padding: 16px 20px; overflow-x: auto; }
.details h2 { font-size: 14px; margin: 0 0 12px; color: #6b7280; font-weight: 600; }
.details dl { margin: 0 0 12px; }
.details dt { font-size: 12px; color: #6b7280; margin-top: 8px; }
.details dd { margin: 2px 0 0; }
.details pre { margin: 4px 0 0; font-size: 12px; line-height: 1.5; white-space: pre-wrap; word-break: break-word; }
code { font-family: ui-monospace, Consolas, monospace; font-size: 13px; }
a.home { display: inline-block; margin-top: 28px; color: #1f2328; }
@media (prefers-color-scheme: dark) {
    body { background: #12161c; color: #e6e8eb; }
    h1 small, p.ref, .details h2, .details dt { color: #8b929b; }
    p.msg { color: #b3b9c1; }
    .details { border-color: #2e353d; }
    a.home { color: #e6e8eb; }
}
</style>
</head>
<body>
<header><a href="/">' . self::logoTag($brand, $site) . '</a></header>
<main>
<h1><small>' . (int)$code . '</small>' . htmlspecialchars($title) . '</h1>
<p class="msg">' . htmlspecialchars($shown) . '</p>
' . $refLine . $details . '
<a class="home" href="/">' . htmlspecialchars($s['back']) . '</a>
</main>
</body>
</html>';
    }

    /** 异常链最多画 5 层，页面上全铺没意义 */
    private static function exceptionBlock(?\Throwable $e, array $s, int $depth): string
    {
        if ($e === null || $depth >= 5) {
            return '';
        }

        $label = $depth > 0 ? '<dt>' . htmlspecialchars($s['previous']) . '</dt>' : '';
        $block = $label . '<dt>' . htmlspecialchars($s['type']) . '</dt><dd><code>' . htmlspecialchars(get_class($e)) . '</code></dd>'
            . '<dt>' . htmlspecialchars($s['message']) . '</dt><dd><code>' . htmlspecialchars($e->getMessage()) . '</code></dd>'
            . '<dt>' . htmlspecialchars($s['location']) . '</dt><dd><code>' . htmlspecialchars($e->getFile() . ':' . $e->getLine()) . '</code></dd>'
            . '<dt>' . htmlspecialchars($s['trace']) . '</dt><dd><pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre></dd>';

        return '<dl>' . $block . '</dl>' . self::exceptionBlock($e->getPrevious(), $s, $depth + 1);
    }
}

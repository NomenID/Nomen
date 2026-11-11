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
 * 站内地址的唯一出口。
 *
 * 有伪静态时保持原来的 /login 这种形态；没有伪静态（config/installed.php 的
 * app.pretty_urls 为 false）时改走 index.php?page=login。配置里没写这一项按有伪静态处理，
 * 老站升级不会因为少了这个键就整站换地址。
 */
class Url
{
    private static ?bool $pretty = null;

    private static ?string $base = null;

    /** 查询串模式下的入口脚本，相对站点根 */
    private const ENTRY = '/index.php';

    public static function pretty(): bool
    {
        if (self::$pretty !== null) {
            return self::$pretty;
        }

        $file = dirname(__DIR__) . '/config/installed.php';
        $config = is_file($file) ? (array)(require $file) : [];
        $value = $config['app']['pretty_urls'] ?? null;

        if ($value === null || $value === '') {
            return self::$pretty = true;
        }

        return self::$pretty = (bool)filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public static function queryMode(): bool
    {
        return !self::pretty();
    }

    /** 站点装在子目录里时，$_SERVER['SCRIPT_NAME'] 会带上那段前缀 */
    public static function base(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }

        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $dir = str_replace('\\', '/', dirname($script));

        return self::$base = ($dir === '/' || $dir === '.' || $dir === '') ? '' : rtrim($dir, '/');
    }

    /**
     * 站内路径 → 当前模式下的地址。外部地址、协议相对地址、锚点原样返回。
     *
     * @param array<string, string|int> $query
     */
    public static function to(string $path, array $query = []): string
    {
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $path;
        }

        $fragment = '';
        if (($pos = strpos($path, '#')) !== false) {
            $fragment = substr($path, $pos);
            $path = substr($path, 0, $pos);
        }

        if (($pos = strpos($path, '?')) !== false) {
            parse_str(substr($path, $pos + 1), $inline);
            $path = substr($path, 0, $pos);
            $query = array_merge($inline, $query);
        }

        if (self::pretty()) {
            $url = self::base() . ($path === '' ? '/' : $path);
        } else {
            $url = self::base() . self::ENTRY;
            if ($path !== '' && $path !== '/') {
                $query = ['page' => ltrim($path, '/')] + $query;
            }
        }

        if ($query !== []) {
            $pairs = [];
            foreach ($query as $key => $value) {
                $pair = rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
                // page 里的 / 保持可读：index.php?page=w/local-docs/install
                $pairs[] = $key === 'page' ? str_replace('%2F', '/', $pair) : $pair;
            }
            $url .= '?' . implode('&', $pairs);
        }

        return $url . $fragment;
    }

    /**
     * 把已经渲染好的 HTML 里的站内链接换成当前模式能用的地址。
     *
     * 只动 public/ 下不存在真实文件的路径：/css/、/assets/、/uploads/ 这些交给 nginx，
     * 皮肤、插件里写死的 /login 和站长自己写在页面里的链接则一起跟着走。
     */
    public static function filterHtml(string $html): string
    {
        if (self::pretty() || $html === '') {
            return $html;
        }

        return (string)preg_replace_callback(
            '#\b(href|src|action)=("|\')(/[^"\']*)\2#i',
            static function (array $m): string {
                return $m[1] . '=' . $m[2] . self::rewritePath($m[3]) . $m[2];
            },
            $html
        );
    }

    private static function rewritePath(string $value): string
    {
        if (str_starts_with($value, '//')) {
            return $value;
        }

        $fragment = '';
        if (($pos = strpos($value, '#')) !== false) {
            $fragment = substr($value, $pos);
            $value = substr($value, 0, $pos);
        }

        $path = $value;
        $query = '';
        if (($pos = strpos($value, '?')) !== false) {
            $path = substr($value, 0, $pos);
            $query = substr($value, $pos + 1);
        }

        if ($path === '' || self::isRealFile($path)) {
            return $value . $fragment;
        }

        return self::to($path . ($query !== '' ? '?' . $query : '')) . $fragment;
    }

    private static function isRealFile(string $path): bool
    {
        static $cache = [];

        if (isset($cache[$path])) {
            return $cache[$path];
        }

        $clean = rawurldecode($path);
        if (str_contains($clean, "\0") || str_contains($clean, '..')) {
            return $cache[$path] = false;
        }

        return $cache[$path] = file_exists(dirname(__DIR__) . '/public' . $clean);
    }
}

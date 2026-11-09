<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src {

class I18n
{
    public const COOKIE = 'nomen_lang';
    /** 翻译缺失时的回退语言（与站点默认语言是两回事，那个在 config/app.php） */
    public const FALLBACK = 'en';
    /** 母键语言 = 英文原句；也是缺翻译时的最终兜底 */
    public const SOURCE = 'en';

    private static ?string $locale = null;
    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    // ---------- 注册表 ----------

    public static function registry(): array
    {
        static $registry = null;
        if ($registry === null) {
            $registry = require __DIR__ . '/../locales/languages.php';
        }
        return $registry;
    }

    public static function slugs(): array
    {
        return array_column(self::registry(), 'slug');
    }

    public static function isValid(string $slug): bool
    {
        return in_array($slug, self::slugs(), true);
    }

    public static function entry(?string $slug = null): array
    {
        $slug = $slug ?? self::locale();
        foreach (self::registry() as $entry) {
            if ($entry['slug'] === $slug) {
                return $entry;
            }
        }
        return self::registry()[0];
    }

    /** BCP-47，给 <html lang> 用 */
    public static function code(?string $slug = null): string
    {
        return self::entry($slug)['code'];
    }

    public static function dir(?string $slug = null): string
    {
        return self::entry($slug)['dir'];
    }

    public static function isRtl(?string $slug = null): bool
    {
        return self::dir($slug) === 'rtl';
    }

    // ---------- 站点语言设置 ----------

    /** 站点默认语言：安装器写进 installed.php，config/app.php 汇总成 language_default。 */
    public static function siteDefaultLocale(): string
    {
        static $default = null;
        if ($default === null) {
            $config = require dirname(__DIR__) . '/config/app.php';
            $slug = (string)($config['language_default'] ?? self::SOURCE);
            $default = self::isValid($slug) ? $slug : self::SOURCE;
        }
        return $default;
    }

    /** 站长关掉切换后，全站固定默认语言：?lang、cookie、账号偏好都不再参与。 */
    public static function switchingAllowed(): bool
    {
        static $allowed = null;
        if ($allowed === null) {
            $config = require dirname(__DIR__) . '/config/app.php';
            $allowed = filter_var($config['language_allow_switch'] ?? true, FILTER_VALIDATE_BOOLEAN);
        }
        return $allowed;
    }

    // ---------- 语言判定 ----------

    public static function locale(): string
    {
        return self::$locale ?? self::SOURCE;
    }

    /**
     * 每个请求调一次。优先级：?lang 显式切换 > 登录账号的偏好 > cookie > 浏览器语言 > 站点默认语言。
     * 站长关掉切换（language_allow_switch）时整条链路都不走，固定默认语言。
     * CLI（跑测试/脚本）固定站点默认语言，行为与多语言化之前一致。
     */
    public static function init(): void
    {
        if (self::$locale !== null) {
            return;
        }

        if (PHP_SAPI === 'cli') {
            self::$locale = self::siteDefaultLocale();
            return;
        }

        if (!self::switchingAllowed()) {
            self::$locale = self::siteDefaultLocale();
            return;
        }

        // 语言选择器点过来：显式动作优先级最高。
        $param = (string)($_GET['lang'] ?? '');
        if ($param !== '' && self::isValid($param)) {
            self::applyChoice($param);
            return;
        }

        $sessionLocale = (string)(Session::get('user_locale') ?? '');
        if (Session::get('user_id') && self::isValid($sessionLocale)) {
            self::$locale = $sessionLocale;
            return;
        }

        $cookie = (string)($_COOKIE[self::COOKIE] ?? '');
        if (self::isValid($cookie)) {
            self::$locale = $cookie;
            return;
        }

        self::$locale = self::matchAcceptLanguage($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') ?? self::siteDefaultLocale();
    }

    /**
     * 明确的语言选择动作（?lang= 参数、/special/lang 选择页共用）：
     * 落 cookie，登录态顺手写进账号偏好。
     */
    public static function applyChoice(string $slug): void
    {
        if (!self::isValid($slug)) {
            return;
        }
        self::$locale = $slug;
        setcookie(self::COOKIE, $slug, [
            'expires' => time() + 31536000,
            'path' => '/',
            'samesite' => 'Lax',
        ]);
        $userId = Session::get('user_id');
        if ($userId) {
            self::persistUserLocale((int)$userId, $slug);
        }
    }

    private static function persistUserLocale(int $userId, string $slug): void
    {
        try {
            $stmt = Database::getInstance()->prepare('UPDATE users SET locale = :l WHERE id = :id');
            $stmt->execute([':l' => $slug, ':id' => $userId]);
            Session::set('user_locale', $slug);
        } catch (\Throwable) {
            // DDL 还没跑 / 库暂时不可用时降级成只改 cookie，别让整站 500
        }
    }

    private static function matchAcceptLanguage(string $header): ?string
    {
        if ($header === '') {
            return null;
        }

        $tags = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $q = 1.0;
            if (str_contains($part, ';')) {
                [$tag, $param] = array_pad(explode(';', $part, 2), 2, '');
                if (preg_match('/q=([0-9.]+)/', $param, $m)) {
                    $q = (float)$m[1];
                }
            } else {
                $tag = $part;
            }
            $tag = strtolower(str_replace('_', '-', trim($tag)));
            if ($tag !== '' && $tag !== '*') {
                $tags[] = ['tag' => $tag, 'q' => $q];
            }
        }

        usort($tags, fn($a, $b) => $b['q'] <=> $a['q']);
        foreach ($tags as $t) {
            $slug = self::matchTag($t['tag']);
            if ($slug !== null) {
                return $slug;
            }
        }
        return null;
    }

    private static function matchTag(string $tag): ?string
    {
        $tag = strtolower(str_replace('_', '-', $tag));
        // 历史遗留代码（in=印尼、iw=希伯来），带区域的也要折算
        $base = explode('-', $tag)[0];
        if ($base === 'in') $tag = 'id' . substr($tag, 2);
        if ($base === 'iw') $tag = 'he' . substr($tag, 2);

        foreach (self::registry() as $entry) {
            if (strtolower($entry['code']) === $tag || $entry['slug'] === $tag) {
                return $entry['slug'];
            }
        }

        if (str_starts_with($tag, 'zh')) {
            $sub = substr($tag, 2);
            if (str_contains($sub, 'hant') || str_contains($sub, '-tw') || str_contains($sub, '-hk') || str_contains($sub, '-mo')) {
                return 'zh-hant';
            }
            return 'zh-hans';
        }

        $base = explode('-', $tag)[0];
        foreach (self::registry() as $entry) {
            if (explode('-', strtolower($entry['code']))[0] === $base || $entry['slug'] === $base) {
                return $entry['slug'];
            }
        }
        return null;
    }

    // ---------- 翻译 ----------

    /**
     * 翻译。键就是简体中文原文；当前语言没有、en 也没有时原样返回键（永不白屏）。
     * 插值：t('The code is valid for {:min} minutes', ['min' => 10])
     */
    public static function t(string $key, array $params = [], ?string $slug = null): string
    {
        $text = self::lookup($slug ?? self::locale(), $key)
            ?? self::lookup(self::FALLBACK, $key)
            ?? $key;

        foreach ($params as $name => $value) {
            $text = strtr($text, ['{:' . $name . '}' => (string)$value]);
        }
        // {:sitename} 是全局占位符（站点品牌名），调用点不用传
        if (!isset($params['sitename']) && str_contains($text, '{:sitename}')) {
            $text = strtr($text, ['{:sitename}' => (string)Brand::get('name', 'Nomen')]);
        }
        return $text;
    }

    /** 用指定语言翻译（邮件等脱离当前会话的场合）。语言无效时按简体（键原文）出。 */
    public static function tIn(string $slug, string $key, array $params = []): string
    {
        return self::t($key, $params, self::isValid($slug) ? $slug : self::SOURCE);
    }

    private static function lookup(string $slug, string $key): ?string
    {
        if (!isset(self::$cache[$slug])) {
            self::$cache[$slug] = self::loadLocale($slug);
        }
        $value = self::$cache[$slug][$key] ?? null;
        return (is_string($value) && $value !== '') ? $value : null;
    }

    /** 核心词典 + 启用的插件 / 可用皮肤自己的 i18n/ 目录。核心优先，扩展只补缺。 */
    private static function loadLocale(string $slug): array
    {
        $file = __DIR__ . '/../locales/' . $slug . '.json';
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        $merged = is_array($data) ? $data : [];

        foreach (self::extensionDictDirs() as $dir) {
            $file = $dir . '/' . $slug . '.json';
            if (!is_file($file)) {
                continue;
            }
            $data = json_decode((string)file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            foreach ($data as $key => $value) {
                if (is_string($value) && $value !== '' && !isset($merged[$key])) {
                    $merged[$key] = $value;
                }
            }
        }

        return $merged;
    }

    /** @return string[] 有 i18n/ 目录的插件与皮肤 */
    private static function extensionDictDirs(): array
    {
        static $dirs = null;
        if ($dirs !== null) {
            return $dirs;
        }

        $dirs = [];
        foreach (Plugins::enabled() as $slug) {
            $dir = Plugins::dir() . '/' . $slug . '/i18n';
            if (is_dir($dir)) {
                $dirs[] = $dir;
            }
        }
        foreach (Skins::available() as $slug => $manifest) {
            $dir = Skins::dir() . '/' . $slug . '/i18n';
            if (is_dir($dir)) {
                $dirs[] = $dir;
            }
        }
        return $dirs;
    }

    // ---------- 邮件语言 ----------

    /**
     * 收件人语言：账号存了偏好就用它；没存（含库异常）按站点默认语言发。
     * 请求上下文里能确定用户正在看的语言的（登录验证码这类），直接传 I18n::locale()，不走这里。
     */
    public static function emailLocale(?int $userId = null): string
    {
        if ($userId) {
            try {
                $stmt = Database::getInstance()->prepare('SELECT locale FROM users WHERE id = :id');
                $stmt->execute([':id' => $userId]);
                $locale = (string)($stmt->fetchColumn() ?: '');
                if (self::isValid($locale)) {
                    return $locale;
                }
            } catch (\Throwable) {
                // 落到下面的默认
            }
        }
        return self::siteDefaultLocale();
    }

    // ---------- 前端词典 ----------

    /** 布局里挂的词典地址，?v= 取文件内容哈希，内容变了缓存自动失效。
     *  不带 .js 后缀：nginx 的静态 location 会把 *.js 拦在 PHP 前面（2026-11-05 线上踩过） */
    public static function catalogUrl(?string $slug = null): string
    {
        $slug = $slug ?? self::locale();
        $file = __DIR__ . '/../locales/' . $slug . '.json';
        $v = is_file($file) ? substr(md5_file($file), 0, 8) : '0';
        $v .= '-' . substr(md5(implode(',', self::extensionDictDirs())), 0, 8);
        return '/i18n/' . rawurlencode($slug) . '?v=' . $v;
    }

    /** GET /i18n/{slug}.js —— 给 app.js 和 Vue 共用的词典（管理后台不挂，按键原样显示中文） */
    public static function serveScript(string $slug): void
    {
        if (!self::isValid($slug)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            exit('not found');
        }

        $file = __DIR__ . '/../locales/' . $slug . '.json';
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        $data = is_array($data) ? $data : [];

        // 插件/皮肤自己的词条一并给前端，Vue 里也能 t() 到
        foreach (self::extensionDictDirs() as $dir) {
            $file = $dir . '/' . $slug . '.json';
            if (!is_file($file)) {
                continue;
            }
            $extra = json_decode((string)file_get_contents($file), true);
            if (!is_array($extra)) {
                continue;
            }
            foreach ($extra as $key => $value) {
                if (is_string($value) && $value !== '' && !isset($data[$key])) {
                    $data[$key] = $value;
                }
            }
        }

        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=31536000, immutable');
        echo 'window.NOMEN_I18N=' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
        echo 'window.NOMEN_SITENAME=' . json_encode((string)Brand::get('name', 'Nomen'), JSON_UNESCAPED_UNICODE) . ';';
        echo 'window.NOMEN_LOCALE=' . json_encode($slug) . ';';
        echo 'window.NOMEN_LANG=' . json_encode(self::code($slug)) . ';';
        echo 'window.NOMEN_DIR=' . json_encode(self::dir($slug)) . ';';
        exit;
    }
}

}

namespace {

// 全局翻译助手。必须放全局命名空间（这个文件整体在 Nomen\Src 下，
// 不单独开 namespace 块的话 t() 会变成 Nomen\Src\t()，模板里根本调不到）。
if (!function_exists('t')) {
    function t(string $key, array $params = []): string
    {
        return \Nomen\Src\I18n::t($key, $params);
    }
}

}

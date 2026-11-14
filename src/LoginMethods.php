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
 * 登录方式总开关：密码、邮箱验证码、生物识别、临时密钥、第三方登录。
 * 开关存 site_settings（login.method_*），缺省全开，后台「登录方式」页改。
 * 插件可以经 login.methods 钩子注册自己的登录方式（extra()），默认方式存 login.default。
 * 保存时校验至少留一种可用方式；真被锁外面了用 php bin/nomen auth:reset 恢复。
 */
final class LoginMethods
{
    public const METHODS = ['password', 'email_code', 'biometric', 'temp_key', 'oauth'];

    /** @return array<string, bool> method => 是否开启 */
    public static function all(): array
    {
        $out = [];
        foreach (self::METHODS as $method) {
            $out[$method] = Settings::get('login.method_' . $method, '1') === '1';
        }

        return $out;
    }

    public static function on(string $method): bool
    {
        return in_array($method, self::METHODS, true)
            && Settings::get('login.method_' . $method, '1') === '1';
    }

    /**
     * 插件注册的登录方式。条目要有 id（小写字母开头，字母数字连字符下划线）和 url（登录入口）；
     * label 是成品文案，logo 可选，by 填插件显示名（后台展示用）。同 id 先注册的生效。
     *
     * @return array<int, array{id:string,label:string,url:string,logo:string,by:string}>
     */
    public static function extra(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $out = [];
        $seen = [];
        foreach ((array)Hooks::filter('login.methods', []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = strtolower(trim((string)($entry['id'] ?? '')));
            $url = trim((string)($entry['url'] ?? ''));
            $label = trim((string)($entry['label'] ?? ''));
            if ($id === '' || $url === '' || isset($seen[$id]) || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $id)) {
                continue;
            }
            if (in_array($id, self::METHODS, true)) {
                continue;
            }
            $seen[$id] = true;
            $out[] = [
                'id'    => $id,
                'label' => $label !== '' ? $label : $id,
                'url'   => $url,
                'logo'  => (string)($entry['logo'] ?? ''),
                'by'    => (string)($entry['by'] ?? ''),
            ];
        }

        return $cache = $out;
    }

    private static function oauthUsable(): bool
    {
        return self::on('oauth') && IdentityProviders::all(true) !== [];
    }

    /** 该方式当前能不能当默认：核心方式要开着（oauth 还得有已启用提供方），插件方式要已注册。 */
    public static function isDefaultable(string $method): bool
    {
        if (in_array($method, self::METHODS, true)) {
            return $method === 'oauth' ? self::oauthUsable() : self::on($method);
        }

        foreach (self::extra() as $extra) {
            if ($extra['id'] === $method) {
                return true;
            }
        }

        return false;
    }

    /**
     * 默认登录方式（登录页第一眼显示的那种）。存 login.default；
     * 值失效（方式被关 / 插件被停）时按 开启的核心方式 → 插件方式 顺序兜底。
     */
    public static function default(): string
    {
        $saved = (string)Settings::get('login.default', '');
        if ($saved !== '' && self::isDefaultable($saved)) {
            return $saved;
        }

        foreach (self::METHODS as $method) {
            if ($method === 'oauth' ? self::oauthUsable() : self::on($method)) {
                return $method;
            }
        }

        $extra = self::extra();
        return $extra !== [] ? $extra[0]['id'] : 'password';
    }

    /**
     * 至少还有一种方式登得进来。第三方登录要算数，得有至少一个已启用的提供方；
     * 插件注册的登录方式也算数。
     *
     * @param array<string, bool>|null $overrides 传改动后的状态用于保存前校验
     */
    public static function hasAvailableWay(?array $overrides = null): bool
    {
        $methods = [];
        foreach (self::METHODS as $method) {
            $methods[$method] = $overrides[$method] ?? self::on($method);
        }

        if ($methods['password'] || $methods['email_code'] || $methods['biometric'] || $methods['temp_key']) {
            return true;
        }

        if ($methods['oauth'] && IdentityProviders::all(true) !== []) {
            return true;
        }

        return self::extra() !== [];
    }
}

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
 * 登录方式总开关：密码、邮箱验证码、生物识别、临时密钥、第三方登录。
 * 开关存 site_settings（login.method_*），缺省全开，后台「登录方式」页改。
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
     * 至少还有一种方式登得进来。第三方登录要算数，得有至少一个已启用的提供方。
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

        return $methods['oauth'] && IdentityProviders::all(true) !== [];
    }
}

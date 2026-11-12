<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

use PDO;

/**
 * OpenID Connect 那一层：发现文档、JWKS、id_token 签名。
 * 签名密钥第一次用到时生成，放 data/oidc-key.json（data/ 不进仓库、也不进发布包）。
 */
final class Oidc
{
    private const KEY_FILE = '/data/oidc-key.json';

    private static ?array $key = null;
    private static ?string $opensslConfig = null;

    public static function issuer(): string
    {
        return rtrim((string)Brand::get('base_url', ''), '/');
    }

    /** openssl 不可用时只提供普通 OAuth，不发 id_token。 */
    public static function enabled(): bool
    {
        return function_exists('openssl_pkey_new') && self::key() !== null;
    }

    public static function discovery(): array
    {
        $base = self::issuer();
        $config = require dirname(__DIR__) . '/config/app.php';

        $doc = [
            'issuer'                                => $base,
            'authorization_endpoint'                => $base . '/oauth/authorize',
            'token_endpoint'                        => $base . '/oauth/token',
            'userinfo_endpoint'                     => $base . '/api/userinfo',
            'jwks_uri'                              => $base . '/oidc/jwks.json',
            'end_session_endpoint'                  => $base . '/logout',
            'response_types_supported'              => ['code'],
            'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
            'subject_types_supported'               => ['public'],
            'scopes_supported'                      => array_keys($config['scopes']),
            'claims_supported'                      => [
                'iss', 'sub', 'aud', 'exp', 'iat', 'nonce',
                'name', 'preferred_username', 'picture', 'email', 'email_verified',
            ],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'code_challenge_methods_supported'      => ['S256'],
        ];

        if (self::enabled()) {
            $doc['id_token_signing_alg_values_supported'] = ['RS256'];
        }

        return $doc;
    }

    public static function jwks(): array
    {
        $key = self::key();
        if ($key === null) {
            return ['keys' => []];
        }

        $details = openssl_pkey_get_details(openssl_pkey_get_public($key['public']));
        $rsa = $details['rsa'] ?? null;
        if (!is_array($rsa)) {
            return ['keys' => []];
        }

        return ['keys' => [[
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $key['kid'],
            'n'   => self::base64Url($rsa['n']),
            'e'   => self::base64Url($rsa['e']),
        ]]];
    }

    /**
     * 组装 id_token 的 claims（不含签名）。
     *
     * @param array $scopes 已授予的 scope 列表
     */
    public static function claims(PDO $db, int $userId, string $clientId, array $scopes, ?string $nonce = null): ?array
    {
        $stmt = $db->prepare(
            'SELECT id, username, email, display_name, avatar, email_verified FROM users WHERE id = :id AND status = 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) {
            return null;
        }

        $config = require dirname(__DIR__) . '/config/app.php';
        $now = time();

        $claims = [
            'iss' => self::issuer(),
            'sub' => self::subject($db, $userId, $clientId),
            'aud' => $clientId,
            'iat' => $now,
            'exp' => $now + (int)$config['access_token_ttl'],
        ];

        if ($nonce !== null && $nonce !== '') {
            $claims['nonce'] = $nonce;
        }

        if (in_array('profile', $scopes, true)) {
            $avatar = \Nomen\Controllers\DashboardController::resolveAvatarUrl($user['avatar'] ?? null);
            $claims['preferred_username'] = (string)$user['username'];
            $claims['name'] = (string)($user['display_name'] ?: $user['username']);
            if ($avatar !== null && $avatar !== '') {
                $claims['picture'] = self::absoluteUrl($avatar);
            }
        }

        if (in_array('email', $scopes, true)) {
            $claims['email'] = (string)$user['email'];
            $claims['email_verified'] = (bool)$user['email_verified'];
        }

        return Hooks::filter('oidc.claims', $claims, $user, $scopes);
    }

    /** 签一个 RS256 的 JWT；没有密钥时返回 null。 */
    public static function idToken(array $claims): ?string
    {
        $key = self::key();
        if ($key === null) {
            return null;
        }

        $header = ['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $key['kid']];
        $input = self::base64Url(json_encode($header, JSON_UNESCAPED_SLASHES))
            . '.' . self::base64Url(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $signature = '';
        if (!openssl_sign($input, $signature, $key['private'], OPENSSL_ALGO_SHA256)) {
            error_log('[OIDC] id_token signing failed');
            return null;
        }

        return $input . '.' . self::base64Url($signature);
    }

    /** 每个应用独立的主体标识，和 /api/userinfo 的 sub 是同一个值。 */
    public static function subject(PDO $db, int $userId, string $clientId): string
    {
        $stmt = $db->prepare('SELECT subject_id FROM user_app_subjects WHERE user_id = :u AND client_id = :c');
        $stmt->execute([':u' => $userId, ':c' => $clientId]);
        $subject = $stmt->fetchColumn();

        return $subject !== false ? (string)$subject : (string)$userId;
    }

    private static function key(): ?array
    {
        if (self::$key !== null) {
            return self::$key;
        }

        if (!function_exists('openssl_pkey_new')) {
            return null;
        }

        $file = dirname(__DIR__) . self::KEY_FILE;

        if (is_file($file)) {
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data) && !empty($data['private']) && !empty($data['public']) && !empty($data['kid'])) {
                return self::$key = $data;
            }
            error_log('[OIDC] ' . $file . ' 内容损坏，将重新生成');
        }

        $resource = self::generateKey();
        if ($resource === false) {
            error_log('[OIDC] 生成 RSA 密钥失败：' . (string)openssl_error_string());
            return null;
        }

        $private = '';
        $exportOptions = self::$opensslConfig !== null ? ['config' => self::$opensslConfig] : null;
        if (!openssl_pkey_export($resource, $private, null, $exportOptions)) {
            error_log('[OIDC] 导出私钥失败：' . (string)openssl_error_string());
            return null;
        }

        $details = openssl_pkey_get_details($resource);
        $public = (string)($details['key'] ?? '');

        $data = [
            'kid'     => substr(hash('sha256', $public), 0, 16),
            'private' => $private,
            'public'  => $public,
        ];

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (@file_put_contents($file, json_encode($data)) === false) {
            error_log('[OIDC] 写不进 ' . $file . '，id_token 无法持久化，重启后会换密钥');
        } else {
            @chmod($file, 0600);
        }

        return self::$key = $data;
    }

    /**
     * Windows 上的 PHP 常常找不到 openssl.cnf（Linux 默认能找），
     * 所以默认失败时再按常见位置试一次。
     */
    private static function generateKey(): mixed
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $resource = openssl_pkey_new($options);
        if ($resource !== false) {
            return $resource;
        }

        $candidates = [
            getenv('OPENSSL_CONF') ?: '',
            dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
            'C:/Program Files/Common Files/SSL/openssl.cnf',
            '/etc/ssl/openssl.cnf',
        ];

        foreach ($candidates as $conf) {
            if ($conf === '' || !is_file($conf)) {
                continue;
            }
            $options['config'] = $conf;
            $resource = openssl_pkey_new($options);
            if ($resource !== false) {
                self::$opensslConfig = $conf;
                return $resource;
            }
        }

        return false;
    }

    private static function absoluteUrl(string $url): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            return $url;
        }

        return self::issuer() . '/' . ltrim($url, '/');
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
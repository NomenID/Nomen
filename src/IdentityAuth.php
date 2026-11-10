<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

use RuntimeException;

/**
 * 第三方登录的 OAuth2 客户端流程（授权码 + PKCE）。
 * 只处理「拿 code → 换 token → 取用户信息」，账号怎么落库在 IdentityController。
 */
class IdentityAuth
{
    private const SESSION_KEY = 'identity_oauth';
    private const STATE_TTL = 600;

    public static function begin(array $provider, bool $link = false, string $redirect = '/dashboard'): string
    {
        $state = bin2hex(random_bytes(24));
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        Session::set(self::SESSION_KEY, [
            'provider' => (string)$provider['slug'],
            'state'    => $state,
            'verifier' => $verifier,
            'link'     => $link,
            'redirect' => $redirect,
            'at'       => time(),
        ]);

        $params = [
            'response_type'         => 'code',
            'client_id'             => (string)$provider['client_id'],
            'redirect_uri'          => IdentityProviders::callbackUrl((string)$provider['slug']),
            'scope'                 => (string)$provider['scopes'],
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ];

        $extra = [];
        parse_str((string)($provider['extra_authorize_params'] ?? ''), $extra);
        foreach ($extra as $key => $value) {
            if (is_string($key) && is_string($value) && $key !== '') {
                $params[$key] = $value;
            }
        }

        $url = (string)$provider['authorize_url'];
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($params);
    }

    /** @return array|null 本次回调对应的 pending 状态；过期或没有就返回 null */
    public static function pending(): ?array
    {
        $pending = Session::get(self::SESSION_KEY);
        if (!is_array($pending) || empty($pending['state']) || empty($pending['provider'])) {
            return null;
        }
        if ((int)($pending['at'] ?? 0) + self::STATE_TTL < time()) {
            Session::remove(self::SESSION_KEY);
            return null;
        }

        return $pending;
    }

    public static function clear(): void
    {
        Session::remove(self::SESSION_KEY);
    }

    /** @return array{access_token: string, token_type: string, scope?: string} */
    public static function exchange(array $provider, string $code, string $verifier): array
    {
        $payload = [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => IdentityProviders::callbackUrl((string)$provider['slug']),
            'code_verifier' => $verifier,
        ];

        $headers = ['Accept: application/json', 'User-Agent: Nomen'];
        if (($provider['token_auth'] ?? 'post') === 'basic') {
            $headers[] = 'Authorization: Basic ' . base64_encode(
                (string)$provider['client_id'] . ':' . (string)$provider['client_secret']
            );
        } else {
            $payload['client_id'] = (string)$provider['client_id'];
            $payload['client_secret'] = (string)$provider['client_secret'];
        }

        $response = self::request('POST', (string)$provider['token_url'], $headers, http_build_query($payload));
        $token = (string)($response['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('令牌响应里没有 access_token');
        }

        return [
            'access_token' => $token,
            'token_type'   => (string)($response['token_type'] ?? 'Bearer'),
            'scope'        => (string)($response['scope'] ?? ''),
        ];
    }

    /** @return array{subject: string, email: string, email_verified: bool, name: string, avatar: string, raw: array} */
    public static function profile(array $provider, string $accessToken): array
    {
        $raw = self::request('GET', (string)$provider['userinfo_url'], [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
            'User-Agent: Nomen',
        ]);

        $subject = self::pick($raw, (string)$provider['subject_field']);
        if ($subject === '') {
            throw new RuntimeException('用户信息里找不到唯一标识（' . $provider['subject_field'] . '）');
        }

        $email = self::pick($raw, (string)$provider['email_field']);
        $verified = false;

        if ((string)($provider['email_verified_field'] ?? '') !== '') {
            $verified = (bool)self::pick($raw, (string)$provider['email_verified_field']);
        }

        // GitHub 的 /user 只给公开邮箱，验证状态要再查 /user/emails
        if ((string)($provider['emails_url'] ?? '') !== '') {
            try {
                $emails = self::request('GET', (string)$provider['emails_url'], [
                    'Authorization: Bearer ' . $accessToken,
                    'Accept: application/json',
                    'User-Agent: Nomen',
                ]);
                $chosen = null;
                foreach ($emails as $item) {
                    if (!is_array($item) || empty($item['email'])) {
                        continue;
                    }
                    if (!empty($item['verified']) && ($chosen === null || !empty($item['primary']))) {
                        $chosen = $item;
                    }
                }
                if ($chosen !== null) {
                    $email = (string)$chosen['email'];
                    $verified = true;
                }
            } catch (\Throwable $e) {
                error_log('[identity] 读取邮箱列表失败: ' . $e->getMessage());
            }
        }

        $avatar = self::pick($raw, (string)$provider['avatar_field']);
        if ($avatar !== '' && str_starts_with($avatar, '/')) {
            $parts = parse_url((string)$provider['userinfo_url']);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $avatar = $parts['scheme'] . '://' . $parts['host'] . $avatar;
            }
        }

        return [
            'subject'        => $subject,
            'email'          => $email,
            'email_verified' => $verified,
            'name'           => self::pick($raw, (string)$provider['name_field']),
            'avatar'         => $avatar,
            'raw'            => $raw,
        ];
    }

    /** @return array 解析后的 JSON */
    private static function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('缺少 curl 扩展，无法访问第三方登录接口');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Nomen',
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
        }

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('请求第三方接口失败：' . $error);
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('第三方接口返回的不是 JSON（HTTP ' . $status . '）');
        }
        if ($status >= 400) {
            $message = (string)($decoded['error_description'] ?? $decoded['error'] ?? $decoded['message'] ?? 'HTTP ' . $status);
            throw new RuntimeException('第三方接口报错：' . $message);
        }

        return $decoded;
    }

    /** 按字段路径取值，支持 a.b.c 这种嵌套。 */
    private static function pick(array $data, string $path): string
    {
        if ($path === '') {
            return '';
        }

        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return '';
            }
            $value = $value[$segment];
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        if (is_scalar($value)) {
            return trim((string)$value);
        }

        return '';
    }
}

<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

use Nomen\Src\Brand;
use Nomen\Src\Oidc;
use Nomen\Src\Db\Connection;
use Nomen\Src\Db\Dialect;

$base64UrlDecode = static function (string $value): string {
    $padded = strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4);

    return (string)base64_decode($padded, true);
};

test('OIDC 发现文档的端点和对齐路径', static function () use ($base64UrlDecode): void {
    $doc = Oidc::discovery();
    $base = rtrim((string)Brand::get('base_url', ''), '/');

    assertSame($base, $doc['issuer']);
    assertSame($base . '/oauth/authorize', $doc['authorization_endpoint']);
    assertSame($base . '/oauth/token', $doc['token_endpoint']);
    assertSame($base . '/api/userinfo', $doc['userinfo_endpoint']);
    assertSame($base . '/oidc/jwks.json', $doc['jwks_uri']);
    assertTrue(in_array('code', $doc['response_types_supported'], true));
    assertTrue(in_array('authorization_code', $doc['grant_types_supported'], true));
    assertTrue(in_array('refresh_token', $doc['grant_types_supported'], true));
    assertTrue(in_array('S256', $doc['code_challenge_methods_supported'], true));
    assertTrue(count($doc['scopes_supported']) > 0, 'scopes 不能是空数组');
});

test('OIDC JWKS 里的 kid 和公钥对得上', static function (): void {
    assertTrue(function_exists('openssl_pkey_new'), 'openssl 扩展缺失');

    $jwks = Oidc::jwks();
    assertSame(1, count($jwks['keys']));

    $jwk = $jwks['keys'][0];
    assertSame('RSA', $jwk['kty']);
    assertSame('RS256', $jwk['alg']);
    assertSame('sig', $jwk['use']);

    $keyFile = dirname(__DIR__, 2) . '/data/oidc-key.json';
    assertTrue(is_file($keyFile), 'JWKS 生成后应落地密钥文件');

    $stored = json_decode((string)file_get_contents($keyFile), true);
    assertSame(substr(hash('sha256', $stored['public']), 0, 16), $jwk['kid']);
});

test('OIDC id_token 是可用 JWKS 公钥验过的 RS256 JWT', static function () use ($base64UrlDecode): void {
    $claims = [
        'iss' => 'https://example.test',
        'sub' => 'user-1',
        'aud' => 'client-abc',
        'iat' => 1700000000,
        'exp' => 1700003600,
        'nonce' => 'n-0S6_WzA2Mj',
    ];

    $token = Oidc::idToken($claims);
    assertTrue(is_string($token), 'id_token 应能签出来');

    $parts = explode('.', (string)$token);
    assertSame(3, count($parts));

    $header = json_decode($base64UrlDecode($parts[0]), true);
    assertSame('JWT', $header['typ']);
    assertSame('RS256', $header['alg']);

    $jwks = Oidc::jwks();
    assertSame($jwks['keys'][0]['kid'], $header['kid'], 'header.kid 必须等于 JWKS 的 kid');

    $payload = json_decode($base64UrlDecode($parts[1]), true);
    assertSame($claims, $payload);

    $stored = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/data/oidc-key.json'), true);
    assertSame(
        1,
        openssl_verify($parts[0] . '.' . $parts[1], $base64UrlDecode($parts[2]), $stored['public'], OPENSSL_ALGO_SHA256),
        '依赖方用公钥验签必须通过'
    );
});

test('SQLite 连接把 NOW() 换成 datetime()', static function (): void {
    $db = new Connection('sqlite::memory:');

    assertTrue($db->dialect()->isSqlite());

    $stamp = $db->query("SELECT NOW() AS t")->fetch(PDO::FETCH_ASSOC)['t'] ?? '';
    assertMatches('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$stamp);

    $mysql = new Connection('sqlite::memory:', null, null, [], Dialect::MYSQL);
    assertTrue(!$mysql->dialect()->isSqlite(), '显式给 mysql 方言时不该走 SQLite 改写');
});

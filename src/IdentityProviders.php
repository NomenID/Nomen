<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

use InvalidArgumentException;
use PDO;

/**
 * 第三方登录提供方（GitHub / Google / Microsoft / CaelLabID / 自定义 OAuth2）。
 * 预设只用于后台表单预填，实际值一律以 identity_providers 表为准。
 */
class IdentityProviders
{
    /** @return array<string, array<string, string>> */
    public static function presets(): array
    {
        return require dirname(__DIR__) . '/config/identity-providers.php';
    }

    /** @return array<int, array> */
    public static function all(bool $enabledOnly = false): array
    {
        $sql = 'SELECT * FROM identity_providers';
        if ($enabledOnly) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        try {
            return self::db()->query($sql)->fetchAll();
        } catch (\PDOException $e) {
            // 还没跑迁移：当作没有配置，别把登录页打挂
            error_log('[identity] identity_providers 表不可用: ' . $e->getMessage());

            return [];
        }
    }

    public static function find(string $slug): ?array
    {
        try {
            $stmt = self::db()->prepare('SELECT * FROM identity_providers WHERE slug = :slug LIMIT 1');
            $stmt->execute([':slug' => $slug]);
            $row = $stmt->fetch();
        } catch (\PDOException $e) {
            return null;
        }

        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        try {
            $stmt = self::db()->prepare('SELECT * FROM identity_providers WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
        } catch (\PDOException $e) {
            return null;
        }

        return $row ?: null;
    }

    /** @return array{0: array, 1: array} 保存后的提供方 + 写库字段 */
    public static function save(array $input, ?int $id = null): array
    {
        $slug = strtolower(trim((string)($input['slug'] ?? '')));
        $name = trim((string)($input['name'] ?? ''));
        $type = (string)($input['type'] ?? 'oauth2');
        $authorize = trim((string)($input['authorize_url'] ?? ''));
        $token = trim((string)($input['token_url'] ?? ''));
        $userinfo = trim((string)($input['userinfo_url'] ?? ''));
        $emails = trim((string)($input['emails_url'] ?? ''));
        $clientId = trim((string)($input['client_id'] ?? ''));
        $clientSecret = trim((string)($input['client_secret'] ?? ''));

        $errors = [];
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,31}$/', $slug)) {
            $errors[] = '标识只能用 2-32 位小写字母、数字和连字符';
        }
        if ($name === '' || mb_strlen($name) > 64) {
            $errors[] = '名称需要 1-64 个字符';
        }
        if (!in_array($type, ['oauth2', 'oidc'], true)) {
            $errors[] = '类型只能是 oauth2 或 oidc';
        }
        foreach (['授权地址' => $authorize, '令牌地址' => $token, '用户信息地址' => $userinfo] as $label => $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
                $errors[] = $label . '必须是完整的 http(s) 地址';
            }
        }
        if ($emails !== '' && (!filter_var($emails, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $emails))) {
            $errors[] = '邮箱列表地址必须是完整的 http(s) 地址';
        }
        if ($errors !== []) {
            throw new InvalidArgumentException(implode('；', $errors));
        }

        $stmt = self::db()->prepare('SELECT id FROM identity_providers WHERE slug = :slug AND id != :id LIMIT 1');
        $stmt->execute([':slug' => $slug, ':id' => (int)($id ?? 0)]);
        if ($stmt->fetchColumn()) {
            throw new InvalidArgumentException('这个标识已经被别的提供方占用了');
        }

        $tokenAuth = ($input['token_auth'] ?? 'post') === 'basic' ? 'basic' : 'post';
        $field = static function (string $key, string $default) use ($input): string {
            $value = trim((string)($input[$key] ?? ''));
            return preg_match('/^[A-Za-z0-9_.]{1,64}$/', $value) ? $value : $default;
        };

        // 图标存公开路径：预置自带 /assets/providers/<slug>.svg，上传的落 /assets/develops/
        $logo = trim((string)($input['logo'] ?? ''));
        if ($logo !== '' && (!preg_match('#^/[A-Za-z0-9._/-]+\.(svg|png|webp|jpe?g)$#i', $logo) || str_contains($logo, '..'))) {
            throw new InvalidArgumentException('图标地址不合法');
        }

        $data = [
            'slug'                 => $slug,
            'name'                 => mb_substr($name, 0, 64),
            'logo'                 => $logo,
            'type'                 => $type,
            'client_id'            => mb_substr($clientId, 0, 255),
            'client_secret'        => $clientSecret,
            'authorize_url'        => $authorize,
            'token_url'            => $token,
            'userinfo_url'         => $userinfo,
            'emails_url'           => $emails,
            'scopes'               => mb_substr(trim((string)($input['scopes'] ?? '')), 0, 500),
            'subject_field'        => $field('subject_field', 'sub'),
            'email_field'          => $field('email_field', 'email'),
            'name_field'           => $field('name_field', 'name'),
            'avatar_field'         => $field('avatar_field', 'picture'),
            'email_verified_field' => $field('email_verified_field', ''),
            'token_auth'           => $tokenAuth,
            'extra_authorize_params' => mb_substr(trim((string)($input['extra_authorize_params'] ?? '')), 0, 500),
            'enabled'              => !empty($input['enabled']) ? 1 : 0,
            'sort_order'           => (int)($input['sort_order'] ?? 0),
            'updated_at'           => date('Y-m-d H:i:s'),
        ];

        if ($id === null) {
            $data['created_at'] = $data['updated_at'];
            $columns = array_keys($data);
            $sql = 'INSERT INTO identity_providers (' . implode(', ', $columns) . ') VALUES ('
                . implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)) . ')';
            self::db()->prepare($sql)->execute($data);
            $id = (int)self::db()->lastInsertId();
        } else {
            if ($data['client_secret'] === '') {
                unset($data['client_secret']);
            }
            $sets = implode(', ', array_map(static fn(string $c): string => $c . ' = :' . $c, array_keys($data)));
            $data['id'] = $id;
            self::db()->prepare('UPDATE identity_providers SET ' . $sets . ' WHERE id = :id')->execute($data);
        }

        return [self::findById($id) ?? [], $data];
    }

    public static function delete(int $id): void
    {
        self::db()->prepare('DELETE FROM identity_providers WHERE id = :id')->execute([':id' => $id]);
    }

    public static function setEnabled(int $id, bool $enabled): void
    {
        self::db()->prepare('UPDATE identity_providers SET enabled = :e WHERE id = :id')
            ->execute([':e' => $enabled ? 1 : 0, ':id' => $id]);
    }

    /** 登录页 / 注册页用的最小信息 @return array<int, array{slug: string, name: string, logo: string}> */
    public static function publicList(): array
    {
        return array_map(static fn(array $row): array => [
            'slug' => (string)$row['slug'],
            'name' => (string)$row['name'],
            'logo' => (string)($row['logo'] ?? ''),
        ], self::all(true));
    }

    public static function callbackUrl(string $slug): string
    {
        return rtrim((string)Brand::get('base_url'), '/') . '/auth/' . rawurlencode($slug) . '/callback';
    }

    private static function db(): PDO
    {
        return Database::getInstance();
    }
}

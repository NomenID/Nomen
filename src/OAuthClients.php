<?php
namespace Nomen\Src;

use InvalidArgumentException;
use PDO;

/**
 * OAuth 客户端的读写。管理后台和开放平台插件共用，别再各写一份。
 */
class OAuthClients
{
    public static function db(): PDO
    {
        return Database::getInstance();
    }

    /** @return array<int, array> */
    public static function all(?int $developerId = null): array
    {
        $sql = 'SELECT oc.*, d.name AS developer_name, u.username AS owner_username
                FROM oauth_clients oc
                LEFT JOIN developers d ON d.id = oc.developer_id
                LEFT JOIN users u ON u.id = oc.owner_id';
        $params = [];

        if ($developerId !== null) {
            $sql .= ' WHERE oc.developer_id = :did';
            $params[':did'] = $developerId;
        }

        $sql .= ' ORDER BY oc.created_at DESC, oc.id DESC';
        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function find(string $clientId): ?array
    {
        $stmt = self::db()->prepare(
            'SELECT oc.*, d.name AS developer_name, d.icon AS developer_icon, u.username AS owner_username
             FROM oauth_clients oc
             LEFT JOIN developers d ON d.id = oc.developer_id
             LEFT JOIN users u ON u.id = oc.owner_id
             WHERE oc.client_id = :cid LIMIT 1'
        );
        $stmt->execute([':cid' => $clientId]);

        return $stmt->fetch() ?: null;
    }

    public static function findForDeveloper(string $clientId, int $developerId): ?array
    {
        $app = self::find($clientId);

        return ($app && (int)$app['developer_id'] === $developerId) ? $app : null;
    }

    /** @return array<string, string> scope => 描述 */
    public static function scopes(): array
    {
        $config = require dirname(__DIR__) . '/config/app.php';

        return (array)($config['scopes'] ?? []);
    }

    /** @return string[] */
    public static function normalizeScopes(array $requested): array
    {
        $valid = array_keys(self::scopes());
        $scopes = array_values(array_intersect(array_map('strval', $requested), $valid));

        if (!in_array('openid', $scopes, true)) {
            array_unshift($scopes, 'openid');
        }

        return $scopes;
    }

    /** @return string[] */
    public static function parseRedirectUris(string $raw): array
    {
        $lines = preg_split('/\R/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
    }

    /**
     * 校验表单，返回写库用的字段。不合法时抛 InvalidArgumentException，消息直接给用户看。
     *
     * @throws InvalidArgumentException
     */
    public static function validate(array $input): array
    {
        $name = trim((string)($input['name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $homepage = trim((string)($input['homepage'] ?? ''));
        $uris = self::parseRedirectUris((string)($input['redirect_uris'] ?? ''));

        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 128) {
            $errors[] = t('App name needs 2-128 characters');
        }
        if ($homepage !== '' && !filter_var($homepage, FILTER_VALIDATE_URL)) {
            $errors[] = t('Invalid homepage URL');
        }
        if ($uris === []) {
            $errors[] = t('At least one callback URL is required');
        }
        foreach ($uris as $uri) {
            if (!filter_var($uri, FILTER_VALIDATE_URL)) {
                $errors[] = t('Invalid callback URL: ') . $uri;
            }
        }
        if ($errors !== []) {
            throw new InvalidArgumentException(implode('；', $errors));
        }

        $clientType = ($input['client_type'] ?? '') === 'public' ? 'public' : 'confidential';
        $pkce = $clientType === 'public' || !empty($input['pkce_required']);

        return [
            'name'          => $name,
            'description'   => $description,
            'homepage_url'  => $homepage !== '' ? $homepage : null,
            'redirect_uris' => json_encode($uris, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'scopes'        => implode(' ', self::normalizeScopes((array)($input['scopes'] ?? []))),
            'client_type'   => $clientType,
            'pkce_required' => $pkce ? 1 : 0,
        ];
    }

    /**
     * @return array{client_id: string, client_secret: string, name: string}
     * @throws InvalidArgumentException
     */
    public static function create(array $input, int $ownerId, ?int $developerId = null, ?string $icon = null): array
    {
        $data = self::validate($input);
        $clientId = 'app_' . bin2hex(random_bytes(12));
        $secret = bin2hex(random_bytes(32));

        $config = require dirname(__DIR__) . '/config/app.php';
        $hash = hash((string)($config['token_hash_algorithm'] ?? 'sha512'), $secret);

        $stmt = self::db()->prepare(
            'INSERT INTO oauth_clients (client_id, client_secret, client_type, pkce_required, name, icon, description, homepage_url, redirect_uris, scopes, owner_id, developer_id, status)
             VALUES (:cid, :secret, :ctype, :pkce, :name, :icon, :desc, :home, :uris, :scopes, :oid, :did, 1)'
        );
        $stmt->execute([
            ':cid'    => $clientId,
            ':secret' => $hash,
            ':ctype'  => $data['client_type'],
            ':pkce'   => $data['pkce_required'],
            ':name'   => $data['name'],
            ':icon'   => $icon,
            ':desc'   => $data['description'],
            ':home'   => $data['homepage_url'],
            ':uris'   => $data['redirect_uris'],
            ':scopes' => $data['scopes'],
            ':oid'    => $ownerId,
            ':did'    => $developerId,
        ]);

        return ['client_id' => $clientId, 'client_secret' => $secret, 'name' => $data['name']];
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function update(string $clientId, array $input, ?string $icon = null): array
    {
        $data = self::validate($input);

        $sql = 'UPDATE oauth_clients SET name = :name, description = :desc, homepage_url = :home,
                redirect_uris = :uris, scopes = :scopes, client_type = :ctype, pkce_required = :pkce';
        $params = [
            ':name'   => $data['name'],
            ':desc'   => $data['description'],
            ':home'   => $data['homepage_url'],
            ':uris'   => $data['redirect_uris'],
            ':scopes' => $data['scopes'],
            ':ctype'  => $data['client_type'],
            ':pkce'   => $data['pkce_required'],
            ':cid'    => $clientId,
        ];

        if ($icon !== null) {
            $sql .= ', icon = :icon';
            $params[':icon'] = $icon;
        }

        $sql .= ' WHERE client_id = :cid';
        self::db()->prepare($sql)->execute($params);

        return $data;
    }

    /**
     * 应用的可读字段，写活动日志时用来做前后对照。
     *
     * @return array<string, string>
     */
    public static function logFields(array $app): array
    {
        $uris = $app['redirect_uris'] ?? '';
        $uris = is_array($uris) ? $uris : (json_decode((string)$uris, true) ?: []);

        return [
            'name'          => (string)($app['name'] ?? ''),
            'description'   => (string)($app['description'] ?? ''),
            'homepage_url'  => (string)($app['homepage_url'] ?? ''),
            'redirect_uris' => implode("\n", array_map('strval', $uris)),
            'scopes'        => (string)($app['scopes'] ?? ''),
            'client_type'   => ($app['client_type'] ?? 'confidential') === 'public' ? '公共客户端' : '机密客户端',
            'pkce_required' => !empty($app['pkce_required']) ? '是' : '否',
            'icon'          => (string)($app['icon'] ?? ''),
        ];
    }

    /** 重置密钥并作废该应用所有活跃令牌，返回新密钥（只出现这一次）。 */
    public static function resetSecret(string $clientId): string
    {
        $secret = bin2hex(random_bytes(32));
        $config = require dirname(__DIR__) . '/config/app.php';
        $hash = hash((string)($config['token_hash_algorithm'] ?? 'sha512'), $secret);

        $db = self::db();
        $db->prepare('UPDATE oauth_clients SET client_secret = :secret WHERE client_id = :cid')
            ->execute([':secret' => $hash, ':cid' => $clientId]);
        $db->prepare('UPDATE oauth_access_tokens SET revoked = 1 WHERE client_id = :cid AND revoked = 0')
            ->execute([':cid' => $clientId]);
        $db->prepare('UPDATE oauth_refresh_tokens SET revoked = 1 WHERE client_id = :cid AND revoked = 0')
            ->execute([':cid' => $clientId]);

        return $secret;
    }

    public static function setStatus(string $clientId, int $status): void
    {
        self::db()->prepare('UPDATE oauth_clients SET status = :status WHERE client_id = :cid')
            ->execute([':status' => $status, ':cid' => $clientId]);
    }

    public static function delete(string $clientId): void
    {
        self::db()->prepare('DELETE FROM oauth_clients WHERE client_id = :cid')->execute([':cid' => $clientId]);
    }

    /** @return array{authorized_users: int, active_tokens: int} */
    public static function stats(string $clientId): array
    {
        $db = self::db();

        $stmt = $db->prepare('SELECT COUNT(DISTINCT user_id) FROM user_authorizations WHERE client_id = :cid');
        $stmt->execute([':cid' => $clientId]);
        $users = (int)$stmt->fetchColumn();

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM oauth_access_tokens
             WHERE client_id = :cid AND revoked = 0 AND expires_at > ' . Database::dialectFor($db)->now()
        );
        $stmt->execute([':cid' => $clientId]);
        $tokens = (int)$stmt->fetchColumn();

        return ['authorized_users' => $users, 'active_tokens' => $tokens];
    }

    public static function authorizedUserCount(int $developerId): int
    {
        $stmt = self::db()->prepare(
            'SELECT COUNT(DISTINCT ua.user_id)
             FROM user_authorizations ua
             JOIN oauth_clients oc ON ua.client_id = oc.client_id
             WHERE oc.developer_id = :did'
        );
        $stmt->execute([':did' => $developerId]);

        return (int)$stmt->fetchColumn();
    }

    public static function activeTokenCount(int $developerId): int
    {
        $stmt = self::db()->prepare(
            'SELECT COUNT(*)
             FROM oauth_access_tokens oat
             JOIN oauth_clients oc ON oat.client_id = oc.client_id
             WHERE oc.developer_id = :did AND oat.revoked = 0 AND oat.expires_at > ' . Database::dialect()->now()
        );
        $stmt->execute([':did' => $developerId]);

        return (int)$stmt->fetchColumn();
    }

    public static function authorizeSample(array $app, array $redirectUris, bool $pkce): string
    {
        $base = rtrim((string)Brand::get('base_url'), '/');
        $callback = $redirectUris[0] ?? 'YOUR_CALLBACK_URL';
        $url = $base . '/oauth/authorize?'
            . 'response_type=code'
            . '&client_id=' . rawurlencode((string)$app['client_id'])
            . '&redirect_uri=' . rawurlencode($callback)
            . '&scope=openid'
            . '&state=RANDOM_STRING';

        if ($pkce) {
            $url .= '&code_challenge=CODE_CHALLENGE&code_challenge_method=S256';
        }

        return $url;
    }

    public static function tokenSample(array $app, array $redirectUris, bool $pkce): string
    {
        $callback = $redirectUris[0] ?? 'YOUR_CALLBACK_URL';
        $request = "POST /oauth/token\n"
            . "Content-Type: application/x-www-form-urlencoded\n\n"
            . 'grant_type=authorization_code'
            . '&code=AUTHORIZATION_CODE'
            . '&redirect_uri=' . $callback
            . '&client_id=' . $app['client_id'];

        if (($app['client_type'] ?? 'confidential') !== 'public') {
            $request .= '&client_secret=YOUR_CLIENT_SECRET';
        }
        if ($pkce) {
            $request .= '&code_verifier=CODE_VERIFIER';
        }

        return $request;
    }
}

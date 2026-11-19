<?php
namespace Nomen\Controllers;

use Nomen\Src\{Build, Database, FamilyHelper, Features, Response, RateLimiter, Hooks, Oidc, Special\Version};
use PDO;

class ApiController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * GET /api/userinfo - 获取用户信息 (需要 Bearer Token)
     */
    public function userinfo(): void
    {
        // 每分钟 60 次限流
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rateCheck = RateLimiter::check("api:userinfo:{$ip}", 60, 60);
        if (!$rateCheck['allowed']) {
            header('Retry-After: ' . $rateCheck['retry_after']);
            Response::json(['error' => 'rate_limited', 'error_description' => t('Too many requests, try again later')], 429);
            return;
        }

        $user = $this->authenticateToken();
        if (!$user) {
            Response::json(['error' => 'unauthorized', 'error_description' => t('Access token invalid or expired')], 401);
            return;
        }

        $token = $this->getTokenFromRequest();
        $tokenData = $this->validateToken($token);

        $scopes = preg_split('/[\s,]+/', trim($tokenData['scopes'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);

        // 查该应用对该用户的 subject_id（每个应用独立 ID，防止跨应用关联）
        $sub = Oidc::subject($this->db, (int)$tokenData['user_id'], $tokenData['client_id']);

        $result = ['sub' => $sub, 'id' => $sub];

        if (in_array('profile', $scopes)) {
            $result['username']     = $user['username'];
            $result['display_name'] = $user['display_name'];
            $result['avatar']       = \Nomen\Controllers\DashboardController::resolveAvatarUrl($user['avatar']);
        }

        if (in_array('email', $scopes)) {
            $result['email']           = $user['email'];
            $result['email_verified']  = (bool)$user['email_verified'];
        }

        // 插件可以往 userinfo 里加自定义 claim
        $result = Hooks::filter('userinfo.claims', $result, $user, $scopes);

        Response::json($result);
    }

    /**
     * GET /api/token-info - 验证令牌有效性
     */
    public function tokenInfo(): void
    {
        // 每分钟 30 次限流
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rateCheck = RateLimiter::check("api:tokeninfo:{$ip}", 30, 60);
        if (!$rateCheck['allowed']) {
            header('Retry-After: ' . $rateCheck['retry_after']);
            Response::json(['error' => 'rate_limited', 'error_description' => t('Too many requests, try again later')], 429);
            return;
        }

        $token = $this->getTokenFromRequest();
        if (!$token) {
            Response::json(['error' => 'invalid_request', 'error_description' => t('Missing access token')], 400);
            return;
        }

        $tokenData = $this->validateToken($token);
        if (!$tokenData) {
            Response::json(['active' => false], 200);
            return;
        }

        Response::json([
            'active'    => true,
            'client_id' => $tokenData['client_id'],
            'user_id'   => (int)$tokenData['user_id'],
            'scopes'    => $tokenData['scopes'],
            'expires'   => strtotime($tokenData['expires_at']),
        ]);
    }

    /**
     * GET /api/consent-age - 某国家/地区的儿童同意年龄 + 法律来源
     *
     * 注册页选完国家就问这里，不再把年龄表预存进页面。返回的都是公开的法律信息，
     * 不含任何用户数据。
     */
    public function consentAge(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rateCheck = RateLimiter::check("api:consentage:{$ip}", 60, 60);
        if (!$rateCheck['allowed']) {
            header('Retry-After: ' . $rateCheck['retry_after']);
            Response::json(['error' => 'rate_limited', 'error_description' => t('Too many requests, try again later')], 429);
            return;
        }

        if (!Features::guardians()) {
            Response::json(['error' => 'not_found', 'error_description' => t('This feature is not enabled.')], 404);
            return;
        }

        $code = strtoupper(trim((string)($_GET['country'] ?? '')));
        // 国家选择关掉时按平台默认年龄算，前端不带 country 也能问
        if (($code === '' && Features::country()) || ($code !== '' && !preg_match('/^[A-Z]{2}$/', $code))) {
            Response::json(['error' => 'invalid_request', 'error_description' => t('Missing valid country parameter')], 400);
            return;
        }

        $info = FamilyHelper::consentAgeInfo($code);
        Response::json([
            'country'    => $info['country'],
            'zh'         => $info['zh'],
            'en'         => $info['en'],
            'age'        => $info['age'],
            'kind'       => $info['kind'],
            'label'      => $info['label'],
            'quote'      => $info['quote'],
            'note'       => $info['note'],
            'url'        => $info['url'],
            'policy_url' => $info['policy_url'],
        ]);
    }

    /**
     * GET /api/version - 兼容旧客户端的更新检查。
     * 默认报最近一次正式发版的号（data/build.json 的 release），
     * ?action=thissite 报本站实际在跑的号。所有站点都保留这个接口。
     * 新客户端判断更新走 /official-api/releases/latest。
     */
    public function siteVersion(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rateCheck = RateLimiter::check("api:version:{$ip}", 60, 60);
        if (!$rateCheck['allowed']) {
            header('Retry-After: ' . $rateCheck['retry_after']);
            Response::json(['error' => 'rate_limited', 'error_description' => t('Too many requests, try again later')], 429);
            return;
        }

        $config = require __DIR__ . '/../config/app.php';
        $version = (string)($config['version'] ?? '');

        Response::json([
            'product' => 'Nomen',
            'version' => ($_GET['action'] ?? '') === 'thissite' ? $version : Build::release($version),
            'url'     => Version::PROJECT_URL,
        ]);
    }

    /**
     * GET /official-api/releases/latest - 官方站发布源。
     * 读站点根 data/releases.json；没有这个文件的站点返回 404，客户端就不会检查更新。
     */
    public function releaseFeed(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rateCheck = RateLimiter::check("api:releases:{$ip}", 60, 60);
        if (!$rateCheck['allowed']) {
            header('Retry-After: ' . $rateCheck['retry_after']);
            Response::json(['error' => 'rate_limited', 'error_description' => t('Too many requests, try again later')], 429);
            return;
        }

        $file = dirname(__DIR__) . '/data/releases.json';
        if (!is_file($file)) {
            Response::json([
                'error' => 'release_feed_unavailable',
                'error_description' => 'This site does not publish a release feed.',
            ], 404);
            return;
        }

        $data = json_decode((string)@file_get_contents($file), true);
        $release = is_array($data) ? self::pickRelease($data) : null;
        $payload = $release !== null ? self::releasePayload($release) : null;
        if ($payload === null) {
            Response::json([
                'error' => 'invalid_release_feed',
                'error_description' => 'The release feed is malformed.',
            ], 500);
            return;
        }

        header('Cache-Control: public, max-age=60');
        Response::json(['product' => 'Nomen'] + $payload);
    }

    // ========== 私有方法 ==========

    /** 单条发布信息，或 {"releases":[...]} 里版本最高的一条 */
    private static function pickRelease(array $data): ?array
    {
        if (!isset($data['releases']) || !is_array($data['releases'])) {
            return $data;
        }

        $best = null;
        $bestVersion = '';
        foreach ($data['releases'] as $release) {
            if (!is_array($release)) {
                continue;
            }
            $version = trim((string)($release['version'] ?? ''));
            if (preg_match('/^\d+(\.\d+){1,3}$/', $version) !== 1) {
                continue;
            }
            if ($best === null || version_compare($version, $bestVersion, '>')) {
                $best = $release;
                $bestVersion = $version;
            }
        }

        return $best;
    }

    /** 发布源对外只输出白名单字段，data/releases.json 里的其它东西不外泄 */
    private static function releasePayload(array $release): ?array
    {
        $version = trim((string)($release['version'] ?? ''));
        if (preg_match('/^\d+(\.\d+){1,3}$/', $version) !== 1) {
            return null;
        }

        $download = trim((string)($release['download'] ?? ''));
        if (preg_match('#^https?://#i', $download) !== 1) {
            return null;
        }

        $priority = strtolower(trim((string)($release['priority'] ?? 'normal')));
        if (!in_array($priority, \Nomen\Src\UpdateCheck::PRIORITIES, true)) {
            $priority = 'normal';
        }

        $out = [
            'version'  => $version,
            'priority' => $priority,
            'download' => $download,
        ];

        $sha = strtolower(trim((string)($release['sha256'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $sha) === 1) {
            $out['sha256'] = $sha;
        }

        $minPhp = trim((string)($release['min_php'] ?? ''));
        if (preg_match('/^\d+(\.\d+){1,2}$/', $minPhp) === 1) {
            $out['min_php'] = $minPhp;
        }

        $minSupported = trim((string)($release['min_supported'] ?? ''));
        if (preg_match('/^\d+(\.\d+){1,3}$/', $minSupported) === 1) {
            $out['min_supported'] = $minSupported;
        }

        $notes = trim((string)($release['notes'] ?? ''));
        if (preg_match('#^https?://#i', $notes) === 1) {
            $out['notes'] = $notes;
        }

        $released = trim((string)($release['released_at'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}([T ][0-9:.]+Z?)?$/', $released) === 1) {
            $out['released_at'] = mb_substr($released, 0, 40);
        }

        return $out;
    }


    private function authenticateToken(): ?array
    {
        $token = $this->getTokenFromRequest();
        if (!$token) return null;

        $tokenData = $this->validateToken($token);
        if (!$tokenData) return null;

        $stmt = $this->db->prepare(
            'SELECT id, username, email, display_name, avatar, bio, email_verified FROM users WHERE id = :id AND status = 1'
        );
        $stmt->execute([':id' => $tokenData['user_id']]);
        return $stmt->fetch() ?: null;
    }

    private function getTokenFromRequest(): ?string
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
            return $matches[1];
        }

        return $_GET['access_token'] ?? null;
    }

    private function validateToken(string $token): ?array
    {
        $config = require __DIR__ . '/../config/app.php';
        $algorithms = [$config['token_hash_algorithm'] ?? 'sha512', 'sha256'];
        foreach ($algorithms as $alg) {
            $tokenHash = hash($alg, $token);
            $stmt = $this->db->prepare(
                'SELECT * FROM oauth_access_tokens WHERE token_hash = :t AND revoked = 0 AND expires_at > NOW()'
            );
            $stmt->execute([':t' => $tokenHash]);
            if ($result = $stmt->fetch()) {
                return $result;
            }
        }
        return null;
    }
}

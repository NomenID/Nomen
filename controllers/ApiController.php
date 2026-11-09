<?php
namespace Nomen\Controllers;

use Nomen\Src\{Database, FamilyHelper, Features, Response, RateLimiter, Hooks, Special\Version};
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
        $stmt = $this->db->prepare(
            'SELECT subject_id FROM user_app_subjects WHERE user_id = :u AND client_id = :c'
        );
        $stmt->execute([':u' => $tokenData['user_id'], ':c' => $tokenData['client_id']]);
        $subject = $stmt->fetch();
        $sub = $subject ? $subject['subject_id'] : (string)$user['id'];

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
     * GET /api/version - 本站运行的 Nomen 版本（公开）。
     * 官方站（nomenid.org）发版即升级，其它实例把它当更新源查最新正式版。
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
        Response::json([
            'product' => 'Nomen',
            'version' => (string)($config['version'] ?? ''),
            'url'     => Version::PROJECT_URL,
        ]);
    }

    // ========== 私有方法 ==========

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

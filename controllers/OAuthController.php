<?php
namespace Nomen\Controllers;

use Nomen\Src\{ActivityLog, Database, Session, Csrf, Response, RateLimiter, Hooks};
use PDO;

class OAuthController
{
    /** 快速登录标记：回调地址上额外带 spicallogin 这个参数，应用侧可据此提示「快速登录成功」 */
    private const QUICK_LOGIN_FLAG = 'caellab-launch-plan-sister-project-quick-login';

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * GET /oauth/authorize - 授权页面
     */
    public function authorize(): void
    {
        $clientId     = $_GET['client_id'] ?? '';
        $redirectUri  = $_GET['redirect_uri'] ?? '';
        $scope        = $_GET['scope'] ?? 'openid';
        $state        = $_GET['state'] ?? '';
        $responseType = $_GET['response_type'] ?? 'code';
        $prompt       = $_GET['prompt'] ?? '';

        // 验证 response_type
        if ($responseType !== 'code') {
            Response::error(400, t('Unsupported response_type — only code is supported'));
            return;
        }

        // 验证 client_id + redirect_uri
        $client = $this->resolveClient($clientId, $redirectUri);
        if (!$client) {
            Response::error(400, t('Invalid client_id, or the redirect_uri was not authorized when the app was registered'));
            return;
        }

        // PKCE：带了 code_challenge 就必须合法；应用被标记为强制 PKCE 时还必须带
        $pkce = $this->parsePkceParams();
        if ($this->pkceMandatory($client) && $pkce['challenge'] === null) {
            Response::error(400, t('This app enforces PKCE — authorization requests must include a code_challenge'));
            return;
        }

        // 第一方应用（开发者 Id=1）：授权页会挂上「官方应用」标记
        $isFirstParty = $this->isFirstParty($client);

        // 验证 scopes（支持空格或逗号分隔）
        $config = require __DIR__ . '/../config/app.php';
        $requestedScopes = preg_split('/[\s,]+/', trim($scope), -1, PREG_SPLIT_NO_EMPTY);
        $validScopes = array_keys($config['scopes']);
        // 应用注册时登记的 scopes 也是一道闸：只跟全局 scope 表求交集的话，一个只登记了
        // openid 的应用照样能把 profile / email 要走。老数据没填、或填了不认识的词时按最小集算。
        $clientScopes = preg_split('/[\s,]+/', trim((string) ($client['scopes'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        $clientScopes = array_values(array_intersect($clientScopes, $validScopes)) ?: ['openid'];
        $grantedScopes = array_values(array_intersect($requestedScopes, $validScopes, $clientScopes));
        if (empty($grantedScopes)) {
            $grantedScopes = ['openid'];
        }
        $scope = implode(' ', $grantedScopes);

        // 未登录则跳转登录
        $userId = Session::get('user_id');
        if (!$userId) {
            // prompt=none：**不能**把人甩到登录页，按 OIDC 的规矩回 error=login_required。
            // （2026-09-26 起各接入站都不做自动登录探测了，没人指望静默拿码，但这是规范要求的
            // 行为，留着 —— 带着 prompt=none 来问一句，就得给一个不打扰的答复。）
            if ($prompt === 'none') {
                $this->errorRedirect($redirectUri, 'login_required', $state);
                return;
            }

            Session::set('oauth_request', [
                'client_id'             => $clientId,
                'redirect_uri'          => $redirectUri,
                'scope'                 => $scope,
                'state'                 => $state,
                'response_type'         => $responseType,
                'code_challenge'        => $pkce['challenge'],
                'code_challenge_method' => $pkce['method'],
            ]);
            $loginUrl = '/login?redirect_to=' . urlencode('/oauth/authorize?' . http_build_query($_GET));
            Response::redirect($loginUrl);
            return;
        }

        // 第一方应用 + 用户开着「快速登录」= 免确认直授，并在回调带上快速登录标记
        $quickLogin = $isFirstParty && $this->quickLoginEnabled((int)$userId);

        // 检查是否已经授权过（且scope相同或更少）
        $existing = $this->getAuthorization($userId, $clientId);
        $allGranted = false;
        if ($existing) {
            $existingScopes = preg_split('/[\s,]+/', trim($existing['scopes']), -1, PREG_SPLIT_NO_EMPTY);
            $allGranted = true;
            foreach ($grantedScopes as $s) {
                if (!in_array($s, $existingScopes)) {
                    $allGranted = false;
                    break;
                }
            }
        }

        // prompt=none 时**绝不能渲染页面**（OIDC 规范）：需要用户交互就只能回 error。
        $silent = ($prompt === 'none');

        // 不经过授权页、直接发码的两种情况：
        //   1) prompt=none：能免确认（已授权，或第一方 + 开着「快速登录」）就直接发码，
        //      需要用户点头就只能回 interaction_required；
        //   2) 普通交互请求 + 应用已授权过、scope 也够，而且不需要中间页
        //      —— 第三方应用，或用户关了快速登录。
        $directIssue = $silent ? ($allGranted || $quickLogin) : ($allGranted && !$quickLogin);

        if ($directIssue) {
            if (!$allGranted) {
                // 只有「第一方 + 开着快速登录 + 首次（或撤销之后）」会走到这儿：
                // 这次的 scope 隐式写进 user_authorizations —— 用户在控制台的已授权列表里
                // 看得到、也能随时撤销，不是"不留痕迹"。
                $this->saveAuthorization($userId, $clientId, $scope);
            }
            // 已授权那条路要自己确保 subject 在；saveAuthorization 内部已经调过了（幂等，重复无害）
            $this->ensureSubject($userId, $clientId);

            $code = $this->createAuthorizationCode(
                $clientId, $userId, $redirectUri, $scope, $pkce['challenge'], $pkce['method']
            );
            $params = ['code' => $code];
            if ($state) $params['state'] = $state;
            // 快速登录标记只在"用户全程没看见任何页面"时带 —— 也就是这条静默直发的路。
            // 中间页那条路用户已经看过打勾动画了，不带，免得应用侧再弹一次多余的提示。
            if ($quickLogin) $params['spicallogin'] = self::QUICK_LOGIN_FLAG;

            $clientName = $this->clientName($clientId);
            ActivityLog::record([
                'action'   => 'oauth.authorize_silent',
                'category' => ActivityLog::CAT_OAUTH,
                'actor_type' => 'user',
                'actor_id' => $userId,
                'target_type' => 'oauth_client',
                'target_id' => $clientId,
                'target_name' => $clientName,
                'summary'  => ($quickLogin ? '快速登录' : '已授权') . '免确认授权应用'
                    . ($clientName !== '' ? '「' . $clientName . '」' : '')
                    . '（scope: ' . $scope . '）',
            ]);

            Hooks::run('oauth.authorize.approved', [(int)$userId, $clientId, $scope, true]);

            Response::redirect($redirectUri . '?' . http_build_query($params));
            return;
        }

        if ($silent) {
            $this->errorRedirect($redirectUri, 'interaction_required', $state);
            return;
        }

        // 剩下的都是用户主动点登录点过来的（比如明确退出之后又点了一次登录），显示授权页面：
        // 第一方 + 开着快速登录走中间页（打勾动画 + 顶部进度条，自动提交后由 handleAuthorize
        // 发码并带上快速登录标记），其余情况是普通授权页，要用户自己勾权限。
        $user = $this->getUser($userId);
        $scopeDescriptions = $config['scopes'];
        $requestedScopeList = $grantedScopes;

        // 存 session 给 handleAuthorize 用，不依赖表单隐藏域
        // （code_challenge 若靠表单传递则可被篡改，放到会话里由服务端自己记）
        Session::set('oauth_request', [
            'client_id'             => $clientId,
            'scope'                 => $scope,
            'code_challenge'        => $pkce['challenge'],
            'code_challenge_method' => $pkce['method'],
        ]);

        Response::view('authorize', compact(
            'client', 'scope', 'state', 'user',
            'scopeDescriptions', 'requestedScopeList',
            'redirectUri', 'clientId', 'responseType', 'isFirstParty', 'quickLogin'
        ));
    }

    /**
     * GET /oauth/deny - 拒绝授权（popup 模式下关闭窗口）
     */
    public function deny(): void
    {
        $state = $_GET['state'] ?? '';
        $redirectUri = $_GET['redirect_uri'] ?? '';
        $clientId = $_GET['client_id'] ?? '';

        // redirect_uri 必须属于该应用，否则这里就是一个开放重定向
        if (!$redirectUri || !$this->resolveClient($clientId, $redirectUri)) {
            echo '<script>window.close();</script>';
            return;
        }

        $separator = strpos($redirectUri, '?') !== false ? '&' : '?';
        $errorUrl = $redirectUri . $separator . 'error=access_denied' . ($state ? '&state=' . urlencode($state) : '');
        Response::redirect($errorUrl);
    }

    /**
     * 按 OAuth 规范把错误回给应用（prompt=none 走这条）
     *
     * 调用方必须先用 resolveClient() 确认过 redirect_uri 属于该 client —— 否则这里就是个
     * 开放重定向（授权错误会带着 error 跳到任意地址去）。
     */
    private function errorRedirect(string $redirectUri, string $error, string $state): void
    {
        $separator = strpos($redirectUri, '?') !== false ? '&' : '?';
        Response::redirect(
            $redirectUri . $separator . 'error=' . urlencode($error) . ($state ? '&state=' . urlencode($state) : '')
        );
    }

    /**
     * POST /oauth/authorize - 处理授权确认
     */
    public function handleAuthorize(): void
    {
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::error(400, t('Invalid request, try again'));
            return;
        }

        $userId = Session::get('user_id');
        if (!$userId) {
            Response::redirect('/login');
            return;
        }

        $action          = $_POST['action'] ?? 'deny';
        $clientId        = $_POST['client_id'] ?? '';
        $redirectUri     = $_POST['redirect_uri'] ?? '';
        $requestedScope  = $_POST['scope'] ?? 'openid';
        $state           = $_POST['state'] ?? '';

        // 授权端点的安全边界：client_id 与 redirect_uri 必须重新查库校验
        // 否则表单里可以被塞任意地址，授权码会直接发到攻击者手上
        $client = $this->resolveClient($clientId, $redirectUri);
        if (!$client) {
            Response::error(400, t('Invalid client_id, or the redirect_uri was not authorized when the app was registered'));
            return;
        }

        // 优先从 session 取 scope / PKCE（authorize() 渲染页面时存的），不依赖表单
        $oauthRequest = Session::get('oauth_request', []);
        if (!empty($oauthRequest['scope'])) {
            $requestedScope = $oauthRequest['scope'];
        }

        // 会话里存的必须是这个应用的请求：同一个浏览器先后打开两个授权页时后一个会覆盖前者，
        // 不认这一下的话，A 应用页面上的「授权」会把 B 应用申请到的 scope 发给 A
        if (!empty($oauthRequest['client_id']) && $oauthRequest['client_id'] !== $clientId) {
            Response::error(400, t('This authorization request has expired — start the authorization again'));
            return;
        }

        $codeChallenge = $oauthRequest['code_challenge'] ?? null;
        $codeChallengeMethod = $oauthRequest['code_challenge_method'] ?? null;

        // 兜底：强制 PKCE 的应用没拿到 challenge（旧页面表单、或将来新增的发码路径漏传）
        if ($this->pkceMandatory($client) && empty($codeChallenge)) {
            Response::error(400, t('This app enforces PKCE — authorization requests must include a code_challenge'));
            return;
        }

        if ($action === 'deny') {
            $separator = strpos($redirectUri, '?') !== false ? '&' : '?';
            Response::redirect($redirectUri . $separator . 'error=access_denied' . ($state ? '&state=' . urlencode($state) : ''));
            return;
        }

        $config = require __DIR__ . '/../config/app.php';
        $validScopes = array_keys($config['scopes']);

        // 只允许授予「应用本次请求的 scope」∩「用户勾选的 scope」
        // 勾选框没提交（用户取消勾选 / 应用只请求了 openid）时，只保留必需的 openid
        $requestedParts = preg_split('/[\s,]+/', trim($requestedScope), -1, PREG_SPLIT_NO_EMPTY);

        // 第一方应用 + 用户开着「快速登录」：授权页没有勾选框，本次请求的 scope 全部授予
        $quickLogin = $this->isFirstParty($client) && $this->quickLoginEnabled((int)$userId);

        if ($quickLogin) {
            $scopeParts = array_values(array_intersect($requestedParts, $validScopes));
        } else {
            $postedScopes = (isset($_POST['scopes']) && is_array($_POST['scopes'])) ? $_POST['scopes'] : [];

            $scopeParts = array_values(array_unique(
                array_intersect($postedScopes, $validScopes, $requestedParts)
            ));
        }

        if (!in_array('openid', $scopeParts, true)) {
            $scopeParts[] = 'openid';
        }

        $scope = implode(' ', $scopeParts);

        // 用完清掉 session
        Session::remove('oauth_request');

        // 授权通过
        $code = $this->createAuthorizationCode(
            $clientId, $userId, $redirectUri, $scope, $codeChallenge, $codeChallengeMethod
        );
        $this->saveAuthorization($userId, $clientId, $scope);

        Hooks::run('oauth.authorize.approved', [(int)$userId, $clientId, $scope, false]);

        $clientName = $this->clientName($clientId);
        ActivityLog::record([
            'action'   => 'oauth.authorize',
            'category' => ActivityLog::CAT_OAUTH,
            'actor_type' => 'user',
            'actor_id' => $userId,
            'target_type' => 'oauth_client',
            'target_id' => $clientId,
            'target_name' => $clientName,
            'summary'  => '授权应用' . ($clientName !== '' ? '「' . $clientName . '」' : '')
                . '（scope: ' . $scope . '）',
        ]);

        $params = ['code' => $code];
        if ($state) $params['state'] = $state;
        // 这里只可能是中间页（或普通授权页）提交回来的 —— 用户刚看过页面，不带快速登录标记
        Response::redirect($redirectUri . '?' . http_build_query($params));
    }

    /**
     * POST /oauth/token - 令牌端点
     */
    public function token(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        // 每分钟 30 次限流（基于 IP + client_id）
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rateCheck = RateLimiter::check("oauth:token:{$ip}", 30, 60);
        if (!$rateCheck['allowed']) {
            header('Retry-After: ' . $rateCheck['retry_after']);
            Response::json(['error' => 'rate_limited', 'error_description' => t('Too many requests, try again later')], 429);
            return;
        }

        $grantType    = $_POST['grant_type'] ?? '';
        $code         = $_POST['code'] ?? '';
        $redirectUri  = $_POST['redirect_uri'] ?? '';
        $clientId     = $_POST['client_id'] ?? '';
        $clientSecret = $_POST['client_secret'] ?? '';
        $refreshToken = $_POST['refresh_token'] ?? '';
        $codeVerifier = $_POST['code_verifier'] ?? '';

        // Basic Auth 支持
        if (!$clientId && isset($_SERVER['PHP_AUTH_USER'])) {
            $clientId = $_SERVER['PHP_AUTH_USER'];
            $clientSecret = $_SERVER['PHP_AUTH_PW'] ?? '';
        }

        // 验证客户端（支持明文和 hash 两种存储方式的兼容验证）
        $client = $this->getClient($clientId);
        if (!$client) {
            Response::json(['error' => 'invalid_client', 'error_description' => t('Client authentication failed')], 401);
            return;
        }

        // 公共客户端（桌面 / SPA / CLI）拿不住密钥，不校验 client_secret，
        // 安全性由强制 PKCE 承担：换码时必须出示与 code_challenge 匹配的 code_verifier
        if (($client['client_type'] ?? 'confidential') !== 'public') {
            $storedSecret = $client['client_secret'];
            $hashAlg = $this->getHashAlgorithm();
            $secretValid = hash_equals($storedSecret, $clientSecret)
                        || hash_equals(hash('sha256', $clientSecret), $storedSecret)
                        || hash_equals(hash($hashAlg, $clientSecret), $storedSecret);
            if (!$secretValid) {
                Response::json(['error' => 'invalid_client', 'error_description' => t('Client authentication failed')], 401);
                return;
            }
        }

        if ($grantType === 'authorization_code') {
            $this->handleAuthorizationCodeGrant($code, $redirectUri, $clientId, $codeVerifier, $client);
        } elseif ($grantType === 'refresh_token') {
            $this->handleRefreshTokenGrant($refreshToken, $clientId);
        } else {
            Response::json(['error' => 'unsupported_grant_type', 'error_description' => t('Unsupported grant type')], 400);
        }
    }

    /**
     * POST /oauth/revoke - 撤销令牌
     */
    public function revoke(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $token = $_POST['token'] ?? '';
        $tokenTypeHint = $_POST['token_hint_type'] ?? '';

        if (!$token) {
            Response::json(['error' => 'invalid_request'], 400);
            return;
        }

        $this->revokeTokenByHash($token, 'oauth_access_tokens');
        $this->revokeTokenByHash($token, 'oauth_refresh_tokens');

        Hooks::run('oauth.token.revoked', [$tokenTypeHint]);

        http_response_code(200);
        echo '{}';
        exit;
    }

    // ========== 私有方法 ==========

    private function clientName(string $clientId): string
    {
        $stmt = $this->db->prepare('SELECT name FROM oauth_clients WHERE client_id = :c LIMIT 1');
        $stmt->execute([':c' => $clientId]);
        return (string)($stmt->fetchColumn() ?: '');
    }

    private function handleAuthorizationCodeGrant(
        string $code,
        string $redirectUri,
        string $clientId,
        string $codeVerifier,
        array $client
    ): void {
        if (!$code || !$redirectUri) {
            Response::json(['error' => 'invalid_request', 'error_description' => t('Missing required parameters')], 400);
            return;
        }

        // 查找授权码
        $stmt = $this->db->prepare(
            'SELECT * FROM oauth_authorization_codes WHERE code = :c AND client_id = :clid AND expires_at > NOW()'
        );
        $stmt->execute([':c' => $code, ':clid' => $clientId]);
        $authCode = $stmt->fetch();

        if (!$authCode) {
            Response::json(['error' => 'invalid_grant', 'error_description' => t('Authorization code invalid or expired')], 400);
            return;
        }

        if ($authCode['redirect_uri'] !== $redirectUri) {
            Response::json(['error' => 'invalid_grant', 'error_description' => t('redirect_uri mismatch')], 400);
            return;
        }

        // PKCE 校验必须排在删码之前：否则拿一个错误 verifier 打过来就能把
        // 别人还没换走的授权码销毁掉，白送一个拒绝服务
        $pkceError = $this->verifyPkce($authCode, $client, $codeVerifier);
        if ($pkceError !== null) {
            Response::json(['error' => $pkceError[0], 'error_description' => $pkceError[1]], 400);
            return;
        }

        // 删除已使用的授权码
        $stmt = $this->db->prepare('DELETE FROM oauth_authorization_codes WHERE code = :c');
        $stmt->execute([':c' => $code]);

        // 生成 token
        $config = require __DIR__ . '/../config/app.php';
        $accessToken = $this->generateToken();
        $refreshTokenValue = $this->generateToken();

        $accessTokenHash = $this->hashToken($accessToken);
        $refreshTokenHash = $this->hashToken($refreshTokenValue);

        $accessExpiresAt = date('Y-m-d H:i:s', time() + $config['access_token_ttl']);
        $refreshExpiresAt = date('Y-m-d H:i:s', time() + $config['refresh_token_ttl']);

        // 保存 access token
        $stmt = $this->db->prepare(
            'INSERT INTO oauth_access_tokens (token_hash, client_id, user_id, scopes, expires_at) VALUES (:t, :c, :u, :s, :e)'
        );
        $stmt->execute([
            ':t' => $accessTokenHash,
            ':c' => $clientId,
            ':u' => $authCode['user_id'],
            ':s' => $authCode['scopes'],
            ':e' => $accessExpiresAt,
        ]);
        $accessTokenId = (int)$this->db->lastInsertId();

        // 保存 refresh token
        $stmt = $this->db->prepare(
            'INSERT INTO oauth_refresh_tokens (token_hash, access_token_id, client_id, user_id, scopes, expires_at) VALUES (:t, :a, :c, :u, :s, :e)'
        );
        $stmt->execute([
            ':t' => $refreshTokenHash,
            ':a' => $accessTokenId,
            ':c' => $clientId,
            ':u' => $authCode['user_id'],
            ':s' => $authCode['scopes'],
            ':e' => $refreshExpiresAt,
        ]);

        ActivityLog::record([
            'action'   => 'oauth.token_issued',
            'category' => ActivityLog::CAT_OAUTH,
            'actor_type' => 'system',
            'actor_id' => (int)$authCode['user_id'],
            'target_type' => 'oauth_client',
            'target_id' => $clientId,
            'target_name' => $this->clientName($clientId),
            'summary'  => '应用换取访问令牌（scope: ' . $authCode['scopes'] . '）',
            // 这个请求来自接入方服务器，REMOTE_ADDR 是源站自己的地址，不是用户
            'skip_ip'  => true,
        ]);

        Hooks::run('oauth.token.issued', [
            (int)$authCode['user_id'],
            $clientId,
            (string)$authCode['scopes'],
            'authorization_code',
        ]);

        Response::json([
            'access_token'  => $accessToken,
            'token_type'    => 'Bearer',
            'expires_in'    => $config['access_token_ttl'],
            'refresh_token' => $refreshTokenValue,
            'scope'         => $authCode['scopes'],
        ]);
    }

    private function handleRefreshTokenGrant(string $refreshToken, string $clientId): void
    {
        if (!$refreshToken) {
            Response::json(['error' => 'invalid_request', 'error_description' => t('Missing refresh_token')], 400);
            return;
        }

        $refresh = $this->findTokenByHash($refreshToken, 'oauth_refresh_tokens');

        if (!$refresh || $refresh['client_id'] !== $clientId) {
            Response::json(['error' => 'invalid_grant', 'error_description' => t('refresh_token is invalid or expired')], 400);
            return;
        }

        $this->revokeTokenByHash($refreshToken, 'oauth_refresh_tokens');

        $stmt = $this->db->prepare('UPDATE oauth_access_tokens SET revoked = 1 WHERE id = :id');
        $stmt->execute([':id' => $refresh['access_token_id']]);

        $config = require __DIR__ . '/../config/app.php';
        $newAccessToken = $this->generateToken();
        $newRefreshToken = $this->generateToken();

        $accessExpiresAt = date('Y-m-d H:i:s', time() + $config['access_token_ttl']);
        $refreshExpiresAt = date('Y-m-d H:i:s', time() + $config['refresh_token_ttl']);

        $stmt = $this->db->prepare(
            'INSERT INTO oauth_access_tokens (token_hash, client_id, user_id, scopes, expires_at) VALUES (:t, :c, :u, :s, :e)'
        );
        $stmt->execute([
            ':t' => $this->hashToken($newAccessToken),
            ':c' => $clientId,
            ':u' => $refresh['user_id'],
            ':s' => $refresh['scopes'],
            ':e' => $accessExpiresAt,
        ]);
        $newAccessTokenId = (int)$this->db->lastInsertId();

        $stmt = $this->db->prepare(
            'INSERT INTO oauth_refresh_tokens (token_hash, access_token_id, client_id, user_id, scopes, expires_at) VALUES (:t, :a, :c, :u, :s, :e)'
        );
        $stmt->execute([
            ':t' => $this->hashToken($newRefreshToken),
            ':a' => $newAccessTokenId,
            ':c' => $clientId,
            ':u' => $refresh['user_id'],
            ':s' => $refresh['scopes'],
            ':e' => $refreshExpiresAt,
        ]);

        Hooks::run('oauth.token.issued', [
            (int)$refresh['user_id'],
            $clientId,
            (string)$refresh['scopes'],
            'refresh_token',
        ]);

        Response::json([
            'access_token'  => $newAccessToken,
            'token_type'    => 'Bearer',
            'expires_in'    => $config['access_token_ttl'],
            'refresh_token' => $newRefreshToken,
            'scope'         => $refresh['scopes'],
        ]);
    }

    private function getClient(string $clientId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT oc.*, d.name AS developer_name, d.icon AS developer_icon FROM oauth_clients oc
             LEFT JOIN developers d ON d.id = oc.developer_id
             WHERE oc.client_id = :c AND oc.status = 1'
        );
        $stmt->execute([':c' => $clientId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * 校验 client_id 与 redirect_uri 是否匹配，匹配则返回客户端记录
     *
     * 授权端点唯一的安全边界就是 redirect_uri 白名单：
     * 任何走到「跳回应用」的路径都必须先过这里，绝不能信任请求参数
     */
    private function resolveClient(string $clientId, string $redirectUri): ?array
    {
        if (!$clientId || !$redirectUri) {
            return null;
        }

        $client = $this->getClient($clientId);
        if (!$client) {
            return null;
        }

        $allowedUris = json_decode($client['redirect_uris'], true) ?: [];
        if (!in_array($redirectUri, $allowedUris, true)) {
            return null;
        }

        return $client;
    }

    /**
     * 是否为第一方应用（开发者 Id=1，即虚舟实验室自己的应用）
     */
    private function isFirstParty(array $client): bool
    {
        return (int)($client['developer_id'] ?? 0) === 1;
    }

    /**
     * 用户是否开着「快速登录」（users.quick_login，默认开启）
     * 关闭后第一方应用也走普通授权确认流程，回调不带快速登录标记
     */
    private function quickLoginEnabled(int $userId): bool
    {
        $stmt = $this->db->prepare('SELECT quick_login FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        return !$row || (int)$row['quick_login'] === 1;
    }

    private function getUser(int $userId): ?array
    {
        // 临时密钥过期/撤销检查
        if (Session::get('temp_key_id')) {
            $stmt = $this->db->prepare(
                'SELECT id FROM user_temp_keys WHERE id = :id AND expires_at > NOW()'
            );
            $stmt->execute([':id' => Session::get('temp_key_id')]);
            if (!$stmt->fetch()) {
                Session::destroy();
                Session::flash('error', t('Temp key expired or revoked'));
                Response::redirect('/login');
            }
        }

        $stmt = $this->db->prepare(
            'SELECT id, username, email, display_name, avatar FROM users WHERE id = :id'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch() ?: null;
        if ($user) {
            $user['avatar_url'] = \Nomen\Controllers\DashboardController::resolveAvatarUrl($user['avatar']);
        }
        return $user;
    }

    private function getAuthorization(int $userId, string $clientId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM user_authorizations WHERE user_id = :u AND client_id = :c'
        );
        $stmt->execute([':u' => $userId, ':c' => $clientId]);
        return $stmt->fetch() ?: null;
    }

    private function saveAuthorization(int $userId, string $clientId, string $scope): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO user_authorizations (user_id, client_id, scopes) VALUES (:u, :c, :s) ' .
            Database::dialectFor($this->db)->upsert(['user_id', 'client_id'], 'scopes = :s2, updated_at = NOW()')
        );
        $stmt->execute([':u' => $userId, ':c' => $clientId, ':s' => $scope, ':s2' => $scope]);

        // 同步写入 user_app_subjects（已有记录则跳过，不动老用户）
        $this->ensureSubject($userId, $clientId);
    }

    /**
     * 确保 user_app_subjects 中有记录
     * 已有记录跳过，只给首次授权的新用户生成 subject_id
     */
    private function ensureSubject(int $userId, string $clientId): void
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM user_app_subjects WHERE user_id = :u AND client_id = :c LIMIT 1'
        );
        $stmt->execute([':u' => $userId, ':c' => $clientId]);
        if ($stmt->fetch()) {
            return; // 已有记录，不动老用户
        }

        $subjectId = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare(
            Database::dialectFor($this->db)->insertIgnore(
                'INSERT IGNORE INTO user_app_subjects (user_id, client_id, subject_id) VALUES (:u, :c, :s)'
            )
        );
        $stmt->execute([':u' => $userId, ':c' => $clientId, ':s' => $subjectId]);
    }

    private function updateAuthorization(int $userId, string $clientId, string $scope): void
    {
        $stmt = $this->db->prepare(
            'UPDATE user_authorizations SET scopes = :s, updated_at = NOW() WHERE user_id = :u AND client_id = :c'
        );
        $stmt->execute([':s' => $scope, ':u' => $userId, ':c' => $clientId]);
    }

    private function createAuthorizationCode(
        string $clientId,
        int $userId,
        string $redirectUri,
        string $scope,
        ?string $codeChallenge = null,
        ?string $codeChallengeMethod = null
    ): string {
        $config = require __DIR__ . '/../config/app.php';
        $code = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + $config['auth_code_ttl']);

        $stmt = $this->db->prepare(
            'INSERT INTO oauth_authorization_codes (code, client_id, user_id, redirect_uri, scopes, code_challenge, code_challenge_method, expires_at)
             VALUES (:c, :clid, :u, :r, :s, :cc, :ccm, :e)'
        );
        $stmt->execute([
            ':c'    => $code,
            ':clid' => $clientId,
            ':u'    => $userId,
            ':r'    => $redirectUri,
            ':s'    => $scope,
            ':cc'   => $codeChallenge,
            ':ccm'  => $codeChallenge !== null ? $codeChallengeMethod : null,
            ':e'    => $expiresAt,
        ]);

        return $code;
    }

    /**
     * 解析授权请求里的 PKCE 参数
     *
     * @return array{challenge: ?string, method: ?string} challenge 为 null 表示本次请求没用 PKCE
     * 参数非法时直接回 400 错误页（Response::error 内部会 exit，不会返回）
     */
    private function parsePkceParams(): array
    {
        $challenge = trim((string)($_GET['code_challenge'] ?? ''));
        $method    = trim((string)($_GET['code_challenge_method'] ?? ''));

        if ($challenge === '') {
            return ['challenge' => null, 'method' => null];
        }

        // RFC 7636：code_challenge 是 43-128 位 unreserved 字符
        if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $challenge)) {
            Response::error(400, t('Invalid code_challenge (must be 43-128 unreserved characters)'));
            return ['challenge' => null, 'method' => null];
        }

        // plain 相当于把 verifier 明文放进 URL，没有任何价值，只收 S256
        if ($method !== 'S256') {
            Response::error(400, t('Unsupported code_challenge_method — this service only supports S256'));
            return ['challenge' => null, 'method' => null];
        }

        return ['challenge' => $challenge, 'method' => $method];
    }

    /**
     * 该应用是否强制 PKCE
     *
     * 公共客户端没有密钥可验，恒强制；机密客户端看应用设置里的开关
     * （老应用该开关为关，所以不带 code_challenge 的老接入方不受影响）
     */
    private function pkceMandatory(array $client): bool
    {
        return ($client['client_type'] ?? 'confidential') === 'public'
            || (int)($client['pkce_required'] ?? 0) === 1;
    }

    /**
     * 校验授权码与 code_verifier 的绑定关系
     *
     * @return array{0: string, 1: string}|null 出错时返回 [error, error_description]，通过返回 null
     */
    private function verifyPkce(array $authCode, array $client, string $codeVerifier): ?array
    {
        $challenge = (string)($authCode['code_challenge'] ?? '');
        $method    = (string)($authCode['code_challenge_method'] ?? '');

        if ($challenge === '') {
            // 授权时就没带 challenge：强制 PKCE 的应用不该出现这种码，兜一道
            if ($this->pkceMandatory($client)) {
                return ['invalid_grant', t('This app requires PKCE, but the authorization code has no code_challenge bound')];
            }
            return null;
        }

        if ($codeVerifier === '') {
            return ['invalid_request', t('Missing code_verifier')];
        }

        if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $codeVerifier)) {
            return ['invalid_grant', t('Invalid code_verifier format')];
        }

        if ($method !== 'S256') {
            return ['invalid_grant', t('Unsupported code_challenge_method')];
        }

        $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        if (!hash_equals($challenge, $computed)) {
            return ['invalid_grant', t('code_verifier verification failed')];
        }

        return null;
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function getHashAlgorithm(): string
    {
        $config = require __DIR__ . '/../config/app.php';
        return $config['token_hash_algorithm'] ?? 'sha512';
    }

    private function hashToken(string $token): string
    {
        return hash($this->getHashAlgorithm(), $token);
    }

    private function findTokenByHash(string $token, string $table): ?array
    {
        $algorithms = [$this->getHashAlgorithm(), 'sha256'];
        foreach ($algorithms as $alg) {
            $tokenHash = hash($alg, $token);
            $stmt = $this->db->prepare(
                "SELECT * FROM {$table} WHERE token_hash = :t AND revoked = 0 AND expires_at > NOW()"
            );
            $stmt->execute([':t' => $tokenHash]);
            if ($result = $stmt->fetch()) {
                return $result;
            }
        }
        return null;
    }

    private function revokeTokenByHash(string $token, string $table): void
    {
        $algorithms = [$this->getHashAlgorithm(), 'sha256'];
        foreach ($algorithms as $alg) {
            $tokenHash = hash($alg, $token);
            $stmt = $this->db->prepare("UPDATE {$table} SET revoked = 1 WHERE token_hash = :t");
            $stmt->execute([':t' => $tokenHash]);
        }
    }
}

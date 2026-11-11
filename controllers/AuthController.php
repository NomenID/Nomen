<?php
namespace Nomen\Controllers;

use Nomen\Src\{ActivityLog, Database, Session, Csrf, Response, RateLimiter, Email, EmailTemplate, Captcha, GeoIP, I18n, RecoveryHelper, VerifyCode, FamilyHelper, EmailPolicy, EmailSet, Settings, Skins, Hooks, Features, LoginMethods, Unid};
use Nomen\Controllers\{DeviceController, DashboardController};
use PDO;

class AuthController
{
    private PDO $db;

    /** 待注册验证码有效期（秒），与登录验证码一致 */
    private const CLAIM_TTL = 600;
    /** 同一待注册行最多错几次验证码，超了就作废、要求重发 */
    private const CLAIM_MAX_ATTEMPTS = 5;
    /** 手动「重新发送」的冷却秒数 */
    private const CLAIM_RESEND_COOLDOWN = 60;
    /**
     * 这些表没有指向 users 的外键，删号时不会被级联清掉，得自己动手
     * （公开是为了让 ActivationController 的注销那条路也清一遍）。
     */
    public const ORPHAN_TABLES = ['notifications', 'user_notification_settings', 'user_recovery_codes', 'user_totp_secrets', 'user_temp_keys', 'user_emails'];

    /** 登录页上可用的登录方式（顺序即界面上的推荐次序），登录页与「本次已尝试」共用这套名称 */
    public const LOGIN_METHODS = [
        'password'   => 'Password',
        'email_code' => 'Email code',
        'biometric'  => 'Biometric',
    ];

    /** 「本次已尝试」区里可能出现的方式名称（临时密钥在独立的 /fast-pass 页面上，没有主区表单） */
    public const LOGIN_METHOD_LABELS = self::LOGIN_METHODS + ['temp_key' => 'Temp key sign-in'];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 登录页面
     */
    public function login(): void
    {
        // 已登录则跳转
        if (Session::get('user_id')) {
            $redirect = Session::get('post_login_redirect', '/dashboard');
            Session::remove('post_login_redirect');
            Response::redirect($redirect);
        }

        $error = Session::getFlash('error');
        $success = Session::getFlash('success');
        $redirect_to = $_GET['redirect_to'] ?? '/dashboard';

        // 账号注销成功提示
        if (isset($_GET['deleted'])) {
            $success = '账号已成功注销';
        }

        // 防止开放重定向
        if (!str_starts_with($redirect_to, '/') || str_contains($redirect_to, '://')) {
            $redirect_to = '/dashboard';
        }

        // 图片验证码默认不出现，只有该 IP 短时间内尝试过多才要求
        $requireCaptcha = (bool)Session::getFlash('require_captcha')
            || ($this->isCaptchaRequired() && !$this->captchaGraceActive());

        $failedMethods = self::failedLoginMethods();
        $methods = LoginMethods::all();

        Response::view('login', compact('error', 'success', 'redirect_to', 'requireCaptcha', 'failedMethods', 'methods'));
    }

    /**
     * 处理登录（第一因素：密码 / 临时密钥）
     */
    public function handleLogin(): void
    {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        [$redirect_to, $loginUrl] = $this->resolveRedirect();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $this->failLogin($loginUrl, $isAjax, '请求无效，请重试');
        }

        if (!LoginMethods::on('password')) {
            $this->failLogin($loginUrl, $isAjax, '管理员已关闭密码登录，请改用其它方式登录');
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // 先定位账号（也用于用户维度限流）。邮箱可能命中 2 个账号，所以是一组候选
        $accounts = $this->findAccountsByIdentifier($username);

        foreach ($accounts as $candidate) {
            if ($this->enforceLoginGuards($loginUrl, $isAjax, (int)$candidate['id'])) {
                return;
            }
        }
        if (!$accounts && $this->enforceLoginGuards($loginUrl, $isAjax, null)) {
            return;
        }

        if (!$username || !$password) {
            $this->failLogin($loginUrl, $isAjax, '请输入用户名和密码');
        }

        if (!$accounts) {
            $this->failLogin($loginUrl, $isAjax, '用户名或密码错误', 'password');
        }

        $config = require __DIR__ . '/../config/app.php';
        $pepper = $config['pepper'] ?? '';
        $isTempKeyLogin = false;

        // 同一个邮箱对应两个账号时，密码就是用来区分它们的：逐个试，谁对上算谁。
        // 都对上（两边密码一样）时优先选没被禁用的那个。
        $user = null;
        $disabledMatch = null;
        foreach ($accounts as $candidate) {
            if (!password_verify($password . $pepper, (string)$candidate['password_hash'])) {
                if (password_verify($password, (string)$candidate['password_hash'])) {
                    // 历史遗留的无 pepper 哈希，验证通过后就地升级
                    $newHash = password_hash($password . $pepper, PASSWORD_ARGON2ID);
                    $this->db->prepare('UPDATE users SET password_hash = :p WHERE id = :id')
                        ->execute([':p' => $newHash, ':id' => $candidate['id']]);
                } else {
                    continue;
                }
            }
            if ((int)$candidate['status'] === 1) {
                $user = $candidate;
                break;
            }
            $disabledMatch = $disabledMatch ?? $candidate;
        }

        // 密码都不对：再按临时密钥逐个试一遍（应急通道，不参与两步验证策略）
        if ($user === null) {
            foreach ($accounts as $candidate) {
                if ($this->verifyTempKey((int)$candidate['id'], $password)) {
                    $user = $candidate;
                    $isTempKeyLogin = true;
                    break;
                }
            }
        }

        if ($user === null && $disabledMatch !== null) {
            $this->failLogin($loginUrl, $isAjax, '该账户已被禁用');
        }
        if ($user === null) {
            $this->failLogin($loginUrl, $isAjax, '用户名或密码错误', 'password');
        }

        if ((int)$user['status'] !== 1) {
            $this->failLogin($loginUrl, $isAjax, '该账户已被禁用');
        }

        // 临时密钥属于应急通道，不参与两步验证策略
        if ($isTempKeyLogin) {
            self::finishLogin($user, $redirect_to, !empty($_POST['temporary']), $isAjax, [], 'temp_key');
            return;
        }

        Session::regenerate();
        if (self::beginSecondFactorIfNeeded($user, 'password', $redirect_to, !empty($_POST['temporary']))) {
            Response::redirect('/2fa/verify');
        }

        self::finishLogin($user, $redirect_to, !empty($_POST['temporary']), $isAjax);
    }

    // ========== 登录统一链路 ==========

    /**
     * 解析并校验跳转目标，避免开放重定向
     *
     * @return array{0:string,1:string} [redirect_to, 回登录页的地址]
     */
    private function resolveRedirect(): array
    {
        $redirect_to = $_POST['redirect_to'] ?? $_GET['redirect_to'] ?? '/dashboard';
        if (!str_starts_with($redirect_to, '/') || str_contains($redirect_to, '://')) {
            $redirect_to = '/dashboard';
        }
        return [$redirect_to, '/login?redirect_to=' . urlencode($redirect_to)];
    }

    /**
     * 按「账号标识」找出**全部**候选账号：带 @ 的当邮箱，其余当用户名。
     *
     * 用户名只允许小写字母/数字/下划线，永远不含 @，所以这个判别不会歧义。
     * 未成年账号没有自己的邮箱，登录验证码那条路就靠用户名找到人。
     *
     * ⚠️ 邮箱**最多命中 2 个账号**：一个拿它当主邮箱、一个拿它当备选
     * （两个位各自唯一、互不干扰，所以这个组合是允许的，见 EmailSet 类注释）。
     * 所以这里**绝不挑一个**给调用方 —— 密码登录靠密码去区分，验证码登录
     * 交给用户自己选（offerAccountChoice）。以前这里是一条 SQL 加 LIMIT 1，
     * 落到哪个账号取决于返回顺序，等于把「用这个邮箱登进哪个号」交给运气。
     *
     * @return array<int, array> 候选（用户名最多 1 个，邮箱最多 2 个；主邮箱持有者在前）
     */
    private function findAccountsByIdentifier(string $identifier): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return [];
        }

        $columns = 'id, username, email, password_hash, display_name, avatar, status, role, email_verified, two_factor_policy, locale, skin';

        if (!str_contains($identifier, '@')) {
            $stmt = $this->db->prepare('SELECT ' . $columns . ' FROM users WHERE username = :v LIMIT 1');
            $stmt->execute([':v' => $identifier]);
            $row = $stmt->fetch();
            return $row ? [$row] : [];
        }

        // 邮箱：候选 id 由 EmailSet 给（主邮箱持有者 + 已验证备选的持有者），再换成完整行
        $candidates = EmailSet::candidatesForEmail($identifier);
        if ($candidates === []) {
            return [];
        }

        $ids  = array_map(static fn(array $c): int => (int)$c['id'], $candidates);
        $stmt = $this->db->prepare(
            'SELECT ' . $columns . ' FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute($ids);

        $byId = [];
        foreach ($stmt->fetchAll() as $row) {
            $byId[(int)$row['id']] = $row;
        }

        $out = [];
        foreach ($candidates as $candidate) {
            $id = (int)$candidate['id'];
            if (!isset($byId[$id])) {
                continue;
            }
            $byId[$id]['as_primary'] = !empty($candidate['as_primary']);
            $out[] = $byId[$id];
        }

        return $out;
    }

    /**
     * 「这个账号绑了多个邮箱」时，把可选的收信地址交回前端让用户自己挑发哪一个。
     *
     * **只给打码值 + 行 id**：这一页是未登录状态，把真实地址列出来等于给任何人
     * 一个「这个账号绑了哪些邮箱」的接口。
     */
    private function offerAddressChoice(array $addresses, array $user, string $loginUrl, bool $isAjax): void
    {
        $options = [];
        foreach ($addresses as $address) {
            $options[] = [
                'id'         => (int)$address['id'],
                'masked'     => (string)$address['masked'],
                'is_primary' => !empty($address['is_primary']),
            ];
        }

        if ($isAjax) {
            Response::json([
                'success'             => false,
                'need_address_choice' => true,
                'message'             => t('Several addresses are bound — pick one for the code'),
                'username'            => (string)$user['username'],
                'addresses'           => $options,
            ]);
        }

        Session::flash('error', t('Several addresses are bound — enter the identifier again and pick a mailbox'));
        Response::redirect($loginUrl);
    }

    /**
     * 「这个邮箱对应多个账号」时，把候选交回前端让用户自己挑。
     *
     * 不挑就只能静默落到某一个账号上，而落哪个取决于 SQL 返回顺序。
     */
    private function offerAccountChoice(array $accounts, string $loginUrl, bool $isAjax): void
    {
        $options = [];
        foreach ($accounts as $candidate) {
            $options[] = [
                'id'           => (int)$candidate['id'],
                'username'     => (string)$candidate['username'],
                'display_name' => (string)($candidate['display_name'] ?? ''),
                'avatar'       => DashboardController::resolveAvatarUrl($candidate['avatar'] ?? null),
                'as_primary'   => !empty($candidate['as_primary']),
            ];
        }

        if ($isAjax) {
            Response::json([
                'success'             => false,
                'need_account_choice' => true,
                'message'             => t('This address matches several accounts — pick one to sign in'),
                'accounts'            => $options,
            ]);
        }

        Session::flash('error', t('This address matches several accounts — enter it again and pick from the chooser'));
        Response::redirect($loginUrl);
    }

    /**
     * 该 IP 当前是否需要图片验证码
     */
    private function isCaptchaRequired(): bool
    {
        $config = require __DIR__ . '/../config/app.php';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!Captcha::available()) {
            return false;
        }

        $threshold = (int)($config['login_captcha_threshold'] ?? 5);

        return RateLimiter::count("auth:login:{$ip}", 60) > $threshold;
    }

    /**
     * 图片验证码宽限期内（刚通过过一次）
     */
    private function captchaGraceActive(): bool
    {
        return (int)Session::get('captcha_passed_until', 0) > time();
    }

    /**
     * 登录入口通用防护：条件图片验证码 + IP 硬上限 + 用户维度限流
     *
     * @return bool true 表示已经终止响应（调用方应立即 return）
     */
    private function enforceLoginGuards(string $loginUrl, bool $isAjax, ?int $userId): bool
    {
        $config = require __DIR__ . '/../config/app.php';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // 正常用户不会看到验证码；只有该 IP 尝试次数超过阈值才要求
        // 通过后给 2 分钟宽限：验证码是用后即焚的，否则「先发验证码、再提交」会二次索要
        if ($this->isCaptchaRequired() && !$this->captchaGraceActive()) {
            if (!Captcha::verify($_POST['captcha'] ?? null)) {
                Session::flash('require_captcha', true);
                $this->failLogin($loginUrl, $isAjax, '登录尝试过于频繁，请输入图片验证码');
                return true;
            }
            Session::set('captcha_passed_until', time() + 120);
        }

        // IP 硬上限兜底：防止换着账号暴力破解
        $hardLimit = (int)($config['login_ip_hard_limit'] ?? 20);
        $ipRate = RateLimiter::check("auth:login:{$ip}", $hardLimit, 60);
        if (!$ipRate['allowed']) {
            $this->failLogin($loginUrl, $isAjax, '登录尝试太频繁，请 ' . $ipRate['retry_after'] . ' 秒后重试');
            return true;
        }

        // 用户维度限流
        if ($userId !== null && $userId > 0) {
            $userRate = RateLimiter::check("auth:login:user:{$userId}", 5, 60);
            if (!$userRate['allowed']) {
                $this->failLogin($loginUrl, $isAjax, '登录尝试太频繁，请 ' . $userRate['retry_after'] . ' 秒后重试');
                return true;
            }
        }

        return false;
    }

    /**
     * 统一的登录失败处理：AJAX 返回 JSON，否则 flash + 回登录页
     *
     * @param string|null $method 传了表示「这个登录方式本身没成功」，登录页会把主区让给别的方式；
     *                            输入没填全、图片验证码没过、账号被禁用这类不算，传 null
     */
    private function failLogin(string $loginUrl, bool $isAjax, string $message, ?string $method = null): void
    {
        if ($method !== null) {
            self::recordFailedMethod($method, $message);
        }

        ActivityLog::record([
            'action'   => 'auth.login_failed',
            'category' => ActivityLog::CAT_AUTH,
            'actor_type' => 'system',
            'actor_username' => mb_substr(trim((string)($_POST['username'] ?? $_POST['email'] ?? '')), 0, 64, 'UTF-8'),
            'summary'  => '登录失败：' . $message,
        ]);

        if ($isAjax) {
            Response::json(['success' => false, 'message' => $message]);
        }
        Session::flash('error', $message);
        Response::redirect($loginUrl);
    }

    /**
     * 记下「本次会话里这个登录方式没成功」，登录页据此把主区换成别的方式
     */
    public static function recordFailedMethod(string $method, string $reason): void
    {
        $recorded = Session::get('login_failed_methods', []);
        if (!is_array($recorded)) {
            $recorded = [];
        }
        $recorded[$method] = ['at' => time(), 'reason' => $reason];
        Session::set('login_failed_methods', $recorded);
    }

    /**
     * 本次会话里试过且没成功的登录方式（超出窗口的记录顺带清掉）
     *
     * @return array<string,array{at:int,reason:string}>
     */
    public static function failedLoginMethods(): array
    {
        $recorded = Session::get('login_failed_methods', []);
        if (!is_array($recorded)) {
            return [];
        }

        $config = require __DIR__ . '/../config/app.php';
        $ttl = (int)($config['login_failed_method_ttl'] ?? 900);
        $now = time();

        $fresh = [];
        foreach ($recorded as $method => $item) {
            if (!is_array($item) || ($now - (int)($item['at'] ?? 0)) > $ttl) {
                continue;
            }
            $fresh[(string)$method] = ['at' => (int)$item['at'], 'reason' => (string)($item['reason'] ?? '')];
        }

        if ($fresh !== $recorded) {
            Session::set('login_failed_methods', $fresh);
        }

        return $fresh;
    }

    /**
     * 第一因素通过后的统一判定：要不要进第二因素验证
     *
     * 需要二验时写入 2fa_pending 并返回 true（调用方跳 /2fa/verify）；
     * 不需要二验、或该账号一个可用的第二因素都没有时返回 false。
     *
     * @param array  $user        必须包含 id / username / role / two_factor_policy
     * @param string $firstFactor 已通过的第一因素，二验候选里会排除它
     */
    public static function beginSecondFactorIfNeeded(array $user, string $firstFactor, string $redirect_to, bool $temporary): bool
    {
        $userId = (int)$user['id'];
        $policy = $user['two_factor_policy'] ?? 'new_device';

        // 站点级开关优先：默认关闭（开源版装完就能进后台），站长在后台按需开
        $sitePolicy = (string)Settings::get('security.login_2fa', 'off');
        if (!in_array($sitePolicy, ['off', 'new_device', 'every_login'], true)) {
            $sitePolicy = 'off';
        }
        if ($sitePolicy === 'off') {
            return false;
        }

        // every_login：每次登录都二验；new_device：仅当前设备未被信任时二验
        $need2fa = $sitePolicy === 'every_login'
            || $policy === 'every_login'
            || !DeviceController::isTrustedDevice($userId);
        if (!$need2fa) {
            return false;
        }

        // 候选 = 该账号真实拥有的、且与第一因素不同的方式
        $factors = (new TwoFactorController())->getUserFactors($userId);
        unset($factors[$firstFactor]);

        // 一个可用的第二因素都没有就放行，否则用户会被彻底锁在门外
        if (empty($factors)) {
            return false;
        }

        Session::set('2fa_pending', [
            'user_id'      => $userId,
            'username'     => $user['username'],
            'role'         => $user['role'] ?? 'user',
            'redirect_to'  => $redirect_to,
            'temporary'    => $temporary,
            'first_factor' => $firstFactor,
            'action'       => 'login',
            'nonce'        => bin2hex(random_bytes(16)),
            'created_at'   => time(),
        ]);

        return true;
    }

    /**
     * 直接完成登录：写会话、记登录日志、跳转（或返回 JSON）
     *
     * @param array       $extraSession     额外写入 session 的键值（如临时密钥上下文）
     * @param string      $method           本次登录方式，用于活动日志
     * @param string|null $rememberedMethod 交给浏览器记住的方式；走两步验证时传第一因素
     * @param bool        $temporary        「这是公共设备」：只发浏览器会话 cookie，关掉即退出
     */
    public static function finishLogin(array $user, string $redirect_to, bool $temporary = false, bool $json = false, array $extraSession = [], string $method = 'password', ?string $rememberedMethod = null): void
    {
        $userId = (int)$user['id'];

        // 登录成功了，「本次已尝试」的记忆就该清掉
        Session::remove('login_failed_methods');

        // 一个收信地址都没验证（自己的没点确认、家长那条也没确认）：只建立半登录会话，强制去激活页
        if (empty($user['email_verified'])
            && !(Features::guardians() && FamilyHelper::guardianConfirmed($userId))) {
            Session::regenerate();
            Session::set('user_id', $userId);
            Session::set('username', $user['username']);
            Session::markSignedIn($temporary);
            self::touchLogin($userId);
            Response::redirect('/activate');
        }

        $deviceId = DeviceController::ensureDeviceId();

        Session::set('user_id', $userId);
        Session::set('username', $user['username']);
        Session::set('role', $user['role'] ?? 'user');
        // 账号级语言偏好带进会话，I18n::init() 免查库
        $userLocale = (string)($user['locale'] ?? '');
        if (I18n::isValid($userLocale)) {
            Session::set('user_locale', $userLocale);
        } else {
            Session::remove('user_locale');
        }
        // 外观偏好同理：登录后跟着账号走
        $userSkin = (string)($user['skin'] ?? '');
        if ($userSkin !== '' && Skins::exists($userSkin)) {
            Session::set('user_skin', $userSkin);
        } else {
            Session::remove('user_skin');
        }

        // 登录成功事件：审计、同步、风控都挂这里
        Hooks::run('user.login.succeeded', [$userId, (string)$user['username']]);
        Session::remove('2fa_pending');
        foreach ($extraSession as $key => $value) {
            Session::set($key, $value);
        }

        $methodLabels = [
            'password'   => '密码',
            'email_code' => '邮箱验证码',
            'temp_key'   => '临时密钥',
            'biometric'  => '生物识别',
            '2fa'        => '两步验证',
            'social'     => '第三方登录',
        ];
        ActivityLog::record([
            'action'   => 'auth.login',
            'category' => ActivityLog::CAT_AUTH,
            'actor_type' => 'user',
            'actor_id' => $userId,
            'actor_username' => (string)$user['username'],
            'summary'  => '通过「' . ($methodLabels[$method] ?? $method) . '」登录',
        ]);

        // 满 18 岁且账号创建满 6 个月的未成年账号，登录时自动停止监护。
        // 惰性判定，不建定时任务 —— 晚一两天生效没关系
        $autonomy = Features::guardians() ? FamilyHelper::syncDueAutonomy($userId) : null;
        if ($autonomy !== null) {
            ActivityLog::record([
                'action'   => $autonomy === 'auto' ? 'family.child_independent_auto' : 'family.invite_expired',
                'category' => ActivityLog::CAT_FAMILY,
                'actor_type' => 'system',
                'actor_id' => $userId,
                'actor_username' => (string)$user['username'],
                'summary'  => $autonomy === 'auto' ? '成年后自动停止监护' : '成年后家庭邀请自动作废',
            ]);
        }

        // 默认持久登录（30 天滑动、最长 90 天）；勾了「这是公共设备」才降级成临时会话
        Session::markSignedIn($temporary);

        // 先落盘 session，确保跳转后能读到
        session_write_close();

        $db = Database::getInstance();

        // 把「这次用的方式 + 账号真实的用户名/邮箱」交给浏览器存下来：
        // 登录页据此预填账号、决定推荐哪种方式。前端读走就删，只消费一次；
        // 用 cookie 交接而不是直接渲染进登录页 HTML，免得页面被 CDN 缓存时带出别人的账号信息。
        $identity = $db->prepare('SELECT username, email FROM users WHERE id = :id');
        $identity->execute([':id' => $userId]);
        $identity = $identity->fetch() ?: [];
        self::setLoginHandoffCookie($rememberedMethod ?? $method, (string)($identity['username'] ?? ''), (string)($identity['email'] ?? ''));

        self::touchLogin($userId);

        if ($deviceId) {
            $db->prepare('UPDATE user_devices SET last_seen_at = NOW(), device_name = :n WHERE user_id = :u AND device_id = :d')
                ->execute([
                    ':n' => DeviceController::parseDeviceName($_SERVER['HTTP_USER_AGENT'] ?? ''),
                    ':u' => $userId,
                    ':d' => $deviceId,
                ]);
        }

        if ($json) {
            Response::json(['success' => true, 'message' => t('Signed in'), 'redirect' => $redirect_to]);
        }

        Response::redirect($redirect_to);
    }

    /**
     * 记录「这个人刚刚登录了」
     *
     * 所有建立登录会话的路径都必须调它：注册后自动登录、点激活链接、恢复凭证登录
     * 都是直接写 session 的，别只靠 finishLogin 那一处，否则后台会显示「从未登录」。
     */
    public static function touchLogin(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        Database::getInstance()
            ->prepare('UPDATE users SET last_login_at = NOW(), last_login_ip = :ip WHERE id = :id')
            ->execute([':ip' => $_SERVER['REMOTE_ADDR'] ?? '', ':id' => $userId]);
    }

    /**
     * 登录成功时把「方式 + 账号用户名/邮箱」交给浏览器（app.js 读进 localStorage 后立刻删掉）
     *
     * 必须非 HttpOnly：只有页面脚本读得到才谈得上记住。值里不含任何凭据，只是账号显示名与邮箱。
     */
    private static function setLoginHandoffCookie(string $method, string $username, string $email): void
    {
        if ($username === '') {
            return;
        }

        $payload = json_encode(['m' => $method, 'u' => $username, 'e' => $email], JSON_UNESCAPED_UNICODE);
        $value = rtrim(strtr(base64_encode((string)$payload), '+/', '-_'), '=');

        setcookie('nomen_login_ok', $value, [
            'expires'  => time() + 30 * 24 * 3600,
            'path'     => '/',
            'secure'   => true,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    private function verifyTempKey(int $userId, string $password): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, key_hash, expires_at, last_used_at FROM user_temp_keys 
             WHERE user_id = :u AND expires_at > NOW() ORDER BY created_at DESC'
        );
        $stmt->execute([':u' => $userId]);
        $keys = $stmt->fetchAll();

        foreach ($keys as $key) {
            if (password_verify($password, $key['key_hash'])) {
                $stmt = $this->db->prepare(
                    'UPDATE user_temp_keys SET last_used_at = NOW() WHERE id = :id'
                );
                $stmt->execute([':id' => $key['id']]);

                ActivityLog::record([
                    'action'   => 'security.temp_key_used',
                    'category' => ActivityLog::CAT_SECURITY,
                    'actor_type' => 'user',
                    'actor_id' => $userId,
                    'target_type' => 'temp_key',
                    'target_id' => (int)$key['id'],
                    'summary'  => '使用临时密钥通过验证',
                ]);

                // 只有首次使用才发邮件通知
                if (empty($key['last_used_at'])) {
                    $this->sendTempKeyNotification($userId, $key['id']);
                }
                return $key;
            }
        }
        return null;
    }

    private function sendTempKeyNotification(int $userId, int $keyId): void
    {
        $stmt = $this->db->prepare('SELECT id, email, username FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) return;

        $stmt = $this->db->prepare('SELECT label, expires_at FROM user_temp_keys WHERE id = :id');
        $stmt->execute([':id' => $keyId]);
        $key = $stmt->fetch();
        if (!$key) return;

        $locale = I18n::locale();
        $subject = EmailTemplate::subjectPrefix() . I18n::tIn($locale, 'Temp key used');
        $device = DeviceController::parseDeviceName($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '未知';
        $location = GeoIP::getLocation($ip);
        $time = date('Y-m-d H:i:s');

        $htmlBody = EmailTemplate::render([
            'preheader' => I18n::tIn($locale, '你的临时密钥「{:label}」刚刚被使用', ['label' => $key['label']]),
            'badge'     => ['text' => 'Security alert', 'tone' => 'important'],
            'title'     => 'Temp key used',
            'subtitle'  => 'Your {:sitename} account was signed in with a temp key',
            'blocks'    => [
                ['type' => 'paragraph', 'text' => I18n::tIn($locale, '你好 {:name}，你的临时密钥已被使用：', ['name' => $user['username']])],
                ['type' => 'info', 'items' => [
                    ['label' => 'Key label', 'value' => $key['label']],
                    ['label' => 'Device', 'value' => $device],
                    ['label' => 'IP address', 'value' => $ip],
                    ['label' => 'Location', 'value' => $location],
                    ['label' => 'Used at', 'value' => $time],
                    ['label' => 'Expires at', 'value' => $key['expires_at']],
                ]],
                ['type' => 'alert', 'tone' => 'danger', 'text' => 'If this wasn\'t you, change your password and delete that temp key right away.'],
            ],
            'button'    => ['text' => 'Manage temp keys', 'url' => EmailTemplate::url('/settings')],
        ], $locale);

        // 未成年账号没有自己的邮箱，安全提醒发到监护人邮箱
        $contact = FamilyHelper::contactEmail($user);
        if ($contact !== null) {
            Email::send($contact, $subject, $htmlBody);
        }
    }

    /**
     * 发送登录验证码
     */
    public function sendLoginCode(): void
    {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        // 提前读取跳转目标，失败重定向时保留
        $redirect_to = $_POST['redirect_to'] ?? '/dashboard';
        if (!str_starts_with($redirect_to, '/') || str_contains($redirect_to, '://')) {
            $redirect_to = '/dashboard';
        }
        $loginUrl = '/login?redirect_to=' . urlencode($redirect_to);

        if (!$isAjax) {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) {
                Session::flash('error', t('Invalid request, try again'));
                Response::redirect($loginUrl);
            }
        }

        if (!LoginMethods::on('email_code')) {
            $message = '管理员已关闭邮箱验证码登录，请改用其它方式登录';
            if ($isAjax) {
                Response::json(['success' => false, 'message' => $message]);
            }
            Session::flash('error', $message);
            Response::redirect($loginUrl);
        }

        if (!Email::isConfigured()) {
            $message = t('This site has not set up email delivery yet, so email code login is unavailable for now. Please use your password.');
            if ($isAjax) {
                Response::json(['success' => false, 'message' => $message]);
            }
            Session::flash('error', $message);
            Response::redirect($loginUrl);
        }

        // 条件图片验证码 + IP 限流
        if ($this->enforceLoginGuards($loginUrl, $isAjax, null)) {
            return;
        }

        // 标识可以是邮箱，也可以是用户名 —— 未成年账号没有自己的邮箱，
        // 只能用用户名走这条路（验证码会发到监护人邮箱）
        $identifier = trim($_POST['email'] ?? '');
        if ($identifier === '') {
            $message = '请输入邮箱或用户名';
            if ($isAjax) {
                Response::json(['success' => false, 'message' => $message]);
            }
            Session::flash('error', $message);
            Response::redirect($loginUrl);
        }

        $accounts = $this->findAccountsByIdentifier($identifier);

        if (!$accounts) {
            $message = str_contains($identifier, '@') ? '该邮箱未注册' : '这个用户名不存在';
            // 填的是家长邮箱：一个家长邮箱可能带多个孩子，分不清是哪个，得让人填用户名
            if (Features::guardians()
                && str_contains($identifier, '@')
                && FamilyHelper::existingGroupForGuardianEmail($identifier)) {
                $message = '这个邮箱是某个孩子账号的收信地址（家长的邮箱）—— 请改填那个账号的用户名（一个家长邮箱可能带多个孩子）。';
            }
            if ($isAjax) {
                Response::json(['success' => false, 'message' => $message]);
            }
            Session::flash('error', $message);
            Response::redirect($loginUrl);
        }

        // 这个邮箱对应多个账号（一个当主邮箱、一个当备选）→ 让用户先选一个。
        // 验证码是按账号（不是按邮箱）发的，选错了码就对不上。
        $user = $accounts[0];
        if (count($accounts) > 1) {
            $wantedId = (int)($_POST['account_id'] ?? 0);
            $picked   = null;
            foreach ($accounts as $candidate) {
                if ((int)$candidate['id'] === $wantedId) {
                    $picked = $candidate;
                    break;
                }
            }
            if ($picked === null) {
                $this->offerAccountChoice($accounts, $loginUrl, $isAjax);
                return;
            }
            $user = $picked;
        }

        if ($user['status'] !== 1) {
            $message = '该账户已被禁用';
            if ($isAjax) {
                Response::json(['success' => false, 'message' => $message]);
            }
            Session::flash('error', $message);
            Response::redirect($loginUrl);
        }

        // 填**用户名**时，输入里看不出该往哪个邮箱发：账号绑了多个已验证邮箱就问用户，
        // 别默认挑首选（2026-10-15 用户定的）。**填邮箱那条路不用问** —— 填哪个就发哪个。
        // 回传的是行 id 不是地址：这一页是未登录状态，把真实地址列出来等于
        // 给任何人一个「这个账号绑了哪些邮箱」的接口。
        $requestedAddress = str_contains($identifier, '@') ? $identifier : null;
        if ($requestedAddress === null) {
            $addresses = EmailSet::pickableAddresses((int)$user['id']);
            if (count($addresses) > 1) {
                $wantedId = (int)($_POST['send_to_id'] ?? 0);
                $picked   = null;
                foreach ($addresses as $address) {
                    if ((int)$address['id'] === $wantedId) {
                        $picked = $address;
                        break;
                    }
                }
                if ($picked === null) {
                    $this->offerAddressChoice($addresses, $user, $loginUrl, $isAjax);
                    return;
                }
                $requestedAddress = (string)$picked['email'];
            }
        }

        // 频率限制检查（邮箱维度 + IP维度，标准一样分开计）
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // 邮箱维度
        $emailRate = RateLimiter::checkEmailRate($user['id']);
        if (!$emailRate['allowed']) {
            if ($isAjax) {
                Response::json(['success' => false, 'message' => $emailRate['message'], 'retry_after' => $emailRate['retry_after']]);
            }
            Session::flash('error', $emailRate['message']);
            Response::redirect($loginUrl);
        }

        // IP维度（每分钟1封，每小时3封，每天5封）
        $ipChecks = [
            ['key' => "login_code:{$ip}:minute",  'max' => 1,  'window' => 60],
            ['key' => "login_code:{$ip}:hour",    'max' => 3,  'window' => 3600],
            ['key' => "login_code:{$ip}:day",     'max' => 5,  'window' => 86400],
        ];
        foreach ($ipChecks as $check) {
            $result = RateLimiter::check($check['key'], $check['max'], $check['window']);
            if (!$result['allowed']) {
                $labels = ['minute' => '分钟', 'hour' => '小时', 'day' => '天'];
                $period = explode(':', $check['key'])[2];
                $msg = "发送太频繁，{$labels[$period]}内最多 {$check['max']} 封，请 {$result['retry_after']} 秒后重试";
                if ($isAjax) {
                    Response::json(['success' => false, 'message' => $msg, 'retry_after' => $result['retry_after']]);
                }
                Session::flash('error', $msg);
                Response::redirect($loginUrl);
            }
        }

        // 收信地址：上面已经把「填用户名 + 多个邮箱」那种情况问过用户了，
        // 到这里 $requestedAddress 要么是填的那个地址、要么是用户选的那个、要么还是 null
        // （账号只有一个可用地址，或者未成年账号没有自己的地址 → 回退监护人邮箱）
        $contact = EmailSet::resolveRecipient($user, $requestedAddress);
        if ($contact === null) {
            $message = '账号没有可用的收信地址，请联系支持团队';
            if ($isAjax) {
                Response::json(['success' => false, 'message' => $message]);
            }
            Session::flash('error', $message);
            Response::redirect($loginUrl);
        }

        // 生成6位验证码
        $code = str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // 存不下就别发信：让用户收到一封输进去却对不上的验证码，比当场报错更难查
        if (!VerifyCode::issue(VerifyCode::LOGIN, (int)$user['id'], $code)) {
            if ($isAjax) {
                Response::json(['success' => false, 'message' => t('Service temporarily unavailable, try again later')]);
            }
            Session::flash('error', t('Service temporarily unavailable, try again later'));
            Response::redirect($loginUrl);
        }

        // 发送验证码邮件（正文里写明是哪个账号的码，一个家长邮箱可能带多个孩子）
        $locale = I18n::locale();
        $subject = EmailTemplate::subjectPrefix() . I18n::tIn($locale, 'Sign-in code');
        $htmlBody = $this->getLoginCodeEmailHtml($contact, $code, (string)$user['username'], $locale);

        if (Email::send($contact, $subject, $htmlBody)) {
            if ($isAjax) {
                Response::json(['success' => true, 'message' => t('Code sent — check your inbox (valid for 10 minutes)')]);
            }
            Session::flash('success', t('Code sent — check your inbox (valid for 10 minutes)'));
        } else {
            if ($isAjax) {
                Response::json(['success' => false, 'message' => t('Email failed to send, try again later')]);
            }
            Session::flash('error', t('Email failed to send, try again later'));
        }

        Response::redirect($loginUrl);
    }

    /**
     * 使用验证码登录（第一因素：邮箱验证码）
     */
    public function handleLoginWithCode(): void
    {
        [$redirect_to, $loginUrl] = $this->resolveRedirect();

        if (!LoginMethods::on('email_code')) {
            $this->failLogin($loginUrl, false, '管理员已关闭邮箱验证码登录，请改用其它方式登录');
        }

        if (!Email::isConfigured()) {
            Session::flash('error', t('This site has not set up email delivery yet, so email code login is unavailable for now. Please use your password.'));
            Response::redirect($loginUrl);
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Session::flash('error', t('Invalid request, try again'));
            Response::redirect($loginUrl);
        }

        $identifier = trim($_POST['email'] ?? '');
        $code = trim($_POST['code'] ?? '');

        $accounts = $this->findAccountsByIdentifier($identifier);

        foreach ($accounts as $candidate) {
            if ($this->enforceLoginGuards($loginUrl, false, (int)$candidate['id'])) {
                return;
            }
        }
        if (!$accounts && $this->enforceLoginGuards($loginUrl, false, null)) {
            return;
        }

        if ($identifier === '' || !$code) {
            $this->failLogin($loginUrl, false, '请输入邮箱（或用户名）和验证码');
        }

        if (!$accounts) {
            $this->failLogin($loginUrl, false, '邮箱或验证码错误', 'email_code');
        }

        // 验证码是按**账号**（不是按邮箱）发的：命中多个账号时必须跟着选中的那个走，
        // 否则「给 A 发的码」会被拿去验 B。account_id 由登录页的选择框填。
        $user = null;
        if (count($accounts) === 1) {
            $user = $accounts[0];
        } else {
            $wantedId = (int)($_POST['account_id'] ?? 0);
            foreach ($accounts as $candidate) {
                if ((int)$candidate['id'] === $wantedId) {
                    $user = $candidate;
                    break;
                }
            }
            if ($user === null) {
                $this->failLogin($loginUrl, false, '这个邮箱对应多个账号，请勾选要登录的账号后再输入验证码', 'email_code');
            }
        }

        if ((int)$user['status'] !== 1) {
            $this->failLogin($loginUrl, false, '该账户已被禁用');
        }

        // 这几个分支里的 failLogin 自己会 exit，不用再 return
        switch (VerifyCode::check(VerifyCode::LOGIN, (int)$user['id'], $code)) {
            case 'unavailable':
                // 不是用户输错了，所以不记「这个方式试过」—— 那会让登录页把主区让给别的方式
                $this->failLogin($loginUrl, false, '服务暂时不可用，请稍后重试');
                break;
            case 'expired':
                $this->failLogin($loginUrl, false, '验证码已过期，请重新发送', 'email_code');
                break;
            case 'mismatch':
                $this->failLogin($loginUrl, false, '验证码错误', 'email_code');
                break;
        }

        // 用后即焚
        VerifyCode::clear(VerifyCode::LOGIN, (int)$user['id']);

        Session::regenerate();
        if (self::beginSecondFactorIfNeeded($user, 'email_code', $redirect_to, !empty($_POST['temporary']))) {
            Response::redirect('/2fa/verify');
        }

        self::finishLogin($user, $redirect_to, !empty($_POST['temporary']), false, [], 'email_code');
    }

    /**
     * 临时密钥登录页面
     */
    public function fastPass(): void
    {
        if (Session::get('user_id')) {
            $redirect = Session::get('post_login_redirect', '/dashboard');
            Session::remove('post_login_redirect');
            Response::redirect($redirect);
        }

        if (!LoginMethods::on('temp_key')) {
            Session::flash('error', '管理员已关闭临时密钥登录，请改用其它方式登录');
            Response::redirect('/login');
        }

        $error = Session::getFlash('error');
        $success = Session::getFlash('success');
        $redirect_to = $_GET['redirect_to'] ?? '/dashboard';

        if (!str_starts_with($redirect_to, '/') || str_contains($redirect_to, '://')) {
            $redirect_to = '/dashboard';
        }

        Response::view('fast-pass', compact('error', 'success', 'redirect_to'));
    }

    /**
     * 使用临时密钥登录（应急通道，不参与两步验证策略）
     */
    public function handleLoginWithTempKey(): void
    {
        [$redirect_to, $loginUrl] = $this->resolveRedirect();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Session::flash('error', t('Invalid request, try again'));
            Response::redirect($loginUrl);
        }

        if (!LoginMethods::on('temp_key')) {
            $this->failLogin($loginUrl, false, '管理员已关闭临时密钥登录，请改用其它方式登录');
        }

        $username = trim($_POST['username'] ?? '');
        $tempKey = $_POST['temp_key'] ?? '';

        $accounts = $this->findAccountsByIdentifier($username);

        foreach ($accounts as $candidate) {
            if ($this->enforceLoginGuards($loginUrl, false, (int)$candidate['id'])) {
                return;
            }
        }
        if (!$accounts && $this->enforceLoginGuards($loginUrl, false, null)) {
            return;
        }

        if (!$username || !$tempKey) {
            $this->failLogin($loginUrl, false, '请输入用户名和临时密钥');
        }

        if (!$accounts) {
            $this->failLogin($loginUrl, false, '用户名或临时密钥错误', 'temp_key');
        }

        // 临时密钥是按账号签发的，邮箱命中多个账号时逐个试
        $user = null;
        $tempKeyData = null;
        foreach ($accounts as $candidate) {
            $data = $this->verifyTempKey((int)$candidate['id'], $tempKey);
            if ($data) {
                $user = $candidate;
                $tempKeyData = $data;
                break;
            }
        }
        if ($user === null) {
            $this->failLogin($loginUrl, false, '用户名或临时密钥错误', 'temp_key');
        }

        if ((int)$user['status'] !== 1) {
            $this->failLogin($loginUrl, false, '该账户已被禁用');
        }

        Session::regenerate();
        self::finishLogin($user, $redirect_to, !empty($_POST['temporary']), false, [
            'temp_key_login'      => true,
            'temp_key_id'         => (int)$tempKeyData['id'],
            'temp_key_expires_at' => $tempKeyData['expires_at'],
        ], 'temp_key');
    }

    /**
     * 登录验证码邮件 HTML 模板
     */
    private function getLoginCodeEmailHtml(string $contact, string $code, string $username = '', string $locale = 'zh-hans'): string
    {
        $items = [];
        if ($username !== '') {
            $items[] = ['label' => '账号', 'value' => $username];
        }
        $items[] = ['label' => 'Contact address', 'value' => $contact];

        return EmailTemplate::render([
            'preheader' => I18n::tIn($locale, '你的登录验证码是 {:code}，10 分钟内有效', ['code' => $code]),
            'badge'     => ['text' => 'Security verification', 'tone' => 'general'],
            'title'     => 'Sign-in code',
            'subtitle'  => $username !== '' ? I18n::tIn($locale, '账号 {:name} 的登录验证码', ['name' => $username]) : I18n::tIn($locale, '使用验证码登录你的 {:sitename} 账户'),
            'blocks'    => [
                ['type' => 'info', 'items' => $items],
                ['type' => 'code', 'label' => 'Code', 'value' => $code],
                ['type' => 'alert', 'tone' => 'warning', 'text' => 'The code is valid for 10 minutes. If this wasn\'t you, ignore this email.'],
            ],
        ], $locale);
    }

    /**
     * 注册页面
     */
    public function register(): void
    {
        if (Session::get('user_id')) {
            Response::redirect('/dashboard');
        }

        // 密码注册被关且第三方也没有（或没启用）：注册页就是死路，回登录页提示
        if (!LoginMethods::on('password') && !LoginMethods::on('oauth')) {
            Session::flash('error', '本站没有可用的注册方式，请联系管理员');
            Response::redirect('/login');
        }

        $error = Session::getFlash('error');
        $old = Session::getFlash('old', []);
        $passwordRegistration = LoginMethods::on('password');

        // 第三步按来访 IP 预填国家/地区，只是默认值（取不到就空着，用户随时能改）
        $detectedCountry = '';
        if (Features::country() && empty($old['country'])) {
            $code = GeoIP::getCountryCode($_SERVER['REMOTE_ADDR'] ?? '');
            if ($code !== null && isset(FamilyHelper::countries()[$code])) {
                $detectedCountry = $code;
            }
        }

        Response::view('register', compact('error', 'old', 'detectedCountry', 'passwordRegistration'));
    }

    /**
     * 处理注册
     */
    public function handleRegister(): void
    {
        if (!LoginMethods::on('password')) {
            Session::flash('error', '管理员已关闭账号密码注册，请使用第三方登录');
            Response::redirect('/register');
        }

        // 回显数据走白名单，绝不整体闪 $_POST —— 明文密码不能进 session / 页面
        $old = [
            'username' => trim($_POST['username'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'country' => Features::country() ? trim($_POST['country'] ?? '') : '',
            'birth_date' => trim($_POST['birth_date'] ?? ''),
            'gender' => trim($_POST['gender'] ?? ''),
            'parent_email' => Features::guardians() ? trim($_POST['parent_email'] ?? '') : '',
            // 密码不回显，所以失败后必须回到第 2 步重新输入，不能再停在第 5 步提交
            'current_step' => min((int)($_POST['current_step'] ?? 1), 2),
            'agree_terms' => $_POST['agree_terms'] ?? '',
            'agree_service' => $_POST['agree_service'] ?? '',
        ];

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Session::flash('error', t('Invalid request, try again'));
            Session::flash('old', $old);
            Response::redirect('/register');
        }

        // 验证图片验证码（GD 没装时这一步整体跳过）
        if (Captcha::available() && !Captcha::verify($_POST['captcha'] ?? null)) {
            Session::flash('error', t('Wrong code'));
            Session::flash('old', $old);
            Response::redirect('/register');
        }

        // 检查是否同意条款
        if (empty($_POST['agree_terms']) || empty($_POST['agree_service'])) {
            Session::flash('error', t('Please agree to the Privacy Policy and Terms of Service'));
            Session::flash('old', $old);
            Response::redirect('/register');
        }

        // 每小时 3 次注册限流（基于 IP）
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rateCheck = RateLimiter::check("auth:register:{$ip}", 3, 3600);
        if (!$rateCheck['allowed']) {
            Session::flash('error', t('Too many sign-ups, try again later'));
            Session::flash('old', $old);
            Response::redirect('/register');
        }

        $username = $old['username'];
        $email = $old['email'];
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';
        $country = $old['country'];
        $birth_date = $old['birth_date'];
        $gender = $old['gender'];
        $parent_email = $old['parent_email'];

        // 年龄一律在服务端算。前端只拿 consent-age.php 那份表决定展示哪一段
        $age = FamilyHelper::ageOf($birth_date);
        $is_minor = Features::guardians()
            && $age !== null
            && $age < FamilyHelper::consentAgeFor($country);

        // 验证
        if (!$username || !$password) {
            Session::flash('error', t(!$username ? '请填写用户名' : '请填写密码'));
            Session::flash('old', $old);
            Response::redirect('/register');
        }

        if (Features::country() && (!$country || !preg_match('/^[A-Z]{2}$/', $country))) {
            Session::flash('error', t('Pick a country/region'));
            Session::flash('old', $old);
            Response::redirect('/register');
        }

        if (!$birth_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
            Session::flash('error', t('Pick a valid date of birth'));
            Session::flash('old', $old);
            Response::redirect('/register');
        }
        $bd = explode('-', $birth_date);
        if (!checkdate((int)$bd[1], (int)$bd[2], (int)$bd[0]) || (int)$bd[0] < 1920 || (int)$bd[0] > (int)date('Y')) {
            Session::flash('error', t('Pick a valid date of birth'));
            Session::flash('old', $old);
            Response::redirect('/register');
        }

        $validGenders = ['female', 'male', 'non-binary', 'prefer-not-to-say'];
        if (!$gender || !in_array($gender, $validGenders)) {
            Session::flash('error', t('Pick a gender'));
            Session::flash('old', $old);
            Response::redirect('/register');
        }

        if (!preg_match('/^[a-z0-9_]{3,32}$/', $username)) {
            Session::flash('error', t('Username: lowercase letters, digits, underscore; 3-32 characters'));
            Session::flash('old', $old);
            Response::redirect('/register');
        }

        // 邮箱：未成年可以先填自己的（绝大多数人都这么填，那就和普通账号一模一样）；
        // 没有自己的邮箱才填家长邮箱 —— 那份地址只是"能收到验证邮件的地址"，
        // 存在家长那条成员行上，不占 users.email，所以一个家长邮箱能带多个孩子
        $email = ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null;

        if ($email === null && !$is_minor) {
            Session::flash('error', t('Invalid email format'));
            Session::flash('old', $old);
            Response::redirect('/register');
        }

        if ($email === null && (!$parent_email || !filter_var($parent_email, FILTER_VALIDATE_EMAIL))) {
            Session::flash('error', t('Enter your own email; if you don\'t have one, enter a parent\'s (it receives the verification)'));
            Session::flash('old', $old);
            Response::redirect('/register');
        }

        $useGuardianEmail = Features::guardians() && ($email === null);

        // 黑名单域名一律不收：自己的邮箱，以及（没有自己的邮箱时）家长那条
        if (EmailPolicy::isBlocked((string)$email)
            || ($useGuardianEmail && EmailPolicy::isBlocked((string)$parent_email))) {
            Session::flash('error', t(EmailPolicy::REJECT_MESSAGE));
            Session::flash('old', $old);
            Response::redirect('/register');
        }

        if (strlen($password) < 8 || !preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            Session::flash('error', t('At least 8 characters with letters and digits'));
            Session::flash('old', $old);
            Response::redirect('/register');
        }

        if ($password !== $password_confirm) {
            Session::flash('error', t('The passwords you entered don\'t match'));
            Session::flash('old', $old);
            Response::redirect('/register');
        }

        // 用户名和邮箱分开查：合并成一句 OR 就没法区分是哪个命中的，更没法治「邮箱被一个没验证过的账号占着」
        $stmt = $this->db->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $usernameTaken = (bool)$stmt->fetch();

        // 未成年人没有自己的邮箱（$email 为空），回收支线只对成年人有意义
        $emailOwner = null;
        if ($email !== null) {
            $stmt = $this->db->prepare('SELECT id, email_verified, role FROM users WHERE email = :e LIMIT 1');
            $stmt->execute([':e' => $email]);
            $emailOwner = $stmt->fetch() ?: null;
        }

        // 邮箱被未验证的账号占着（且不是管理员）：不把人堵死，走验证码支线回收
        if (!$usernameTaken && $emailOwner
            && empty($emailOwner['email_verified'])
            && ($emailOwner['role'] ?? 'user') !== 'admin') {
            $this->startRegisterClaim($username, $email, $password, $country, $birth_date, $gender, $is_minor, $old);
            return;
        }

        if ($usernameTaken || $emailOwner) {
            // 这句刻意不区分是用户名还是邮箱命中的，否则等于对外提供一个邮箱枚举接口
            $hint = '如果这个邮箱确实是你的，可以到登录页用「邮箱验证码」登录已有账户。';
            Session::flash('error', t('That username or email is already registered.') . $hint);
            Session::flash('old', $old);
            Response::redirect('/register');
        }

        // 创建用户
        $config = require __DIR__ . '/../config/app.php';
        $pepper = $config['pepper'] ?? '';
        $passwordHash = password_hash($password . $pepper, PASSWORD_ARGON2ID);
            $stmt = $this->db->prepare(
                'INSERT INTO users (unid, username, email, password_hash, display_name, email_verified, country, birth_date, gender, locale) VALUES (:unid, :u, :e, :p, :d, 0, :c, :bd, :g, :loc)'
        );
        $stmt->execute([
            ':unid' => Unid::generate(),
            ':u' => $username,
            ':e' => $email,
            ':p' => $passwordHash,
            ':d' => $username,
            ':c' => $country !== '' ? $country : null,
            ':bd' => $birth_date,
            ':g' => $gender,
            ':loc' => I18n::locale(),
        ]);

        $userId = (int)$this->db->lastInsertId();

        // 注册只写了 users.email，把它登记成账号的第一个（也是首选）邮箱
        EmailSet::syncPrimaryFromUsers($userId);

        Hooks::run('user.registered', [$userId, $username, $email]);

        // 用家长邮箱收信的孩子：建一条"邀请中的家庭"，等家长用自己的账号接受邀请。
        // 注意这里不改任何权限 —— 孩子注册完就是普通账号，能正常用
        if ($useGuardianEmail) {
            $groupId = FamilyHelper::createUnclaimedGroup($parent_email, 'other', $userId);
            ActivityLog::record([
                'action'   => 'family.group_created',
                'category' => ActivityLog::CAT_FAMILY,
                'actor_type' => 'user',
                'actor_id' => $userId,
                'actor_username' => $username,
                'target_type' => 'family_group',
                'target_id' => $groupId,
                'summary'  => '用家长邮箱注册，创建家庭并邀请家长',
            ]);
        }

        // 注册即快照初始信息；明文密码与 hash 绝不能进日志
        $changes = [
            'username'     => ['old' => null, 'new' => $username],
            'display_name' => ['old' => null, 'new' => $username],
            'birth_date'   => ['old' => null, 'new' => $birth_date],
            'gender'       => ['old' => null, 'new' => $gender],
            'email_verified' => ['old' => null, 'new' => 0],
        ];
        if ($country !== '') {
            $changes['country'] = ['old' => null, 'new' => $country];
        }
        if ($email !== null) {
            $changes['email'] = ['old' => null, 'new' => $email];
        }
        if ($useGuardianEmail) {
            $changes['guardian_email'] = ['old' => null, 'new' => FamilyHelper::normalizeEmail($parent_email)];
        }

        ActivityLog::record([
            'action'   => 'auth.register',
            'category' => ActivityLog::CAT_AUTH,
            'actor_type' => 'user',
            'actor_id' => $userId,
            'actor_username' => $username,
            'target_type' => 'user',
            'target_id' => $userId,
            'summary'  => '注册账户 ' . $username,
            'changes'  => $changes,
        ]);

        // 登录但不完全激活。用家长邮箱收信的账号，要等家长点确认邮件后才能开始使用
        Session::regenerate();
        Session::set('user_id', $userId);
        Session::set('username', $username);
        Session::set('role', 'user');
        Session::markSignedIn(false);
        self::touchLogin($userId);

        if ($useGuardianEmail) {
            Session::flash('success', t('Account created. Press Send verification email below — the mail goes to {:email}. The account activates once a parent confirms.', ['email' => RecoveryHelper::maskEmail($parent_email)]));
            Response::redirect('/activate');
        }

        Response::redirect('/activate');
    }

    // ========== 注册支线：回收被未验证账号占用的邮箱 ==========

    /**
     * 邮箱被一个未验证的账号占着：把这次的注册资料暂存起来、发一封验证码邮件，跳去验证页
     *
     * 资料暂存在服务端而不是 session —— 用户可能在另一个浏览器、或隔一会儿才回来输验证码。
     */
    private function startRegisterClaim(string $username, string $email, string $password, string $country, string $birth_date, string $gender, bool $is_under_16, array $old): void
    {
        $verifyUrl = '/register/verify?e=' . urlencode($email);

        $limited = $this->enforceClaimSendLimits($email, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if ($limited) {
            Session::flash('error', $limited);
            if ($this->findPendingRegistration($email)) {
                Response::redirect($verifyUrl);
            }
            // 没有可用的待注册行时，验证页会把用户弹回注册页，所以错误提示得带回去
            Session::flash('old', $old);
            Response::redirect('/register');
        }

        $config = require __DIR__ . '/../config/app.php';
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $passwordHash = password_hash($password . ($config['pepper'] ?? ''), PASSWORD_ARGON2ID);

        // 过期的待注册行没人清理（这里没有定时任务），写入前顺手扫掉
        $this->db->prepare('DELETE FROM pending_registrations WHERE expires_at < NOW()')->execute();
        $this->db->prepare('DELETE FROM pending_registrations WHERE email = :e')->execute([':e' => $email]);
        $stmt = $this->db->prepare(
            'INSERT INTO pending_registrations
                (email, username, password_hash, country, birth_date, gender, is_under_16, code_hash, attempts, last_sent_at, expires_at, ip_address, user_agent)
             VALUES (:e, :u, :p, :c, :bd, :g, :kid, :ch, 0, NOW(), :exp, :ip, :ua)'
        );
        $stmt->execute([
            ':e'   => $email,
            ':u'   => $username,
            ':p'   => $passwordHash,
            ':c'   => $country !== '' ? $country : null,
            ':bd'  => $birth_date,
            ':g'   => $gender,
            ':kid' => $is_under_16 ? 1 : 0,
            ':ch'  => hash('sha512', $code),
            ':exp' => date('Y-m-d H:i:s', time() + self::CLAIM_TTL),
            ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua'  => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500, 'UTF-8'),
        ]);

        $registerLocale = I18n::locale();
        if (!Email::send($email, EmailTemplate::subjectPrefix() . I18n::tIn($registerLocale, $is_under_16 ? 'Parental consent confirmation' : 'Email verification'), $this->getRegisterClaimEmailHtml($email, $code, $is_under_16, $country, $gender, $registerLocale))) {
            Session::flash('error', t('The verification email failed — press Resend on the page'));
        }

        Response::redirect($verifyUrl);
    }

    /**
     * 验证码页面
     */
    public function showRegisterVerify(): void
    {
        $email = trim((string)($_GET['e'] ?? ''));
        $row = $this->findPendingRegistration($email);
        if (!$row) {
            Session::flash('error', t('This pending registration expired — please register again'));
            Response::redirect('/register');
        }

        $error = Session::getFlash('error');
        $success = Session::getFlash('success');
        $maskedEmail = RecoveryHelper::maskEmail($email);
        $isMinor = Features::guardians() && !empty($row['is_under_16']);
        // 冷却没走完就在页面上显示倒计时，别等用户点了才被拒
        $resendAfter = max(0, self::CLAIM_RESEND_COOLDOWN - (time() - (int)strtotime((string)$row['last_sent_at'])));

        Response::view('register-verify', compact('email', 'maskedEmail', 'isMinor', 'error', 'success', 'resendAfter'));
    }

    /**
     * 重新发送验证码
     */
    public function resendRegisterVerifyCode(): void
    {
        $email = trim((string)($_POST['email'] ?? ''));
        $back = '/register/verify?e=' . urlencode($email);

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Session::flash('error', t('Invalid request, try again'));
            Response::redirect($back);
        }

        $row = $this->findPendingRegistration($email);
        if (!$row) {
            Session::flash('error', t('This pending registration expired — please register again'));
            Response::redirect('/register');
        }

        $wait = self::CLAIM_RESEND_COOLDOWN - (time() - (int)strtotime((string)$row['last_sent_at']));
        if ($wait > 0) {
            Session::flash('error', t('One was just sent — wait {:wait}s (the code in it is still valid)', ['wait' => $wait]));
            Response::redirect($back);
        }

        $limited = $this->enforceClaimSendLimits($email, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if ($limited) {
            Session::flash('error', $limited);
            Response::redirect($back);
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->db->prepare('UPDATE pending_registrations SET code_hash = :ch, attempts = 0, last_sent_at = NOW(), expires_at = :exp WHERE id = :id')
            ->execute([
                ':ch'  => hash('sha512', $code),
                ':exp' => date('Y-m-d H:i:s', time() + self::CLAIM_TTL),
                ':id'  => $row['id'],
            ]);

        $is_under_16 = Features::guardians() && !empty($row['is_under_16']);
        $registerLocale = I18n::locale();
        if (Email::send($email, EmailTemplate::subjectPrefix() . I18n::tIn($registerLocale, $is_under_16 ? 'Parental consent confirmation' : 'Email verification'), $this->getRegisterClaimEmailHtml($email, $code, $is_under_16, (string)$row['country'], (string)$row['gender'], $registerLocale))) {
            Session::flash('success', t('Code resent — check your inbox (valid for 10 minutes)'));
        } else {
            Session::flash('error', t('Email failed to send, try again later'));
        }

        Response::redirect($back);
    }

    /**
     * 校验验证码：通过就删掉原来那个未验证账号，并用暂存的资料建一个已验证的新账号
     */
    public function handleRegisterVerify(): void
    {
        $email = trim((string)($_POST['email'] ?? ''));
        $code = trim((string)($_POST['code'] ?? ''));
        $back = '/register/verify?e=' . urlencode($email);

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Session::flash('error', t('Invalid request, try again'));
            Response::redirect($back);
        }

        $row = $this->findPendingRegistration($email);
        if (!$row) {
            Session::flash('error', t('This pending registration expired — please register again'));
            Response::redirect('/register');
        }

        if ((int)$row['attempts'] >= self::CLAIM_MAX_ATTEMPTS) {
            $this->db->prepare('DELETE FROM pending_registrations WHERE id = :id')->execute([':id' => $row['id']]);
            Session::flash('error', t('Too many wrong codes — press Resend for a new one'));
            Response::redirect($back);
        }

        if ($code === '' || !hash_equals((string)$row['code_hash'], hash('sha512', $code))) {
            $this->db->prepare('UPDATE pending_registrations SET attempts = attempts + 1 WHERE id = :id')->execute([':id' => $row['id']]);
            Session::flash('error', t('Wrong code — try again'));
            Response::redirect($back);
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $verifyRate = RateLimiter::check("register_claim:verify:{$ip}", 20, 3600);
        if (!$verifyRate['allowed']) {
            Session::flash('error', t('Too many verification attempts — retry in {:n}s', ['n' => $verifyRate['retry_after']]));
            Response::redirect($back);
        }

        $this->db->beginTransaction();
        try {
            // 锁住这个邮箱对应的行：这中间原主人可能自己把邮箱验证掉了
            $stmt = $this->db->prepare(
                'SELECT id, username, email_verified, role FROM users WHERE email = :e'
                . Database::dialectFor($this->db)->forUpdate()
            );
            $stmt->execute([':e' => $email]);
            $owner = $stmt->fetch();

            // 已验证的账号（或管理员）绝不动：那是真主人在用的账号，删了等于替他注销
            if ($owner && (!empty($owner['email_verified']) || ($owner['role'] ?? 'user') === 'admin')) {
                $this->db->rollBack();
                Session::flash('error', t('This address is already verified — sign in to the existing account with an email code.'));
                Response::redirect('/register');
            }

            $stmt = $this->db->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
            $stmt->execute([':u' => $row['username']]);
            if ($stmt->fetch()) {
                $this->db->rollBack();
                Session::flash('old', [
                    'username'      => (string)$row['username'],
                    'email'         => $email,
                    'country'       => (string)$row['country'],
                    'birth_date'    => (string)$row['birth_date'],
                    'gender'        => (string)$row['gender'],
                    'parent_email'  => !empty($row['is_under_16']) ? $email : '',
                    'current_step'  => 2,
                ]);
                Session::flash('error', t('The username “{:name}” was just taken — go back and pick another.', ['name' => $row['username']]));
                Response::redirect('/register');
            }

            $oldId = 0;
            $snapshot = [];
            if ($owner) {
                $oldId = (int)$owner['id'];
                $stmt = $this->db->prepare('SELECT username, email, country, birth_date, gender FROM users WHERE id = :id');
                $stmt->execute([':id' => $oldId]);
                $snapshot = $stmt->fetch() ?: [];

                // 先删子表再删主表：这几张表没有指向 users 的外键，不会跟着级联走
                foreach (self::ORPHAN_TABLES as $table) {
                    $this->db->prepare("DELETE FROM {$table} WHERE user_id = :id")->execute([':id' => $oldId]);
                }

                // 必须赶在建新账号之前删掉：users.email 是唯一索引，两行同时占着同一个邮箱会直接冲突。
                // email_verified = 0 与 role 是最后一道闸，锁内刚查过，这里再挡一次。
                $stmt = $this->db->prepare('DELETE FROM users WHERE id = :id AND email_verified = 0 AND role <> :admin');
                $stmt->execute([':id' => $oldId, ':admin' => 'admin']);
                if ($stmt->rowCount() === 0) {
                    $this->db->rollBack();
                    Session::flash('error', t('This address\'s status just changed — please submit the registration again.'));
                    Response::redirect('/register');
                }
            }

            $stmt = $this->db->prepare(
                'INSERT INTO users (unid, username, email, password_hash, display_name, email_verified, country, birth_date, gender, locale) VALUES (:unid, :u, :e, :p, :d, 1, :c, :bd, :g, :loc)'
            );
            $stmt->execute([
                ':unid' => Unid::generate(),
                ':u'  => $row['username'],
                ':e'  => $email,
                ':p'  => $row['password_hash'],
                ':d'  => $row['username'],
                ':c'  => !empty($row['country']) ? $row['country'] : null,
                ':bd' => $row['birth_date'],
                ':g'  => $row['gender'],
                ':loc' => I18n::locale(),
            ]);
            $userId = (int)$this->db->lastInsertId();

            EmailSet::syncPrimaryFromUsers($userId);

            Hooks::run('user.registered', [$userId, (string)$row['username'], $email]);

            ActivityLog::record([
                'action'   => 'auth.register',
                'category' => ActivityLog::CAT_AUTH,
                'actor_type' => 'user',
                'actor_id' => $userId,
                'actor_username' => (string)$row['username'],
                'target_type' => 'user',
                'target_id' => $userId,
                'summary'  => '注册账户 ' . $row['username'] . '（回收未验证的占用邮箱）',
                'changes'  => array_filter([
                    'username'     => ['old' => null, 'new' => $row['username']],
                    'email'        => ['old' => null, 'new' => $email],
                    'display_name' => ['old' => null, 'new' => $row['username']],
                    'country'      => !empty($row['country']) ? ['old' => null, 'new' => $row['country']] : null,
                    'birth_date'   => ['old' => null, 'new' => $row['birth_date']],
                    'gender'       => ['old' => null, 'new' => $row['gender']],
                    'email_verified' => ['old' => null, 'new' => 1],
                ]),
            ]);

            if ($oldId > 0) {
                ActivityLog::record([
                    'action'   => 'account.delete',
                    'category' => ActivityLog::CAT_ACCOUNT,
                    'actor_type' => 'user',
                    'actor_id' => $userId,
                    'actor_username' => (string)$row['username'],
                    'target_type' => 'user',
                    'target_id' => $oldId,
                    'target_name' => (string)($snapshot['username'] ?? ''),
                    'summary'  => '回收未验证邮箱时清掉原占用账号 ' . ($snapshot['username'] ?? ''),
                    'changes'  => [
                        'username'   => ['old' => $snapshot['username'] ?? null, 'new' => null],
                        'email'      => ['old' => $snapshot['email'] ?? null, 'new' => null],
                        'country'    => ['old' => $snapshot['country'] ?? null, 'new' => null],
                        'birth_date' => ['old' => $snapshot['birth_date'] ?? null, 'new' => null],
                        'gender'     => ['old' => $snapshot['gender'] ?? null, 'new' => null],
                    ],
                ]);
            }

            ActivityLog::record([
                'action'   => 'account.reclaim',
                'category' => ActivityLog::CAT_ACCOUNT,
                'actor_type' => 'user',
                'actor_id' => $userId,
                'actor_username' => (string)$row['username'],
                'target_type' => 'user',
                'target_id' => $userId,
                'summary'  => '验证邮箱后回收占用账号 ' . $email,
                'changes'  => ['email' => ['old' => null, 'new' => $email]],
            ]);

            $this->db->prepare('DELETE FROM pending_registrations WHERE id = :id')->execute([':id' => $row['id']]);
            $this->db->commit();
        } catch (\Throwable $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[RegisterClaim] ' . $ex->getMessage());
            Session::flash('error', t('Sign-up failed, try again later'));
            Response::redirect($back);
        }

        self::finishLogin([
            'id'               => $userId,
            'username'         => (string)$row['username'],
            'email'            => $email,
            'display_name'     => (string)$row['username'],
            'avatar'           => null,
            'status'           => 1,
            'role'             => 'user',
            'email_verified'   => 1,
            'two_factor_policy' => 'new_device',
        ], '/dashboard', false);
    }

    /**
     * 取一条还没过期的待注册记录
     */
    private function findPendingRegistration(string $email): ?array
    {
        if ($email === '') {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM pending_registrations WHERE email = :e AND expires_at > NOW() LIMIT 1');
        $stmt->execute([':e' => $email]);
        return $stmt->fetch() ?: null;
    }

    /**
     * 发验证码的频率限制：按邮箱和按 IP 各一组（Redis 不可用时 RateLimiter 是放行的，
     * 所以这里不是唯一防线，「重新发送」那条路上还有行内 60 秒冷却）。
     */
    private function enforceClaimSendLimits(string $email, string $ip): ?string
    {
        $checks = [
            ["register_claim:mail:{$email}:minute", 1, 60],
            ["register_claim:mail:{$email}:hour",   3, 3600],
            ["register_claim:mail:{$email}:day",    5, 86400],
            ["register_claim:ip:{$ip}:hour",        5, 3600],
        ];
        foreach ($checks as [$key, $max, $window]) {
            $result = RateLimiter::check($key, $max, $window);
            if (!$result['allowed']) {
                return '验证码发送太频繁，请 ' . $result['retry_after'] . ' 秒后重试';
            }
        }
        return null;
    }

    /**
     * 邮箱回收验证码邮件（未成年走「家长同意」那套措辞）
     */
    private function getRegisterClaimEmailHtml(string $email, string $code, bool $is_under_16, string $country, string $gender, string $locale = 'zh-hans'): string
    {
        $replaceNote = I18n::tIn($locale, '这个邮箱上还有一个没完成验证的账户。你完成验证后，系统会清掉那个账户，并用你这次填写的信息注册一个新账户。');

        if ($is_under_16) {
            $pronoun     = FamilyHelper::genderPronoun($gender, $locale);
            $genderLabel = FamilyHelper::genderLabel($gender, $locale);
            $countryName = FamilyHelper::countryName($country);

            $blocks = [
                    ['type' => 'paragraph', 'text' => '你好，'],
                    ['type' => 'paragraph', 'text' => I18n::tIn($locale, '你的孩子正在注册 {:sitename} —— 一个青少年可以在这里安全地探索和创造的平台。{:pronoun}在注册时填写的国家 / 地区是{:country}，性别是{:gender}。因为还没到本地区可以独立使用 {:sitename} 的年龄，我们需要你的同意。', ['pronoun' => $pronoun, 'country' => $countryName, 'gender' => $genderLabel])],
                    ['type' => 'info', 'items' => [
                        ['label' => 'Parent\'s email', 'value' => $email],
                        ['label' => 'Country / Region', 'value' => $countryName],
                        ['label' => 'Gender', 'value' => $genderLabel],
                    ]],
                ];

            return EmailTemplate::render([
                'preheader' => I18n::tIn($locale, '你的孩子正在注册 {:sitename} 账户，需要你的同意'),
                'badge'     => ['text' => 'Parental consent', 'tone' => 'warning'],
                'title'     => 'Parental consent confirmation',
                'subtitle'  => 'Your child is creating a {:sitename} account',
                'blocks'    => array_merge(
                    $blocks,
                    FamilyHelper::consentAgeEmailBlocks(FamilyHelper::consentAgeInfo($country), $locale),
                    [
                        ['type' => 'code', 'label' => 'Parental consent code', 'value' => $code],
                        ['type' => 'paragraph', 'muted' => true, 'text' => I18n::tIn($locale, '在注册页输入这个验证码，即表示你同意你的孩子使用 {:sitename}。{:note}', ['note' => $replaceNote])],
                        ['type' => 'alert', 'tone' => 'warning', 'text' => I18n::tIn($locale, '这个验证码 10 分钟内有效。如果不是你或你的孩子操作，请忽略此邮件。')],
                    ],
                    EmailTemplate::privacyUrl() !== '' ? [
                        ['type' => 'divider'],
                        ['type' => 'paragraph', 'muted' => true, 'text' => 'You can read our full privacy policy: ' . EmailTemplate::privacyUrl()],
                    ] : []
                ),
            ], $locale);
        }

        return EmailTemplate::render([
            'preheader' => I18n::tIn($locale, '你的注册验证码是 {:code}，10 分钟内有效', ['code' => $code]),
            'badge'     => ['text' => 'Email verification', 'tone' => 'general'],
            'title'     => 'Finish email verification',
            'subtitle'  => 'Registration completes once verified',
            'blocks'    => [
                ['type' => 'info', 'items' => [['label' => 'Pending email', 'value' => $email]]],
                ['type' => 'code', 'label' => 'Code', 'value' => $code],
                ['type' => 'paragraph', 'muted' => true, 'text' => $replaceNote],
                ['type' => 'alert', 'tone' => 'warning', 'text' => I18n::tIn($locale, '这个验证码 10 分钟内有效。如果不是你本人操作，请忽略此邮件。')],
            ],
        ], $locale);
    }

    /**
     * 检查用户名可用性 (AJAX)
     */
    public function checkUsername(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $username = trim($_GET['username'] ?? '');
        if (!$username) {
            Response::json(['available' => false, 'message' => t('Enter a username')]);
            return;
        }

        if (!preg_match('/^[a-z0-9_]{3,32}$/', $username)) {
            Response::json(['available' => false, 'message' => t('Username: lowercase letters, digits, underscore; 3-32 characters')]);
            return;
        }

        // 保留用户名
        $reserved = ['admin', 'root', 'system', 'caellab', 'support', 'test', 'null', 'undefined'];
        if (in_array(strtolower($username), $reserved)) {
            Response::json(['available' => false, 'message' => t('That username is unavailable')]);
            return;
        }

        $stmt = $this->db->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        if ($stmt->fetch()) {
            Response::json(['available' => false, 'message' => t('Username taken')]);
            return;
        }

        Response::json(['available' => true, 'message' => t('Username available')]);
    }

    /**
     * 退出登录
     */
    public function logout(): void
    {
        $redirectTo = $_GET['redirect_to'] ?? '/login';

        $userId = Session::get('user_id');
        if ($userId) {
            $username = (string)Session::get('username', '');
            ActivityLog::record([
                'action'   => 'auth.logout',
                'category' => ActivityLog::CAT_AUTH,
                'actor_type' => 'user',
                'actor_id' => (int)$userId,
                'summary'  => '退出登录',
            ]);

            Hooks::run('user.logout', [(int)$userId, $username]);
        }

        Session::destroy();
        Response::redirect($redirectTo);
    }

}

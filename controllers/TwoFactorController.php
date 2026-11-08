<?php
namespace Nomen\Controllers;

use Nomen\Src\{ActivityLog, Database, Session, Csrf, Response, TOTP, Email, EmailTemplate, RateLimiter, GeoIP, I18n, RecoveryHelper, VerifyCode, FamilyHelper, EmailSet};
use Nomen\Controllers\DeviceController;
use PDO;

/**
 * 双因素验证控制器
 * 处理：TOTP管理、恢复码管理、2FA验证流程
 */
class TwoFactorController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ========== 2FA验证流程 ==========

    /**
     * 读取待验证会话，校验存在性 + action + TTL
     *
     * @return array pending 数据；不合法时返回空数组
     */
    private function loadPending(): array
    {
        $pending = Session::get('2fa_pending');
        if (!$pending || !isset($pending['user_id']) || ($pending['action'] ?? '') !== 'login') {
            return [];
        }

        $config = require __DIR__ . '/../config/app.php';
        $ttl = (int)($config['two_factor_pending_ttl'] ?? 600);
        if (time() - (int)($pending['created_at'] ?? 0) > $ttl) {
            Session::remove('2fa_pending');
            return [];
        }

        return $pending;
    }

    /**
     * 重新读取用户当前状态，避免用第一因素通过时的旧快照完成登录
     */
    private function loadActiveUser(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, email, role, status, email_verified, two_factor_policy FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user || (int)$user['status'] !== 1) {
            return null;
        }
        return $user;
    }

    /**
     * 校验跳转目标，避免开放重定向
     */
    private static function safeRedirect(string $url): string
    {
        if (!str_starts_with($url, '/') || str_contains($url, '://')) {
            return '/dashboard';
        }
        return $url;
    }

    /**
     * 2FA验证页面（选择第二因素）
     * GET /2fa/verify
     */
    public function verifyPage(): void
    {
        $pending = $this->loadPending();
        if (!$pending) {
            Session::flash('error', t('Verification session expired — sign in again'));
            Response::redirect('/login');
        }

        $userId = (int)$pending['user_id'];
        $firstFactor = $pending['first_factor'] ?? null;

        // 去掉已使用过的第一因素
        $factors = $this->getUserFactors($userId);
        if ($firstFactor && isset($factors[$firstFactor])) {
            unset($factors[$firstFactor]);
        }

        // 一个可用的第二因素都没有：不把用户锁在门外，直接完成登录
        if (empty($factors)) {
            $user = $this->loadActiveUser($userId);
            Session::remove('2fa_pending');
            if (!$user) {
                Session::destroy();
                Response::redirect('/login');
            }
            AuthController::finishLogin($user, self::safeRedirect($pending['redirect_to'] ?? '/dashboard'), !empty($pending['remember_me']));
            return;
        }

        // 账号有多个已验证邮箱时，让用户自己挑一个收码（默认首选）。
        // 这一步已经过了第一因素，列出自己的邮箱不泄露什么
        $emailChoices = array_values(array_filter(
            EmailSet::all($userId),
            static fn(array $row): bool => !empty($row['verified'])
        ));

        $error = Session::getFlash('error');
        Response::view('2fa-verify', compact('pending', 'factors', 'firstFactor', 'emailChoices', 'error'));
    }

    /**
     * POST /2fa/verify - 提交第二因素验证
     */
    public function verifyComplete(): void
    {
        $pending = $this->loadPending();
        if (!$pending) {
            Response::json(['success' => false, 'message' => t('Verification session expired — sign in again')]);
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => t('Invalid request')]);
        }

        // 一次性 nonce，防止 pending 被复用
        $nonce = (string)($_POST['nonce'] ?? '');
        if ($nonce === '' || !hash_equals((string)($pending['nonce'] ?? ''), $nonce)) {
            Response::json(['success' => false, 'message' => t('Invalid request — sign in again')]);
        }

        $userId = (int)$pending['user_id'];

        // 6 位码 10 分钟可爆破，必须限流（失败也计数）
        $rate = RateLimiter::check("auth:2fa:{$userId}", 10, 600);
        if (!$rate['allowed']) {
            Response::json(['success' => false, 'message' => t('Too many verification attempts — retry in {:n}s', ['n' => $rate['retry_after']])]);
        }

        $firstFactor  = $pending['first_factor'] ?? '';
        $secondFactor = $_POST['factor_type'] ?? '';
        $factorValue  = $_POST['factor_value'] ?? '';

        if (!$secondFactor || !$factorValue) {
            Response::json(['success' => false, 'message' => t('Pick a verification method and enter the value')]);
        }

        if ($secondFactor === $firstFactor) {
            Response::json(['success' => false, 'message' => t('The second step must use a different method from the first')]);
        }

        // 只能选该账号真实拥有、且不同于第一因素的方式
        $factors = $this->getUserFactors($userId);
        unset($factors[$firstFactor]);
        if (!isset($factors[$secondFactor])) {
            Response::json(['success' => false, 'message' => t('Invalid verification method')]);
        }

        if (!$this->verifyFactor($userId, $secondFactor, $factorValue)) {
            ActivityLog::record([
                'action'   => 'auth.2fa_failed',
                'category' => ActivityLog::CAT_AUTH,
                'actor_type' => 'user',
                'actor_id' => $userId,
                'actor_username' => (string)($pending['username'] ?? ''),
                'summary'  => '两步验证失败（方式：' . $secondFactor . '）',
            ]);
            Response::json(['success' => false, 'message' => t('Verification failed — check your input')]);
        }

        // 用第二因素通过后，再确认一次账号当前状态
        $user = $this->loadActiveUser($userId);
        Session::remove('2fa_pending');
        if (!$user) {
            Session::destroy();
            Response::json(['success' => false, 'message' => t('Account state looks off — sign in again')]);
        }

        // 设备信任：新设备才发通知、才记日志，every_login 场景只顺延信任期
        $deviceId  = DeviceController::ensureDeviceId();
        $wasTrusted = DeviceController::isTrustedDevice($userId);
        DeviceController::trustDevice($userId, $deviceId, $wasTrusted);
        if (!$wasTrusted) {
            DeviceController::notifyNewDevice($userId);
        }

        ActivityLog::record([
            'action'   => 'auth.2fa_passed',
            'category' => ActivityLog::CAT_AUTH,
            'actor_type' => 'user',
            'actor_id' => $userId,
            'actor_username' => (string)$user['username'],
            'summary'  => '两步验证通过（方式：' . $secondFactor . '）',
        ]);

        AuthController::finishLogin(
            $user,
            self::safeRedirect($pending['redirect_to'] ?? '/dashboard'),
            !empty($pending['remember_me']),
            true,
            [],
            '2fa',
            // 日志里记「两步验证」，但浏览器该记住的是他实际用的第一因素
            $firstFactor
        );
    }

    /**
     * POST /2fa/verify/send-code - 发送邮箱验证码（作为2FA因素）
     */
    public function sendCode(): void
    {
        $pending = $this->loadPending();
        if (!$pending) {
            Response::json(['success' => false, 'message' => t('Verification session expired — sign in again')]);
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => t('Invalid request')]);
        }

        $userId = (int)$pending['user_id'];
        $firstFactor = $pending['first_factor'] ?? '';

        // 必须确实是该账号可用的候选，且不同于第一因素
        $factors = $this->getUserFactors($userId);
        unset($factors[$firstFactor]);
        if (!isset($factors['email_code'])) {
            Response::json(['success' => false, 'message' => t('Email code verification is unavailable right now')]);
        }

        $rateCheck = RateLimiter::checkEmailRate($userId);
        if (!$rateCheck['allowed']) {
            Response::json(['success' => false, 'message' => $rateCheck['message'], 'retry_after' => $rateCheck['retry_after']]);
        }

        $stmt = $this->db->prepare('SELECT id, username, email FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) {
            Response::json(['success' => false, 'message' => t('User not found')]);
        }

        // 收信地址：这一步用户已经过了第一因素，可以让他在账号的多个邮箱里挑一个，
        // 没挑就发首选；未成年账号没有自己的地址，回退到监护人邮箱
        $contact = EmailSet::resolveRecipient($user, $_POST['send_to'] ?? null);
        if ($contact === null) {
            Response::json(['success' => false, 'message' => t('The account has no usable mailbox — contact support')]);
        }

        $code = str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // 存二验专用的一套键，不复用登录码：否则该码可被拿去 /login/code 当第一因素用
        if (!VerifyCode::issue(VerifyCode::TWO_FACTOR, $userId, $code)) {
            Response::json(['success' => false, 'message' => t('Service temporarily unavailable, try again later')]);
        }

        $locale = I18n::locale();
        $subject = EmailTemplate::subjectPrefix() . I18n::tIn($locale, '双因素验证码');
        $htmlBody = $this->getTwoFactorCodeEmailHtml($contact, $code, (string)$user['username'], $locale);

        if (Email::send($contact, $subject, $htmlBody)) {
            Response::json(['success' => true, 'message' => t('Code sent (valid for 10 minutes)')]);
        } else {
            Response::json(['success' => false, 'message' => t('Email failed to send')]);
        }
    }

    // ========== TOTP管理 ==========

    /**
     * GET /2fa/totp/setup - 获取TOTP设置信息
     */
    public function totpSetup(): void
    {
        $userId = (int)Session::get('user_id');
        if (!$userId) {
            Response::json(['success' => false, 'message' => t('Not signed in')]);
        }

        // 检查是否已有TOTP
        $stmt = $this->db->prepare('SELECT secret FROM user_totp_secrets WHERE user_id = :u AND verified = 1');
        $stmt->execute([':u' => $userId]);
        $existing = $stmt->fetch();

        if ($existing) {
            Response::json(['success' => true, 'has_totp' => true, 'message' => t('TOTP on')]);
        }

        // 生成新密钥
        $secret = TOTP::generateSecret();
        $uri = TOTP::getProvisioningUri($secret, Session::get('username'));

        // 临时存储（未验证）
        Session::set('totp_pending_secret', $secret);

        Response::json([
            'success' => true,
            'has_totp' => false,
            'secret' => $secret,
            'uri' => $uri,
        ]);
    }

    /**
     * POST /2fa/totp/verify - 验证TOTP并启用
     */
    public function totpVerify(): void
    {
        $userId = (int)Session::get('user_id');
        if (!$userId) {
            Response::json(['success' => false, 'message' => t('Not signed in')]);
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => t('Invalid request')]);
        }

        $code = trim($_POST['code'] ?? '');
        $secret = Session::get('totp_pending_secret');

        if (!$secret) {
            Response::json(['success' => false, 'message' => t('Please start setup again')]);
        }

        if (!preg_match('/^\d{6}$/', $code)) {
            Response::json(['success' => false, 'message' => t('Enter the 6-digit verification code')]);
        }

        if (!TOTP::verify($secret, $code)) {
            Response::json(['success' => false, 'message' => t('Wrong code, try again')]);
        }

        // 验证通过，保存密钥
        $stmt = $this->db->prepare(
            'INSERT INTO user_totp_secrets (user_id, secret, verified) VALUES (:u, :s, 1) ' .
            Database::dialectFor($this->db)->upsert(['user_id'], 'secret = :s2, verified = 1, updated_at = NOW()')
        );
        $stmt->execute([':u' => $userId, ':s' => $secret, ':s2' => $secret]);

        Session::remove('totp_pending_secret');

        ActivityLog::record([
            'action'   => 'security.totp_enable',
            'category' => ActivityLog::CAT_SECURITY,
            'summary'  => '绑定验证器 APP（TOTP）',
        ]);

        // 自动生成恢复码（如果还没有）；新生成的明文随响应返回，让用户当场保存
        $recoveryCodes = $this->ensureRecoveryCodes($userId);
        if ($recoveryCodes) {
            ActivityLog::record([
                'action'   => 'security.recovery_codes_generate',
                'category' => ActivityLog::CAT_SECURITY,
                'summary'  => '绑定验证器时自动生成一批恢复码',
            ]);
        }

        Response::json([
            'success' => true,
            'message' => t('TOTP enabled'),
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * POST /2fa/totp/disable - 禁用TOTP
     */
    public function totpDisable(): void
    {
        $userId = (int)Session::get('user_id');
        if (!$userId) {
            Response::json(['success' => false, 'message' => t('Not signed in')]);
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => t('Invalid request')]);
        }

        // 验证密码（恢复模式下旧密码正是问题所在，跳过）
        $password = $_POST['password'] ?? '';
        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        $config = require __DIR__ . '/../config/app.php';
        $pepper = $config['pepper'] ?? '';
        if (!RecoveryHelper::isActiveFor($userId)
            && !password_verify($password . $pepper, $user['password_hash'])
            && !password_verify($password, $user['password_hash'])) {
            Response::json(['success' => false, 'message' => t('Wrong password')]);
        }

        // 删除TOTP密钥
        $stmt = $this->db->prepare('DELETE FROM user_totp_secrets WHERE user_id = :u');
        $stmt->execute([':u' => $userId]);

        ActivityLog::record([
            'action'   => 'security.totp_disable',
            'category' => ActivityLog::CAT_SECURITY,
            'summary'  => '解绑验证器 APP（TOTP）',
        ]);

        Response::json(['success' => true, 'message' => t('TOTP disabled')]);
    }

    /**
     * GET /2fa/totp/status
     */
    public function totpStatus(): void
    {
        $userId = (int)Session::get('user_id');
        if (!$userId) {
            Response::json(['success' => false, 'message' => t('Not signed in')]);
        }

        $stmt = $this->db->prepare('SELECT verified FROM user_totp_secrets WHERE user_id = :u');
        $stmt->execute([':u' => $userId]);
        $row = $stmt->fetch();

        Response::json([
            'success' => true,
            'enabled' => (bool)$row,
        ]);
    }

    // ========== 恢复码管理 ==========

    /**
     * GET /2fa/recovery-codes - 获取恢复码列表（已脱敏）
     */
    public function getRecoveryCodes(): void
    {
        $userId = (int)Session::get('user_id');
        if (!$userId) {
            Response::json(['success' => false, 'message' => t('Not signed in')]);
        }

        // 按批次分组
        $stmt = $this->db->prepare(
            'SELECT batch_id, MAX(created_at) AS created_at,
                    SUM(CASE WHEN used_at IS NULL THEN 1 ELSE 0 END) AS unused_count,
                    COUNT(*) AS total_count
             FROM user_recovery_codes
             WHERE user_id = :u
             GROUP BY batch_id
             ORDER BY MAX(created_at) DESC'
        );
        $stmt->execute([':u' => $userId]);
        $batches = $stmt->fetchAll();

        Response::json([
            'success' => true,
            'batches' => $batches,
        ]);
    }

    /**
     * POST /2fa/recovery-codes/generate - 生成新批次恢复码
     */
    public function generateRecoveryCodes(): void
    {
        $userId = (int)Session::get('user_id');
        if (!$userId) {
            Response::json(['success' => false, 'message' => t('Not signed in')]);
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => t('Invalid request')]);
        }

        // 验证密码（恢复模式下旧密码正是问题所在，跳过）
        $password = $_POST['password'] ?? '';
        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        $config = require __DIR__ . '/../config/app.php';
        $pepper = $config['pepper'] ?? '';
        if (!RecoveryHelper::isActiveFor($userId)
            && !password_verify($password . $pepper, $user['password_hash'])
            && !password_verify($password, $user['password_hash'])) {
            Response::json(['success' => false, 'message' => t('Wrong password')]);
        }

        // 删除旧批次
        $stmt = $this->db->prepare('DELETE FROM user_recovery_codes WHERE user_id = :u');
        $stmt->execute([':u' => $userId]);

        // 生成批次ID
        $batchId = bin2hex(random_bytes(8));

        // 生成8个恢复码
        $codes = [];
        $rows = [];
        for ($i = 0; $i < 8; $i++) {
            $code = $this->generateRecoveryCode();
            $codes[] = $code;
            $rows[] = [
                ':u' => $userId,
                ':c' => password_hash($code, PASSWORD_ARGON2ID),
                ':b' => $batchId,
            ];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO user_recovery_codes (user_id, code_hash, batch_id) VALUES (:u, :c, :b)'
        );
        foreach ($rows as $row) {
            $stmt->execute($row);
        }

        ActivityLog::record([
            'action'   => 'security.recovery_codes_generate',
            'category' => ActivityLog::CAT_SECURITY,
            'summary'  => '生成（刷新）一批恢复码',
        ]);

        Response::json([
            'success' => true,
            'codes' => $codes,
            'batch_id' => $batchId,
            'message' => t('Recovery codes generated — save them now! They\'re shown once.'),
        ]);
    }

    /**
     * POST /2fa/recovery-codes/delete - 删除全部恢复码
     */
    public function deleteRecoveryCodes(): void
    {
        $userId = (int)Session::get('user_id');
        if (!$userId) {
            Response::json(['success' => false, 'message' => t('Not signed in')]);
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => t('Invalid request')]);
        }

        // 验证密码（恢复模式下旧密码正是问题所在，跳过）
        $password = $_POST['password'] ?? '';
        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        $config = require __DIR__ . '/../config/app.php';
        $pepper = $config['pepper'] ?? '';
        if (!RecoveryHelper::isActiveFor($userId)
            && !password_verify($password . $pepper, $user['password_hash'])
            && !password_verify($password, $user['password_hash'])) {
            Response::json(['success' => false, 'message' => t('Wrong password')]);
        }

        $stmt = $this->db->prepare('DELETE FROM user_recovery_codes WHERE user_id = :u');
        $stmt->execute([':u' => $userId]);

        ActivityLog::record([
            'action'   => 'security.recovery_codes_delete',
            'category' => ActivityLog::CAT_SECURITY,
            'summary'  => '删除全部恢复码',
        ]);

        Response::json(['success' => true, 'message' => t('Recovery codes deleted')]);
    }

    /**
     * POST /2fa/recovery-codes/verify - 验证恢复码
     */
    public function verifyRecoveryCode(): void
    {
        // 无Session要求，用于忘记密码场景
        $pending = Session::get('2fa_pending');
        $userId = null;
        if ($pending && isset($pending['user_id'])) {
            $userId = (int)$pending['user_id'];
        } else {
            $userId = (int)Session::get('user_id');
        }

        if (!$userId) {
            Response::json(['success' => false, 'message' => t('Not signed in or session expired')]);
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => t('Invalid request')]);
        }

        $code = trim($_POST['code'] ?? '');
        if (!$code) {
            Response::json(['success' => false, 'message' => t('Enter a recovery code')]);
        }

        // 查找未使用的恢复码
        $stmt = $this->db->prepare(
            'SELECT id, code_hash FROM user_recovery_codes
             WHERE user_id = :u AND used_at IS NULL
             ORDER BY created_at ASC'
        );
        $stmt->execute([':u' => $userId]);
        $codes = $stmt->fetchAll();

        foreach ($codes as $row) {
            if (password_verify($code, $row['code_hash'])) {
                // 标记为已使用
                $stmt = $this->db->prepare(
                    'UPDATE user_recovery_codes SET used_at = NOW() WHERE id = :id'
                );
                $stmt->execute([':id' => $row['id']]);
                ActivityLog::record([
                    'action'   => 'security.recovery_code_used',
                    'category' => ActivityLog::CAT_SECURITY,
                    'actor_type' => 'user',
                    'actor_id' => $userId,
                    'target_type' => 'recovery_code',
                    'target_id' => (int)$row['id'],
                    'summary'  => '使用恢复码通过验证',
                ]);
                Response::json(['success' => true, 'message' => t('Recovery code accepted')]);
            }
        }

        Response::json(['success' => false, 'message' => t('Recovery code invalid or already used')]);
    }

    // ========== 辅助方法 ==========

    /**
     * 获取用户可用的验证因素
     */
    public function getUserFactors(int $userId): array
    {
        $factors = [];

        // 密码（总是可用）
        $factors['password'] = [
            'type' => 'password',
            'label' => 'Account password',
            'icon' => 'key',
            'input_type' => 'password',
            'placeholder' => 'Enter your password',
        ];

        // 邮箱验证码（账号有任意一个已验证邮箱、且站点配了 SMTP 才算）
        // 没配 SMTP 时不能把它当可用因素：验证码根本寄不出去，用户会卡死在二验页
        if (Email::isConfigured()) {
            $stmt = $this->db->prepare('SELECT email_verified FROM users WHERE id = :id');
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();
            if (($user && $user['email_verified']) || EmailSet::hasVerified($userId)) {
                $factors['email_code'] = [
                    'type' => 'email_code',
                    'label' => 'Email code',
                    'icon' => 'mail',
                    'input_type' => 'text',
                    'placeholder' => 'Enter the 6-digit verification code',
                ];
            }
        }

        // TOTP（如果已启用）
        $stmt = $this->db->prepare('SELECT id FROM user_totp_secrets WHERE user_id = :u AND verified = 1');
        $stmt->execute([':u' => $userId]);
        if ($stmt->fetch()) {
            $factors['totp'] = [
                'type' => 'totp',
                'label' => 'Authenticator app (TOTP)',
                'icon' => 'phone',
                'input_type' => 'text',
                'placeholder' => 'Enter the 6-digit code',
            ];
        }

        // 恢复码（如果有未使用的）
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = :u AND used_at IS NULL'
        );
        $stmt->execute([':u' => $userId]);
        if ((int)$stmt->fetchColumn() > 0) {
            $factors['recovery_code'] = [
                'type' => 'recovery_code',
                'label' => 'Recovery codes',
                'icon' => 'refresh',
                'input_type' => 'text',
                'placeholder' => 'Enter the 12-character recovery code',
            ];
        }

        return $factors;
    }

    /**
     * 验证指定因素的值
     */
    public function verifyFactor(int $userId, string $factorType, string $value): bool
    {
        switch ($factorType) {
            case 'password':
                $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE id = :id');
                $stmt->execute([':id' => $userId]);
                $user = $stmt->fetch();
                if (!$user) return false;

                $config = require __DIR__ . '/../config/app.php';
                $pepper = $config['pepper'] ?? '';
                if (password_verify($value . $pepper, $user['password_hash'])) return true;
                if (password_verify($value, $user['password_hash'])) return true;
                return false;

            case 'email_code':
                if (VerifyCode::check(VerifyCode::TWO_FACTOR, $userId, $value) !== 'ok') {
                    return false;
                }

                // 用后即焚
                VerifyCode::clear(VerifyCode::TWO_FACTOR, $userId);
                return true;

            case 'totp':
                $stmt = $this->db->prepare('SELECT secret FROM user_totp_secrets WHERE user_id = :u AND verified = 1');
                $stmt->execute([':u' => $userId]);
                $row = $stmt->fetch();
                if (!$row) return false;
                return TOTP::verify($row['secret'], $value);

            case 'recovery_code':
                $stmt = $this->db->prepare(
                    'SELECT id, code_hash FROM user_recovery_codes WHERE user_id = :u AND used_at IS NULL'
                );
                $stmt->execute([':u' => $userId]);
                $codes = $stmt->fetchAll();
                foreach ($codes as $row) {
                    if (password_verify($value, $row['code_hash'])) {
                        $this->db->prepare('UPDATE user_recovery_codes SET used_at = NOW() WHERE id = :id')
                            ->execute([':id' => $row['id']]);
                        ActivityLog::record([
                            'action'   => 'security.recovery_code_used',
                            'category' => ActivityLog::CAT_SECURITY,
                            'actor_type' => 'user',
                            'actor_id' => $userId,
                            'target_type' => 'recovery_code',
                            'target_id' => (int)$row['id'],
                            'summary'  => '使用恢复码通过验证',
                        ]);
                        return true;
                    }
                }
                return false;

            default:
                return false;
        }
    }

    /**
     * 生成12位恢复码（大写字母+数字）
     */
    private function generateRecoveryCode(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }

    /**
     * 确保用户有未使用的恢复码；新建批次时返回明文码（只在这一次响应里出现），已有则返回 null。
     * 库里只保留一批：全部用完后新生成的这批会让旧的一批（连同已用记录）失效。
     */
    private function ensureRecoveryCodes(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = :u AND used_at IS NULL'
        );
        $stmt->execute([':u' => $userId]);
        if ((int)$stmt->fetchColumn() > 0) return null;

        // 自动生成一批
        $stmt = $this->db->prepare('DELETE FROM user_recovery_codes WHERE user_id = :u');
        $stmt->execute([':u' => $userId]);

        $batchId = bin2hex(random_bytes(8));
        $codes = [];
        $stmt = $this->db->prepare(
            'INSERT INTO user_recovery_codes (user_id, code_hash, batch_id) VALUES (:u, :c, :b)'
        );
        for ($i = 0; $i < 8; $i++) {
            $code = $this->generateRecoveryCode();
            $codes[] = $code;
            $stmt->execute([':u' => $userId, ':c' => password_hash($code, PASSWORD_ARGON2ID), ':b' => $batchId]);
        }
        return $codes;
    }

    /**
     * 双因素验证码邮件HTML
     */
    private function getTwoFactorCodeEmailHtml(string $contact, string $code, string $username = '', string $locale = 'zh-hans'): string
    {
        $items = [];
        if ($username !== '') {
            $items[] = ['label' => '账号', 'value' => $username];
        }
        $items[] = ['label' => 'Contact address', 'value' => $contact];

        return EmailTemplate::render([
            'preheader' => I18n::tIn($locale, '你的双因素验证码是 {:code}，10 分钟内有效', ['code' => $code]),
            'badge'     => ['text' => 'Security verification', 'tone' => 'general'],
            'title'     => 'Two-factor verification',
            'subtitle'  => $username !== '' ? I18n::tIn($locale, '账号 {:name} 的双因素验证码', ['name' => $username]) : I18n::tIn($locale, '输入验证码以完成身份确认'),
            'blocks'    => [
                ['type' => 'info', 'items' => $items],
                ['type' => 'code', 'label' => 'Code', 'value' => $code],
                ['type' => 'alert', 'tone' => 'warning', 'text' => 'The code is valid for 10 minutes. If this wasn\'t you, ignore this email.'],
            ],
        ], $locale);
    }
}

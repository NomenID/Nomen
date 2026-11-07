<?php
namespace Nomen\Controllers;

use Nomen\Src\{ActivityLog, Database, Session, Csrf, Response, RateLimiter, LoginMethods};
use Nomen\Controllers\DeviceController;
use lbuchs\WebAuthn\WebAuthn;
use PDO;

class BiometricController
{
    private PDO $db;
    private WebAuthn $webAuthn;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->initWebAuthn();
    }

    private function initWebAuthn(): void
    {
        $config = require __DIR__ . '/../config/app.php';
        $origin = $config['webauthn_origin'] ?? 'https://id.caellab.com';
        $rpName = $config['webauthn_rp_name'] ?? 'Nomen';

        $urlParts = parse_url($origin);
        $rpId = $urlParts['host'] ?? 'id.caellab.com';

        $this->webAuthn = new WebAuthn(
            $rpName,
            $rpId,
            null,
            true
        );
    }

    public function registrationOptions(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $userId = Session::get('user_id');
        if (!$userId) {
            Response::json(['success' => false, 'message' => t('Sign in first')], 401);
            return;
        }

        $stmt = $this->db->prepare('SELECT username, display_name FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) {
            Response::json(['success' => false, 'message' => t('User not found')], 404);
            return;
        }

        $options = $this->webAuthn->getCreateArgs(
            (string)$userId,
            $user['username'],
            $user['display_name'] ?? $user['username'],
            60,
            true,   // 可发现凭证：没有它，登录页就不能「不填账号点一下就登」
            true    // 注册时也要求用户验证
        );

        $challenge = $this->webAuthn->getChallenge();
        Session::set('webauthn_registration_challenge', $challenge);

        Response::json(['success' => true, 'options' => $options]);
    }

    public function verifyRegistration(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $userId = Session::get('user_id');
        if (!$userId) {
            Response::json(['success' => false, 'message' => t('Sign in first')], 401);
            return;
        }

        $postData = json_decode(file_get_contents('php://input'), true);
        if (!$postData || !isset($postData['credential'])) {
            Response::json(['success' => false, 'message' => t('Missing credential data')]);
            return;
        }

        $challenge = Session::get('webauthn_registration_challenge');
        if (!$challenge) {
            Response::json(['success' => false, 'message' => t('Challenge invalid or expired')]);
            return;
        }
        Session::remove('webauthn_registration_challenge');

        try {
            $clientDataJSON = base64_decode(str_replace(['-', '_'], ['+', '/'], $postData['credential']['clientDataJSON']));
            $attestationObject = base64_decode(str_replace(['-', '_'], ['+', '/'], $postData['credential']['attestationObject']));

            $result = $this->webAuthn->processCreate(
                $clientDataJSON,
                $attestationObject,
                $challenge,
                true,   // 必须有用户验证（指纹 / 面容 / PIN）
                true,
                false,
                false
            );

            if ($result !== null) {
                $credentialId = base64_encode($result->credentialId);
                $publicKey = base64_encode($result->credentialPublicKey);
                $transports = $postData['credential']['transports'] ?? [];

                $stmt = $this->db->prepare('SELECT COUNT(*) FROM user_biometrics WHERE user_id = :uid');
                $stmt->execute([':uid' => $userId]);
                $count = (int)$stmt->fetchColumn();
                if ($count >= 5) {
                    Response::json(['success' => false, 'message' => t('Up to 5 biometric credentials')]);
                    return;
                }

                $stmt = $this->db->prepare('INSERT INTO user_biometrics 
                    (user_id, credential_id, public_key, sign_count, transports, device_type, created_at) 
                    VALUES (:uid, :cid, :pk, :sc, :t, :dt, NOW())');
                $stmt->execute([
                    ':uid' => $userId,
                    ':cid' => $credentialId,
                    ':pk' => $publicKey,
                    ':sc' => 0,
                    ':t' => json_encode($transports),
                    ':dt' => $this->detectDeviceType($transports),
                ]);

                ActivityLog::record([
                    'action'   => 'security.biometric_add',
                    'category' => ActivityLog::CAT_SECURITY,
                    'target_type' => 'biometric',
                    'summary'  => '绑定生物识别凭证（' . $this->detectDeviceType($transports) . '）',
                ]);

                Response::json(['success' => true, 'message' => t('Biometric bound')]);
            } else {
                Response::json(['success' => false, 'message' => t('Verification failed')]);
            }
        } catch (\Exception $e) {
            Response::json(['success' => false, 'message' => t('Verification failed: ') . $e->getMessage()]);
        }
    }

    public function authenticationOptions(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!LoginMethods::on('biometric')) {
            Response::json(['success' => false, 'message' => t('This sign-in method is not enabled')], 404);
            return;
        }

        // 不传 identifier = 无账号一键：不生成 allowCredentials，
        // 由系统选择器列出本机所有 id.caellab.com 的通行密钥。
        // 传了 identifier（用户名或注册邮箱）= 兜底：老的不可发现凭证只能按账号定位。
        $identifier = trim((string)($_GET['identifier'] ?? $_GET['email'] ?? ''));
        $userId = null;

        // 一个邮箱可能对应 2 个账号（一个拿它当主邮箱、一个拿它当备选），
        // 兜底这条路要把两个账号的凭证都带上，不然选不出另一个账号的通行密钥。
        $userIds = [];
        if ($identifier !== '') {
            if (str_contains($identifier, '@')) {
                foreach (\Nomen\Src\EmailSet::candidatesForEmail($identifier) as $candidate) {
                    $userIds[] = (int)$candidate['id'];
                }
            } else {
                // 占位符必须分开写：PDO 关闭了模拟预处理，MySQL 原生预处理不允许重名占位符
                $stmt = $this->db->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
                $stmt->execute([':u' => $identifier]);
                $row = $stmt->fetch();
                if ($row) {
                    $userIds[] = (int)$row['id'];
                }
            }

            $userIds = array_values(array_filter($userIds));
            if (!$userIds) {
                // 文案不区分「账号不存在」和「没绑生物识别」，否则这里就是个账号枚举接口
                Response::json(['success' => false, 'message' => t('No biometrics bound to this account — check the username or email')]);
            }
            $userId = $userIds[0];
        }

        $credentialIds = [];
        if ($userIds !== []) {
            $stmt = $this->db->prepare(
                'SELECT credential_id FROM user_biometrics
                  WHERE user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ') AND revoked = 0'
            );
            $stmt->execute($userIds);
            foreach ($stmt->fetchAll() as $cred) {
                $credentialIds[] = base64_decode($cred['credential_id']);
            }
            if (!$credentialIds) {
                Response::json(['success' => false, 'message' => t('No biometrics bound to this account — bind one in account settings first')]);
            }
        }

        // 最后一个参数必须为 true：登录也要完成用户验证（指纹 / 面容 / PIN），不能只靠按一下
        $options = $this->webAuthn->getGetArgs($credentialIds, 60, true, true, true, true, true, true);

        Session::set('webauthn_authentication_challenge', $this->webAuthn->getChallenge());
        Session::set('webauthn_auth_user_id', $userId);

        Response::json(['success' => true, 'options' => $options]);
    }

    public function verifyAuthentication(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!LoginMethods::on('biometric')) {
            Response::json(['success' => false, 'message' => t('This sign-in method is not enabled')], 404);
            return;
        }

        $postData = json_decode(file_get_contents('php://input'), true);
        if (!$postData || !isset($postData['credential']['id'])) {
            Response::json(['success' => false, 'message' => t('Missing credential data')]);
        }

        $challenge = Session::get('webauthn_authentication_challenge');
        $expectedUserId = Session::get('webauthn_auth_user_id');

        if (!$challenge) {
            Response::json(['success' => false, 'message' => t('Challenge invalid or expired')]);
        }

        Session::remove('webauthn_authentication_challenge');
        Session::remove('webauthn_auth_user_id');

        $credentialId = str_replace(['-', '_'], ['+', '/'], (string)$postData['credential']['id']);
        while (strlen($credentialId) % 4) $credentialId .= '=';

        // 无账号模式靠凭证 ID 反查账号（凭证 ID 由认证器随机生成，猜不到）；
        // 兜底模式再限定这个用户，避免别处的凭证混进来
        $sql = 'SELECT b.id, b.user_id, b.public_key, b.sign_count
                FROM user_biometrics b
                JOIN users u ON u.id = b.user_id
                WHERE b.credential_id = :cid AND b.revoked = 0 AND u.status = 1';
        $params = [':cid' => $credentialId];
        if (!empty($expectedUserId)) {
            $sql .= ' AND b.user_id = :uid';
            $params[':uid'] = (int)$expectedUserId;
        }

        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $credential = $stmt->fetch();

        if (!$credential) {
            $this->failBiometric('本机凭证与该账号不匹配，请在账户设置里重新绑定');
        }

        $userId = (int)$credential['user_id'];

        // 账号维度 + IP 维度都要限流：不带账号的请求谁都能发，只按账号限流拦不住
        $userRate = RateLimiter::check("auth:biometric:{$userId}", 10, 300);
        if (!$userRate['allowed']) {
            Response::json(['success' => false, 'message' => t('Too many attempts — retry in {:n}s', ['n' => $userRate['retry_after']])]);
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ipRate = RateLimiter::check("auth:biometric:ip:{$ip}", 20, 300);
        if (!$ipRate['allowed']) {
            Response::json(['success' => false, 'message' => t('Too many attempts — retry in {:n}s', ['n' => $ipRate['retry_after']])]);
        }

        $stmt = $this->db->prepare('SELECT id, username, status, role, email_verified, two_factor_policy FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user || (int)$user['status'] !== 1) {
            Response::json(['success' => false, 'message' => t('User not found or disabled')]);
        }

        try {
            $this->webAuthn->processGet(
                base64_decode(str_replace(['-', '_'], ['+', '/'], (string)$postData['credential']['clientDataJSON'])),
                base64_decode(str_replace(['-', '_'], ['+', '/'], (string)$postData['credential']['authenticatorData'])),
                base64_decode(str_replace(['-', '_'], ['+', '/'], (string)$postData['credential']['signature'])),
                base64_decode($credential['public_key']),
                $challenge,
                (int)$credential['sign_count'],
                true,   // 必须完成用户验证（指纹 / 面容 / PIN）
                true
            );
        } catch (\Exception $e) {
            // 断言校验失败的具体原因（签名、来源、挑战、UV 标志）只进日志，不给前端
            error_log('[Biometric] 断言校验失败: ' . $e->getMessage());
            $this->failBiometric('生物识别验证失败，请重试或用其他方式登录');
        }

        $newSignCount = $this->webAuthn->getSignatureCounter();
        if ($newSignCount > (int)$credential['sign_count']) {
            $this->db->prepare('UPDATE user_biometrics SET sign_count = :sc WHERE id = :id')
                ->execute([':sc' => $newSignCount, ':id' => (int)$credential['id']]);
        }

        $redirect = $_GET['redirect_to'] ?? '/dashboard';
        if (!str_starts_with($redirect, '/') || str_contains($redirect, '://')) {
            $redirect = '/dashboard';
        }

        // 与密码 / 邮箱验证码登录走同一套两步验证判定
        Session::regenerate();
        if (AuthController::beginSecondFactorIfNeeded($user, 'biometric', $redirect, false)) {
            Response::json(['success' => true, 'message' => t('Two-step verification required'), 'redirect' => '/2fa/verify']);
        }

        AuthController::finishLogin($user, $redirect, false, true, [], 'biometric');
    }

    /**
     * 生物识别没成功：记下这次方式，登录页会据此把主区让给别的方式
     */
    private function failBiometric(string $message): void
    {
        AuthController::recordFailedMethod('biometric', $message);
        Response::json(['success' => false, 'message' => $message]);
    }

    private function detectDeviceType(array $transports): string
    {
        if (empty($transports)) {
            return 'unknown';
        }
        if (in_array('internal', $transports)) {
            return 'platform';
        }
        if (in_array('usb', $transports)) {
            return 'usb_key';
        }
        if (in_array('nfc', $transports)) {
            return 'nfc_card';
        }
        if (in_array('ble', $transports)) {
            return 'ble_device';
        }
        return 'unknown';
    }
}
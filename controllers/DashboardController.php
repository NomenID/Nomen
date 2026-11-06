<?php
namespace Nomen\Controllers;

use Nomen\Src\{ActivityLog, AppData, Database, Session, Csrf, Response, Email, EmailTemplate, EmailPolicy, EmailSet, I18n, RateLimiter, GeoIP, NotificationHelper, RecoveryHelper, FamilyHelper, Skins};
use Nomen\Controllers\DeviceController;
use PDO;

class DashboardController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        // 需要登录
        if (!Session::get('user_id')) {
            Response::redirect('/login?redirect_to=' . urlencode($_SERVER['REQUEST_URI']));
        }
    }

    /**
     * 仪表盘首页
     */
    public function index(): void
    {
        $userId = (int)Session::get('user_id');

        // 真 SPA：壳只出挂载点 + props（AppData 与 /api/app 端点共用同一份构建）
        $appProps = AppData::shell($userId, AppData::dashboard($userId));
        Response::view('app-shell', ['appProps' => $appProps, 'title' => t('仪表盘')], 'app');
    }

    /**
     * 个人设置页面
     */
    public function settings(): void
    {
        $userId = (int)Session::get('user_id');

        // 临时密钥登录不可访问设置
        if (Session::get('temp_key_login')) {
            Response::redirect('/dashboard');
            return;
        }

        // 真 SPA：壳只出挂载点 + props（AppData 与 /api/app 端点共用同一份构建）
        $appProps = AppData::shell($userId, AppData::settings($userId));
        Response::view('app-shell', ['appProps' => $appProps, 'title' => t('设置')], 'app');
    }

    /**
     * 更新个人资料
     */
    public function updateProfile(): void
    {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('请求无效，请重试')]);
            Session::flash('error', t('请求无效，请重试'));
            Response::redirect('/settings#sec-profile');
        }

        $userId = Session::get('user_id');
        $displayName = trim($_POST['display_name'] ?? '');
        $bio = trim($_POST['bio'] ?? '');

        $stmt = $this->db->prepare('SELECT display_name, bio FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $before = $stmt->fetch() ?: [];

        $stmt = $this->db->prepare(
            'UPDATE users SET display_name = :d, bio = :b WHERE id = :id'
        );
        $stmt->execute([':d' => $displayName, ':b' => $bio, ':id' => $userId]);

        ActivityLog::record([
            'action'   => 'profile.update',
            'category' => ActivityLog::CAT_PROFILE,
            'target_type' => 'user',
            'target_id' => (int)$userId,
            'target_name' => (string)Session::get('username', ''),
            'changes'  => ActivityLog::diff($before, ['display_name' => $displayName, 'bio' => $bio], ['display_name', 'bio']),
            'summary'  => '更新个人资料',
        ]);

        if ($isAjax) {
            Response::json(['success' => true, 'message' => t('个人资料已更新')]);
        }
        Session::flash('success', t('个人资料已更新'));
        Response::redirect('/settings#sec-profile');
    }

    /**
     * 修改密码
     */
    public function changePassword(): void
    {
        $recoveryMode = RecoveryHelper::isActiveFor((int)Session::get('user_id'));
        $fallback = $recoveryMode ? '/recovery/secure#sec-password' : '/settings#sec-password';
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('请求无效，请重试')]);
            Session::flash('error', t('请求无效，请重试'));
            Response::redirect($fallback);
        }

        $userId = Session::get('user_id');
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // 恢复模式下旧密码正是「进不来」的原因，不要求提供
        if ((!$currentPassword && !$recoveryMode) || !$newPassword) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('请填写所有字段')]);
            Session::flash('error', t('请填写所有字段'));
            Response::redirect($fallback);
        }

        if (strlen($newPassword) < 8 || !preg_match('/[a-zA-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('新密码至少8位，且必须包含字母和数字')]);
            Session::flash('error', t('新密码至少8位，且必须包含字母和数字'));
            Response::redirect($fallback);
        }

        if ($newPassword !== $confirmPassword) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('两次输入的新密码不一致')]);
            Session::flash('error', t('两次输入的新密码不一致'));
            Response::redirect($fallback);
        }

        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        $config = require __DIR__ . '/../config/app.php';
        $pepper = $config['pepper'] ?? '';
        $currentPasswordWithPepper = $currentPassword . $pepper;

        if (!$recoveryMode
            && !password_verify($currentPasswordWithPepper, $user['password_hash'])
            && !password_verify($currentPassword, $user['password_hash'])) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('当前密码错误')]);
            Session::flash('error', t('当前密码错误'));
            Response::redirect($fallback);
        }

        // 恢复模式下不校验旧密码，若允许改回原密码，恢复流程等于原地打转
        if (password_verify($newPassword . $pepper, $user['password_hash'])
            || password_verify($newPassword, $user['password_hash'])) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('新密码不能与当前密码相同')]);
            Session::flash('error', t('新密码不能与当前密码相同'));
            Response::redirect($fallback);
        }

        $newHash = password_hash($newPassword . $pepper, PASSWORD_ARGON2ID);
        $stmt = $this->db->prepare('UPDATE users SET password_hash = :p WHERE id = :id');
        $stmt->execute([':p' => $newHash, ':id' => $userId]);

        // 密码变更 = 旧凭据全部作废
        self::revokeAllUserTokens((int)$userId);
        Session::destroyOtherSessionsFor((int)$userId);

        ActivityLog::record([
            'action'   => 'security.password_change',
            'category' => ActivityLog::CAT_SECURITY,
            'summary'  => $recoveryMode ? '恢复流程中修改密码' : '修改密码',
        ]);

        if ($isAjax) {
            Response::json(['success' => true, 'message' => t('密码已修改，其他设备已被登出')]);
        }
        Session::flash('success', t('密码已修改，其他设备已被登出'));
        Response::redirect($fallback);
    }

    /**
     * 密码变更后作废该用户全部令牌与未使用的授权码
     *
     * 保留 user_authorizations（应用连接）与设备信任记录，
     * 与应用连接语义保持一致：令牌失效，但已授权的应用无需重新征求同意
     */
    public static function revokeAllUserTokens(int $userId): void
    {
        $db = Database::getInstance();

        $db->prepare('UPDATE oauth_access_tokens SET revoked = 1 WHERE user_id = :u')
            ->execute([':u' => $userId]);

        $db->prepare('UPDATE oauth_refresh_tokens SET revoked = 1 WHERE user_id = :u')
            ->execute([':u' => $userId]);

        $db->prepare('DELETE FROM oauth_authorization_codes WHERE user_id = :u')
            ->execute([':u' => $userId]);
    }

    /**
     * 撤销该用户全部第三方应用授权，返回被撤销的应用名
     *
     * 与 revokeAllUserTokens 的区别：这里把 user_authorizations 一起删掉，
     * 应用必须重新征求授权 —— 账号恢复的邮件就是这么承诺的，所以恢复流程必须调这个。
     * 普通改密码只作废令牌，仍走 revokeAllUserTokens，授权关系保留。
     *
     * @return string[]
     */
    public static function revokeAllUserAuthorizations(int $userId, string $username = ''): array
    {
        $db = Database::getInstance();

        $stmt = $db->prepare(
            'SELECT ua.client_id, oc.name FROM user_authorizations ua
             JOIN oauth_clients oc ON ua.client_id = oc.client_id
             WHERE ua.user_id = :u'
        );
        $stmt->execute([':u' => $userId]);
        $apps = $stmt->fetchAll();

        if (!$apps) {
            return [];
        }

        $db->prepare('DELETE FROM user_authorizations WHERE user_id = :u')->execute([':u' => $userId]);

        $names = [];
        foreach ($apps as $app) {
            $name = (string)$app['name'];
            $names[] = $name;

            // actor 显式传：恢复流程的调用点在会话建立之前，靠会话会被记成 system
            ActivityLog::record([
                'action'   => 'oauth.revoke',
                'category' => ActivityLog::CAT_OAUTH,
                'actor_type' => 'user',
                'actor_id' => $userId,
                'actor_username' => $username,
                'target_type' => 'oauth_client',
                'target_id' => (string)$app['client_id'],
                'target_name' => $name,
                'summary'  => '账号恢复：撤销应用「' . $name . '」的授权',
            ]);
        }

        return $names;
    }

    /**
     * 撤销应用授权
     */
    public function revokeAuthorization(string $clientId): void
    {
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Session::flash('error', t('请求无效，请重试'));
            Response::redirect('/dashboard');
        }

        $userId = Session::get('user_id');

        $stmt = $this->db->prepare('SELECT name FROM oauth_clients WHERE client_id = :c LIMIT 1');
        $stmt->execute([':c' => $clientId]);
        $clientName = (string)($stmt->fetchColumn() ?: '');

        // 删除授权记录
        $stmt = $this->db->prepare(
            'DELETE FROM user_authorizations WHERE user_id = :u AND client_id = :c'
        );
        $stmt->execute([':u' => $userId, ':c' => $clientId]);

        // 撤销相关 token
        $stmt = $this->db->prepare(
            'UPDATE oauth_access_tokens SET revoked = 1 WHERE user_id = :u AND client_id = :c'
        );
        $stmt->execute([':u' => $userId, ':c' => $clientId]);

        $stmt = $this->db->prepare(
            'UPDATE oauth_refresh_tokens SET revoked = 1 WHERE user_id = :u AND client_id = :c'
        );
        $stmt->execute([':u' => $userId, ':c' => $clientId]);

        ActivityLog::record([
            'action'   => 'oauth.revoke',
            'category' => ActivityLog::CAT_OAUTH,
            'target_type' => 'oauth_client',
            'target_id' => $clientId,
            'target_name' => $clientName,
            'summary'  => '撤销应用「' . $clientName . '」的授权',
        ]);

        Session::flash('success', t('已撤销该应用的授权'));

        // 真 SPA：AJAX 撤销直接回 JSON + 最新授权列表，前端原地更新
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            $userId = (int)$userId;
            Response::json([
                'success' => true,
                'message' => t('已撤销该应用的授权'),
                'data' => ['authorizations' => self::authorizationListFor($userId)],
            ]);
        }
        Response::redirect('/dashboard');
    }

    /** ==== AppData（真 SPA 数据源）用的静态查询，不依赖控制器实例（构造函数有登录守卫）==== */

    public static function activeTokensFor(int $userId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT id, device_id, device_name, ip_address, first_seen_at, last_seen_at, trusted_at
             FROM user_devices
             WHERE user_id = :u AND last_seen_at > ' . Database::dialectFor($db)->nowMinusDays(30) . '
             ORDER BY last_seen_at DESC'
        );
        $stmt->execute([':u' => $userId]);
        $devices = $stmt->fetchAll();
        foreach ($devices as &$d) {
            $d['location'] = GeoIP::getLocation($d['ip_address'] ?? '');
        }
        return $devices;
    }

    public static function trustedDevicesFor(int $userId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT id, device_id, device_name, ip_address, first_seen_at, last_seen_at, trusted_at
             FROM user_devices
             WHERE user_id = :u
             ORDER BY last_seen_at DESC'
        );
        $stmt->execute([':u' => $userId]);
        $devices = $stmt->fetchAll();
        foreach ($devices as &$d) {
            $d['location'] = GeoIP::getLocation($d['ip_address'] ?? '');
        }
        return $devices;
    }

    public static function authorizationListFor(int $userId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT ua.*, oc.name AS client_name, oc.logo_url, oc.icon, oc.homepage_url, d.icon AS developer_icon
             FROM user_authorizations ua
             JOIN oauth_clients oc ON ua.client_id = oc.client_id
             LEFT JOIN developers d ON oc.developer_id = d.id
             WHERE ua.user_id = :u
             ORDER BY ua.updated_at DESC'
        );
        $stmt->execute([':u' => $userId]);
        return $stmt->fetchAll();
    }

    public static function activityFeedFor(int $userId, int $limit = 10): array
    {
        $hidden = ['auth.2fa_passed', 'oauth.token_issued'];
        $rows = ActivityLog::recentFor($userId, $limit * 2);
        $rows = array_filter($rows, static fn(array $row) => !in_array($row['action'], $hidden, true));

        return array_slice(array_values($rows), 0, $limit);
    }

    /**
     * POST /settings/devices/revoke - 撤销设备信任
     */
    public function revokeDevice(): void
    {
        $userId = Session::get('user_id');
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('请求无效')]);
            Session::flash('error', t('请求无效'));
            Response::redirect('/settings');
        }

        $deviceId = trim($_POST['device_id'] ?? '');

        if (!$deviceId) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('缺少设备 ID')]);
            Session::flash('error', t('缺少设备 ID'));
            Response::redirect('/settings');
        }

        $currentDeviceId = $_COOKIE['caellab_device_id'] ?? '';
        if ($deviceId === $currentDeviceId) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('不能撤销当前设备的信任')]);
            Session::flash('error', t('不能撤销当前设备的信任'));
            Response::redirect('/settings');
        }

        $stmt = $this->db->prepare('SELECT device_name FROM user_devices WHERE user_id = :u AND device_id = :d LIMIT 1');
        $stmt->execute([':u' => $userId, ':d' => $deviceId]);
        $deviceName = (string)($stmt->fetchColumn() ?: $deviceId);

        $stmt = $this->db->prepare(
            'DELETE FROM user_devices WHERE user_id = :u AND device_id = :d'
        );
        $stmt->execute([':u' => $userId, ':d' => $deviceId]);

        // 一并撤销该设备相关的所有 OAuth Token，实现踢登
        $stmt = $this->db->prepare(
            'UPDATE oauth_access_tokens SET revoked = 1 WHERE user_id = :u'
        );
        $stmt->execute([':u' => $userId]);

        $stmt = $this->db->prepare(
            'UPDATE oauth_refresh_tokens SET revoked = 1 WHERE user_id = :u'
        );
        $stmt->execute([':u' => $userId]);

        if ($isAjax) {
            Response::json(['success' => true, 'message' => t('设备信任已撤销，相关 Token 已失效')]);
        }
        ActivityLog::record([
            'action'   => 'device.revoke',
            'category' => ActivityLog::CAT_DEVICE,
            'target_type' => 'device',
            'target_name' => $deviceName,
            'summary'  => '移除设备「' . $deviceName . '」',
        ]);
        Session::flash('success', t('设备信任已撤销，相关 Token 已失效'));
        Response::redirect('/settings');
    }

    public function revokeAllDevices(): void
    {
        $userId = Session::get('user_id');
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('请求无效')]);
            Session::flash('error', t('请求无效'));
            Response::redirect('/settings');
        }

        $currentDeviceId = $_COOKIE['caellab_device_id'] ?? '';

        $stmt = $this->db->prepare(
            'DELETE FROM user_devices WHERE user_id = :u AND device_id != :cd'
        );
        $stmt->execute([':u' => $userId, ':cd' => $currentDeviceId]);

        // 撤销所有 Token
        $this->db->prepare('UPDATE oauth_access_tokens SET revoked = 1 WHERE user_id = :u')->execute([':u' => $userId]);
        $this->db->prepare('UPDATE oauth_refresh_tokens SET revoked = 1 WHERE user_id = :u')->execute([':u' => $userId]);

        if ($isAjax) {
            Response::json(['success' => true, 'message' => t('已撤销所有其他设备的信任，相关 Token 已失效')]);
        }
        ActivityLog::record([
            'action'   => 'device.revoke_all',
            'category' => ActivityLog::CAT_DEVICE,
            'summary'  => '移除其他全部设备',
        ]);
        Session::flash('success', t('已撤销所有其他设备的信任，相关 Token 已失效'));
        Response::redirect('/settings');
    }

    /**
     * POST /settings/two-factor/policy - 更新两步验证策略
     */
    public function updateTwoFactorPolicy(): void
    {
        $userId = Session::get('user_id');
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('请求无效')]);
            Session::flash('error', t('请求无效'));
            Response::redirect('/settings#sec-2fa');
        }

        $policy = $_POST['policy'] ?? '';
        if (!in_array($policy, ['new_device', 'every_login'], true)) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('无效的策略选项')]);
            Session::flash('error', t('无效的策略选项'));
            Response::redirect('/settings#sec-2fa');
        }

        // 防止自我锁死：「每次登录都验证」必须至少有一个第二因素可用
        if ($policy === 'every_login') {
            $stmt = $this->db->prepare(
                'SELECT email_verified,
                        (SELECT COUNT(*) FROM user_totp_secrets t WHERE t.user_id = users.id AND t.verified = 1) AS totp_count
                 FROM users WHERE id = :id'
            );
            $stmt->execute([':id' => $userId]);
            $row = $stmt->fetch();

            if (!$row || (empty($row['email_verified']) && (int)$row['totp_count'] === 0)) {
                $msg = '请先验证邮箱或启用验证器 APP，再开启「每次登录都验证」';
                if ($isAjax) Response::json(['success' => false, 'message' => $msg]);
                Session::flash('error', $msg);
                Response::redirect('/settings#sec-2fa');
            }
        }

        $stmt = $this->db->prepare('SELECT two_factor_policy FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $oldPolicy = (string)($stmt->fetchColumn() ?: '');

        $stmt = $this->db->prepare('UPDATE users SET two_factor_policy = :p WHERE id = :id');
        $stmt->execute([':p' => $policy, ':id' => $userId]);

        ActivityLog::record([
            'action'   => 'security.2fa_policy',
            'category' => ActivityLog::CAT_SECURITY,
            'changes'  => ActivityLog::diff(
                ['two_factor_policy' => $oldPolicy],
                ['two_factor_policy' => $policy],
                ['two_factor_policy']
            ),
            'summary'  => '两步验证策略改为' . ($policy === 'every_login' ? '每次登录都验证' : '仅新设备验证'),
        ]);

        if ($isAjax) {
            Response::json(['success' => true, 'message' => t('两步验证策略已更新')]);
        }
        Session::flash('success', t('两步验证策略已更新'));
        Response::redirect('/settings#sec-2fa');
    }

    /**
     * POST /settings/quick-login - 开关「快速登录」
     * 开启（默认）时第一方应用免确认直接授权；关闭后走普通授权确认流程
     */
    public function updateQuickLogin(): void
    {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('请求无效，请重试')]);
            Session::flash('error', t('请求无效，请重试'));
            Response::redirect('/settings#sec-quick-login');
            return;
        }

        $userId = Session::get('user_id');
        $enabled = ($_POST['quick_login'] ?? '0') === '1' ? 1 : 0;

        $stmt = $this->db->prepare('SELECT quick_login FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $oldQuickLogin = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare('UPDATE users SET quick_login = :q WHERE id = :id');
        $stmt->execute([':q' => $enabled, ':id' => $userId]);

        ActivityLog::record([
            'action'   => 'security.quick_login',
            'category' => ActivityLog::CAT_SECURITY,
            'changes'  => ActivityLog::diff(
                ['quick_login' => $oldQuickLogin ? '1' : '0'],
                ['quick_login' => $enabled ? '1' : '0'],
                ['quick_login']
            ),
            'summary'  => $enabled ? '开启快速登录' : '关闭快速登录',
        ]);

        $message = $enabled
            ? 'Nomen启航计划项目快速中央登录已开启'
            : 'Nomen启航计划项目快速中央登录已关闭，授权时将逐项确认';
        if ($isAjax) {
            Response::json(['success' => true, 'message' => $message]);
        }
        Session::flash('success', $message);
        Response::redirect('/settings#sec-quick-login');
    }

    /**
     * POST /settings/locale - 设置页的「语言」下拉。cookie 由语言选择器那套负责，
     * 这里把账号级的写进库 + 会话，登出后语言照旧。
     */
    public function changeLocale(): void
    {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('请求无效，请重试')]);
            Session::flash('error', t('请求无效，请重试'));
            Response::redirect('/settings');
            return;
        }

        if (!I18n::switchingAllowed()) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('这个站点没有开放语言切换')]);
            Session::flash('error', t('这个站点没有开放语言切换'));
            Response::redirect('/settings');
            return;
        }

        $userId = (int)Session::get('user_id');
        $slug = (string)($_POST['locale'] ?? '');
        if (!I18n::isValid($slug)) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('不支持的语言')]);
            Session::flash('error', t('不支持的语言'));
            Response::redirect('/settings');
            return;
        }

        $stmt = $this->db->prepare('SELECT locale FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $oldLocale = (string)($stmt->fetchColumn() ?: '');

        $stmt = $this->db->prepare('UPDATE users SET locale = :l WHERE id = :id');
        $stmt->execute([':l' => $slug, ':id' => $userId]);
        Session::set('user_locale', $slug);
        setcookie(I18n::COOKIE, $slug, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);

        ActivityLog::record([
            'action'   => 'security.locale',
            'category' => ActivityLog::CAT_SECURITY,
            'changes'  => ActivityLog::diff(['locale' => $oldLocale], ['locale' => $slug], ['locale']),
            'summary'  => '界面语言设为 ' . $slug,
        ]);

        if ($isAjax) {
            Response::json(['success' => true, 'message' => t('语言已更新')]);
        }
        Session::flash('success', t('语言已更新'));
        Response::redirect('/settings');
    }

    /**
     * POST /settings/skin - 设置页的「外观」。只改自己的账号和这台浏览器，
     * 没登录的设备走 config/skins.php 里的默认皮肤。
     */
    public function changeSkin(): void
    {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('请求无效，请重试')]);
            Session::flash('error', t('请求无效，请重试'));
            Response::redirect('/settings');
            return;
        }

        $userId = (int)Session::get('user_id');
        $slug = (string)($_POST['skin'] ?? '');
        if (!Skins::exists($slug)) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('没有这个皮肤')]);
            Session::flash('error', t('没有这个皮肤'));
            Response::redirect('/settings');
            return;
        }

        $stmt = $this->db->prepare('SELECT skin FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $oldSkin = (string)($stmt->fetchColumn() ?: '');

        $stmt = $this->db->prepare('UPDATE users SET skin = :s WHERE id = :id');
        $stmt->execute([':s' => $slug, ':id' => $userId]);
        Session::set('user_skin', $slug);
        setcookie('nomen_skin', $slug, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);

        ActivityLog::record([
            'action'   => 'security.skin',
            'category' => ActivityLog::CAT_SECURITY,
            'changes'  => ActivityLog::diff(['skin' => $oldSkin], ['skin' => $slug], ['skin']),
            'summary'  => '外观设为 ' . $slug,
        ]);

        if ($isAjax) {
            Response::json(['success' => true, 'message' => t('外观已更新')]);
        }
        Session::flash('success', t('外观已更新'));
        Response::redirect('/settings');
    }

    /**
     * POST /settings/notifications - 消息偏好（勾选的类别才投递到消息中心）
     */
    public function updateNotificationSettings(): void
    {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('请求无效，请重试')]);
            Session::flash('error', t('请求无效，请重试'));
            Response::redirect('/settings#sec-notifications');
            return;
        }

        $userId = Session::get('user_id');
        // checkbox 未勾选时浏览器不提交该字段，按 0 处理
        $settings = [
            'notify_system_important' => (int)($_POST['notify_system_important'] ?? 0) === 1,
            'notify_system_general'   => (int)($_POST['notify_system_general'] ?? 0) === 1,
            'notify_system_interact'  => (int)($_POST['notify_system_interact'] ?? 0) === 1,
        ];
        $before = NotificationHelper::getSettings($userId);
        NotificationHelper::updateSettings($userId, $settings);

        ActivityLog::record([
            'action'   => 'security.notification_settings',
            'category' => ActivityLog::CAT_SECURITY,
            'changes'  => ActivityLog::diff($before, [
                'notify_system_important' => (int)$settings['notify_system_important'],
                'notify_system_general'   => (int)$settings['notify_system_general'],
                'notify_system_interact'  => (int)$settings['notify_system_interact'],
            ], ['notify_system_important', 'notify_system_general', 'notify_system_interact']),
            'summary'  => '更新消息偏好',
        ]);

        if ($isAjax) {
            Response::json(['success' => true, 'message' => t('消息偏好已更新')]);
        }
        Session::flash('success', t('消息偏好已更新'));
        Response::redirect('/settings#sec-notifications');
    }

    /**
     * 添加邮箱（AJAX）：先发一封验证邮件到新地址，点链接确认后才算数。
     *
     * 这一步既不设首选也不写 users.email —— 换首选是单独的动作，
     * 而且只有已验证的地址才能当首选（见 EmailSet）。
     */
    public function addEmail(): void
    {
        $userId = (int)Session::get('user_id');
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $user = $this->getAuthRow($userId);
        if (!$user || !$this->emailActionGuard($user, $isAjax)) {
            return;
        }

        $email = EmailPolicy::normalize((string)($_POST['new_email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->emailBack($isAjax, '邮箱格式不正确');
        }
        if (EmailPolicy::isBlocked($email)) {
            $this->emailBack($isAjax, t(EmailPolicy::REJECT_MESSAGE));
        }
        if (EmailSet::belongsTo($userId, $email)) {
            $this->emailBack($isAjax, '这个邮箱已经在你的账号里了');
        }
        if (EmailSet::count($userId) >= EmailSet::MAX) {
            $this->emailBack($isAjax, '一个账号最多绑定 ' . EmailSet::MAX . ' 个邮箱');
        }
        // 加进来的是「备选」，所以只跟别人的备选位比 —— 别人的**主邮箱**是可以收作自己备选的
        // （一个地址最多一主一备，两个位各自唯一，见 EmailSet 类注释）
        if (EmailSet::secondaryTakenByOther($email, $userId)) {
            $this->emailBack($isAjax, '这个邮箱已经是别的账号的备选邮箱了');
        }

        $rateCheck = RateLimiter::checkEmailRate($userId);
        if (!$rateCheck['allowed']) {
            $this->emailBack($isAjax, $rateCheck['message'], false, (int)($rateCheck['retry_after'] ?? 0));
        }

        $added = EmailSet::add($userId, $email);
        if ($added === null) {
            $this->emailBack($isAjax, '添加失败，请稍后重试');
        }

        ActivityLog::record([
            'action'      => 'security.email_added',
            'category'    => ActivityLog::CAT_SECURITY,
            'target_type' => 'user_email',
            'target_id'   => $added['id'],
            'target_name' => $email,
            'summary'     => '添加邮箱 ' . $email . '（待新邮箱确认）',
        ]);

        $locale = I18n::locale();
        if (Email::send($email, 'Nomen - ' . I18n::tIn($locale, '邮箱验证'), $this->getEmailVerifyEmailHtml($email, $this->emailVerifyUrl($added['token']), $locale))) {
            $this->emailBack($isAjax, '验证邮件已发送至 ' . $email . '，点里面的链接确认后才会生效', true);
        }
        $this->emailBack($isAjax, '验证邮件发送失败，请稍后重试');
    }

    /**
     * 重发某条待验证邮箱的验证邮件（AJAX）
     */
    public function resendEmailVerify(): void
    {
        $userId = (int)Session::get('user_id');
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $user = $this->getAuthRow($userId);
        if (!$user || !$this->emailActionGuard($user, $isAjax, false)) {
            return;
        }

        $row = EmailSet::findRow($userId, (int)($_POST['email_id'] ?? 0));
        if ($row === null) {
            $this->emailBack($isAjax, '这个邮箱不存在');
        }
        if (!empty($row['verified'])) {
            $this->emailBack($isAjax, '这个邮箱已经验证过了', true);
        }

        $rateCheck = RateLimiter::checkEmailRate($userId);
        if (!$rateCheck['allowed']) {
            $this->emailBack($isAjax, $rateCheck['message'], false, (int)($rateCheck['retry_after'] ?? 0));
        }

        $token = bin2hex(random_bytes(32));
        $this->db->prepare(
            'UPDATE user_emails SET verify_token = :t, verify_token_expires_at = :exp WHERE id = :id AND user_id = :u'
        )->execute([
            ':t'   => $token,
            ':exp' => date('Y-m-d H:i:s', time() + EmailSet::VERIFY_TTL),
            ':id'  => (int)$row['id'],
            ':u'   => $userId,
        ]);

        $email = (string)$row['email'];
        $locale = I18n::locale();
        if (Email::send($email, 'Nomen - ' . I18n::tIn($locale, '邮箱验证'), $this->getEmailVerifyEmailHtml($email, $this->emailVerifyUrl($token), $locale))) {
            $this->emailBack($isAjax, '验证邮件已重新发送至 ' . $email, true);
        }
        $this->emailBack($isAjax, '验证邮件发送失败，请稍后重试');
    }

    /**
     * 设为首选（AJAX）。首选地址就是 users.email —— 通知、登录标识、找回都优先用它
     */
    public function setPrimaryEmail(): void
    {
        $userId = (int)Session::get('user_id');
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $user = $this->getAuthRow($userId);
        if (!$user || !$this->emailActionGuard($user, $isAjax)) {
            return;
        }

        $row = EmailSet::findRow($userId, (int)($_POST['email_id'] ?? 0));
        if ($row === null) {
            $this->emailBack($isAjax, '这个邮箱不存在');
        }
        if (!empty($row['is_primary'])) {
            $this->emailBack($isAjax, '这已经是首选邮箱了', true);
        }
        $error = EmailSet::setPrimary($userId, (int)$row['id']);
        if ($error !== '') {
            $this->emailBack($isAjax, $error);
        }

        $email = (string)$row['email'];
        ActivityLog::record([
            'action'      => 'security.email_changed',
            'category'    => ActivityLog::CAT_SECURITY,
            'target_type' => 'user_email',
            'target_id'   => (int)$row['id'],
            'target_name' => $email,
            'changes'     => ActivityLog::diff(['email' => (string)$user['email']], ['email' => $email], ['email']),
            'summary'     => '首选邮箱更换为 ' . $email,
        ]);

        $this->emailBack($isAjax, '首选邮箱已改为 ' . $email, true);
    }

    /**
     * 移除一个邮箱（AJAX）
     */
    public function removeEmail(): void
    {
        $userId = (int)Session::get('user_id');
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $user = $this->getAuthRow($userId);
        if (!$user || !$this->emailActionGuard($user, $isAjax)) {
            return;
        }

        $row = EmailSet::findRow($userId, (int)($_POST['email_id'] ?? 0));
        if ($row === null) {
            $this->emailBack($isAjax, '这个邮箱不存在');
        }

        $error = EmailSet::remove($userId, (int)$row['id']);
        if ($error !== '') {
            $this->emailBack($isAjax, $error);
        }

        $email = (string)$row['email'];
        ActivityLog::record([
            'action'      => 'security.email_removed',
            'category'    => ActivityLog::CAT_SECURITY,
            'target_type' => 'user_email',
            'target_id'   => (int)$row['id'],
            'target_name' => $email,
            'summary'     => '移除邮箱 ' . $email,
        ]);

        $this->emailBack($isAjax, '已移除 ' . $email, true);
    }

    /**
     * 邮箱管理动作共用的身份确认：CSRF + 当前密码。
     * 恢复模式下旧密码正是用户进不来的原因，跳过（与改密码那几处同一口径）。
     */
    private function emailActionGuard(array $user, bool $isAjax, bool $needPassword = true): bool
    {
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $this->emailBack($isAjax, '请求无效，请重试');
        }

        if (!$needPassword || RecoveryHelper::isActiveFor((int)$user['id'])) {
            return true;
        }

        $password = (string)($_POST['password'] ?? '');
        $config = require __DIR__ . '/../config/app.php';
        $pepper = (string)($config['pepper'] ?? '');

        if ($password === ''
            || (!password_verify($password . $pepper, (string)$user['password_hash'])
                && !password_verify($password, (string)$user['password_hash']))) {
            $this->emailBack($isAjax, '当前密码错误');
        }

        return true;
    }

    private function emailBack(bool $isAjax, string $message, bool $success = false, ?int $retryAfter = null): void
    {
        if ($isAjax) {
            $payload = ['success' => $success, 'message' => $message];
            if ($retryAfter !== null && $retryAfter > 0) {
                $payload['retry_after'] = $retryAfter;
            }
            if ($success) {
                $userId = (int)Session::get('user_id');
                // emails 给 Vue 用（结构化数据）；html 留给还没迁的旧页面
                $payload['emails'] = EmailSet::all($userId);
                $payload['html'] = Response::viewPartial('_email_list', [
                    'emails'       => EmailSet::all($userId),
                    'maxEmails'    => EmailSet::MAX,
                    'recoveryMode' => RecoveryHelper::isActiveFor($userId),
                ]);
            }
            Response::json($payload);
        }

        Session::flash($success ? 'success' : 'error', $message);
        Response::redirect($this->backFromEmail((int)Session::get('user_id')) . '#sec-emails');
    }

    /** 邮箱相关动作该回哪个页面：恢复模式下回修复页，否则回设置页 */
    private function backFromEmail(int $userId): string
    {
        return RecoveryHelper::isActiveFor($userId) ? '/recovery/secure' : '/settings';
    }

    private function emailVerifyUrl(string $token): string
    {
        $config = require __DIR__ . '/../config/app.php';
        return rtrim($config['base_url'], '/') . '/settings/emails/verify?token=' . $token;
    }

    private function getEmailVerifyEmailHtml(string $email, string $verifyUrl, string $locale = 'zh-hans'): string
    {
        return EmailTemplate::render([
            'preheader' => I18n::tIn($locale, '确认把邮箱 {:email} 加到你的 Nomen 账户上', ['email' => $email]),
            'badge'     => ['text' => '账户安全', 'tone' => 'general'],
            'title'     => '确认这个邮箱地址',
            'subtitle'  => '确认后这个邮箱可以用来登录，也可以设为收信地址',
            'blocks'    => [
                ['type' => 'info', 'items' => [['label' => '新邮箱', 'value' => $email]]],
                ['type' => 'link', 'label' => '确认链接', 'url' => $verifyUrl],
                ['type' => 'alert', 'tone' => 'warning', 'text' => '此链接 24 小时内有效。如果不是你本人操作，请忽略此邮件，这个邮箱不会被加到任何账户上。'],
            ],
            'button'    => ['text' => '确认邮箱', 'url' => $verifyUrl],
        ], $locale);
    }

    /** 身份确认用：带 password_hash 的那一行（getUser() 不带） */
    private function getAuthRow(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, username, email, password_hash FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ========== 私有方法 ==========

    private function getUser(int $userId): array
    {
        // 临时密钥过期/撤销检查
        if (Session::get('temp_key_id')) {
            $stmt = $this->db->prepare(
                'SELECT id FROM user_temp_keys WHERE id = :id AND expires_at > NOW()'
            );
            $stmt->execute([':id' => Session::get('temp_key_id')]);
            if (!$stmt->fetch()) {
                Session::destroy();
                Session::flash('error', t('临时密钥已过期或被撤销'));
                Response::redirect('/login');
            }
        }

        $stmt = $this->db->prepare(
            'SELECT id, username, email, display_name, avatar, bio, email_verified, pending_email, role, two_factor_policy, quick_login, created_at, birth_date, gender, country FROM users WHERE id = :id'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if ($user) {
            $user['avatar_url'] = self::resolveAvatarUrl($user['avatar']);
        }
        return $user;
    }

    /**
     * 将头像值解析为完整的 URL
     */
    public static function resolveAvatarUrl(?string $avatar): ?string
    {
        if (empty($avatar)) return null;
        if (str_starts_with($avatar, 'http')) return $avatar;
        return '/assets/avatars/' . $avatar;
    }

    /**
     * 某个账号的头像 URL（导航栏这类模板里直接调；没头像返回 null）
     */
    public static function avatarUrlForUser(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        $stmt = Database::getInstance()->prepare('SELECT avatar FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        return self::resolveAvatarUrl($stmt->fetchColumn() ?: null);
    }

    /**
     * 发送重置密码验证码（AJAX）
     */
    public function sendPasswordResetCode(): void
    {
        $userId = Session::get('user_id');
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('请求无效')]);
            Session::flash('error', t('请求无效'));
            Response::redirect('/settings');
        }

        // 频率限制（共用邮箱限速池）
        $rateCheck = RateLimiter::checkEmailRate($userId);
        if (!$rateCheck['allowed']) {
            if ($isAjax) Response::json(['success' => false, 'message' => $rateCheck['message'], 'retry_after' => $rateCheck['retry_after']]);
            Session::flash('error', $rateCheck['message']);
            Response::redirect('/settings');
        }

        // 获取用户邮箱（未成年账号落的是监护人邮箱）
        $stmt = $this->db->prepare('SELECT id, email FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        // 有多个已验证邮箱时页面上可以挑一个收码（默认首选）
        $contact = EmailSet::resolveRecipient($user ?: [], $_POST['send_to'] ?? null);
        if ($contact === null) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('账号没有可用的收信地址，请联系支持团队')]);
            Session::flash('error', t('账号没有可用的收信地址，请联系支持团队（id@caellab.com）'));
            Response::redirect('/settings');
        }

        // 生成6位验证码
        $code = str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        // 存储验证码
        $stmt = $this->db->prepare(
            'UPDATE users SET password_reset_code = :code, password_reset_code_expires_at = :expires WHERE id = :id'
        );
        $stmt->execute([
            ':code'    => $code,
            ':expires' => $expiresAt,
            ':id'      => $userId,
        ]);

        // 发送验证码邮件
        $locale = I18n::locale();
        $subject = 'Nomen - ' . I18n::tIn($locale, '重置密码验证码');
        $htmlBody = $this->getPasswordResetCodeEmailHtml((string)$contact, $code, $locale);

        if (Email::send($contact, $subject, $htmlBody)) {
            if ($isAjax) Response::json(['success' => true, 'message' => t('验证码已发送，请查收（10分钟内有效）')]);
            Session::flash('success', t('验证码已发送，请查收（10分钟内有效）'));
        } else {
            if ($isAjax) Response::json(['success' => false, 'message' => t('邮件发送失败，请稍后重试')]);
            Session::flash('error', t('邮件发送失败，请稍后重试'));
        }

        Response::redirect('/settings');
    }

    /**
     * 使用验证码重置密码（AJAX）
     */
    public function resetPasswordWithCode(): void
    {
        $userId = Session::get('user_id');
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('请求无效')]);
            Session::flash('error', t('请求无效'));
            Response::redirect('/settings');
        }

        $code = trim($_POST['reset_code'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!$code || !$newPassword || !$confirmPassword) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('请填写所有字段')]);
            Session::flash('error', t('请填写所有字段'));
            Response::redirect('/settings');
        }

        if (strlen($newPassword) < 8 || !preg_match('/[a-zA-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('新密码至少8位，且必须包含字母和数字')]);
            Session::flash('error', t('新密码至少8位，且必须包含字母和数字'));
            Response::redirect('/settings');
        }

        if ($newPassword !== $confirmPassword) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('两次输入的新密码不一致')]);
            Session::flash('error', t('两次输入的新密码不一致'));
            Response::redirect('/settings');
        }

        // 查找用户验证码
        $stmt = $this->db->prepare(
            'SELECT id, password_hash, password_reset_code, password_reset_code_expires_at FROM users WHERE id = :id'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        // 验证验证码
        if (!$user['password_reset_code'] || !hash_equals($user['password_reset_code'], $code)) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('验证码错误')]);
            Session::flash('error', t('验证码错误'));
            Response::redirect('/settings');
        }

        // 检查是否过期
        if (strtotime($user['password_reset_code_expires_at']) < time()) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('验证码已过期，请重新发送')]);
            Session::flash('error', t('验证码已过期，请重新发送'));
            Response::redirect('/settings');
        }

        // 重置密码
        $config = require __DIR__ . '/../config/app.php';
        $pepper = $config['pepper'] ?? '';

        if (password_verify($newPassword . $pepper, $user['password_hash'])
            || password_verify($newPassword, $user['password_hash'])) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('新密码不能与当前密码相同')]);
            Session::flash('error', t('新密码不能与当前密码相同'));
            Response::redirect('/settings');
        }

        $newHash = password_hash($newPassword . $pepper, PASSWORD_ARGON2ID);
        $stmt = $this->db->prepare(
            'UPDATE users SET password_hash = :p, password_reset_code = NULL, password_reset_code_expires_at = NULL WHERE id = :id'
        );
        $stmt->execute([':p' => $newHash, ':id' => $userId]);

        // 密码变更 = 旧凭据全部作废
        self::revokeAllUserTokens((int)$userId);
        Session::destroyOtherSessionsFor((int)$userId);

        ActivityLog::record([
            'action'   => 'security.password_reset',
            'category' => ActivityLog::CAT_SECURITY,
            'summary'  => '使用邮箱验证码重置密码',
        ]);

        if ($isAjax) Response::json(['success' => true, 'message' => t('密码已重置成功，其他设备已被登出')]);
        Session::flash('success', t('密码已重置成功，其他设备已被登出'));
        Response::redirect('/settings');
    }

    /**
     * 上传头像（AJAX）
     */
    public function uploadAvatar(): void
    {
        $userId = Session::get('user_id');
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('请求无效')]);
            Response::redirect('/settings');
        }

        if (empty($_FILES['avatar'])) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('请选择要上传的头像')]);
            Response::redirect('/settings');
        }

        $filename = $this->processAvatarUpload($_FILES['avatar']);

        if ($filename === null) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('头像上传失败，请检查文件格式和大小')]);
            Response::redirect('/settings');
        }

        // 获取旧头像文件名，以便删除
        $stmt = $this->db->prepare('SELECT avatar FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $oldAvatar = $stmt->fetchColumn();

        // 更新数据库
        $stmt = $this->db->prepare('UPDATE users SET avatar = :avatar WHERE id = :id');
        $stmt->execute([':avatar' => $filename, ':id' => $userId]);

        // 删除旧头像文件（如果是本地文件）
        if ($oldAvatar && !str_starts_with($oldAvatar, 'http')) {
            $oldPath = __DIR__ . '/../public/assets/avatars/' . $oldAvatar;
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        // 必须写在 Response::json 之前：那个方法会 exit，日志会整条丢掉
        ActivityLog::record([
            'action'      => 'profile.avatar',
            'category'    => ActivityLog::CAT_PROFILE,
            'target_type' => 'avatar',
            'target_name' => $filename,
            'changes'     => ActivityLog::diff(
                ['avatar' => $oldAvatar ?: null],
                ['avatar' => $filename],
                ['avatar']
            ),
            'summary'     => '更换头像',
        ]);

        if ($isAjax) {
            Response::json(['success' => true, 'message' => t('头像已更新'), 'avatar_url' => '/assets/avatars/' . $filename]);
        }
        Session::flash('success', t('头像已更新'));
        Response::redirect('/settings');
    }

    /**
     * 处理头像文件上传
     */
    private function processAvatarUpload(array $file): ?string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            error_log("Avatar upload error: file error code " . $file['error']);
            return null;
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!function_exists('finfo_open')) {
            error_log('Avatar upload error: fileinfo extension missing');
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes)) {
            error_log("Avatar upload error: invalid mime type " . $mimeType);
            return null;
        }

        if ($file['size'] > 2 * 1024 * 1024) {
            error_log("Avatar upload error: file too large " . $file['size']);
            return null;
        }

        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            error_log("Avatar upload error: GD library not available");
            return null;
        }

        $src = null;
        switch ($mimeType) {
            case 'image/jpeg': $src = @imagecreatefromjpeg($file['tmp_name']); break;
            case 'image/png':  $src = @imagecreatefrompng($file['tmp_name']);  break;
            case 'image/gif':  $src = @imagecreatefromgif($file['tmp_name']);  break;
            case 'image/webp': $src = @imagecreatefromwebp($file['tmp_name']); break;
        }

        if (!$src) {
            error_log("Avatar upload error: failed to create image from " . $mimeType);
            return null;
        }

        $srcWidth = imagesx($src);
        $srcHeight = imagesy($src);
        $targetSize = 135;

        if ($srcWidth > $srcHeight) {
            $cropSize = $srcHeight;
            $cropX = (int)(($srcWidth - $cropSize) / 2);
            $cropY = 0;
        } else {
            $cropSize = $srcWidth;
            $cropX = 0;
            $cropY = (int)(($srcHeight - $cropSize) / 2);
        }

        $dst = imagecreatetruecolor($targetSize, $targetSize);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);

        imagecopyresampled(
            $dst, $src,
            0, 0, $cropX, $cropY,
            $targetSize, $targetSize,
            $cropSize, $cropSize
        );

        $hash = substr(md5(uniqid(mt_rand(), true)), 0, 8);
        $base = time() . '-' . $hash;

        $uploadDir = __DIR__ . '/../public/assets/avatars/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                error_log("Avatar upload error: failed to create directory " . $uploadDir);
                imagedestroy($src);
                imagedestroy($dst);
                return null;
            }
        }

        // 传上来的就是 webp 才留 webp，其余一律存 PNG；135×135 的 PNG 极少压不进 80KB，超了才退回 webp
        $savePng = $mimeType !== 'image/webp';
        $filename = $base . ($savePng ? '.png' : '.webp');
        $destPath = $uploadDir . $filename;

        $result = $savePng ? @imagepng($dst, $destPath, 9) : @imagewebp($dst, $destPath, 85);

        if ($result && $savePng && filesize($destPath) > 80 * 1024) {
            $webpPath = $uploadDir . $base . '.webp';
            if (@imagewebp($dst, $webpPath, 85)) {
                @unlink($destPath);
                $filename = $base . '.webp';
            }
        }

        imagedestroy($src);
        imagedestroy($dst);

        if (!$result) {
            error_log("Avatar upload error: failed to save image " . $destPath);
            return null;
        }

        return $filename;
    }

    /**
     * 重置密码验证码邮件 HTML
     */
    private function getPasswordResetCodeEmailHtml(string $email, string $code, string $locale = 'zh-hans'): string
    {
        return EmailTemplate::render([
            'preheader' => I18n::tIn($locale, '你的密码重置验证码是 {:code}，10 分钟内有效', ['code' => $code]),
            'badge'     => ['text' => '安全验证', 'tone' => 'general'],
            'title'     => '重置密码',
            'subtitle'  => '使用验证码重置你的 Nomen 密码',
            'blocks'    => [
                ['type' => 'info', 'items' => [['label' => '账户邮箱', 'value' => $email]]],
                ['type' => 'code', 'label' => '验证码', 'value' => $code],
                ['type' => 'alert', 'tone' => 'warning', 'text' => '此验证码 10 分钟内有效。如果不是你本人操作，请忽略此邮件，你的密码不会被修改。'],
            ],
        ], $locale);
    }

    public function getTempKeys(): void
    {
        $userId = (int)Session::get('user_id');
        $stmt = $this->db->prepare(
            'SELECT id, label, expires_at, last_used_at, created_at FROM user_temp_keys 
             WHERE user_id = :u ORDER BY expires_at DESC'
        );
        $stmt->execute([':u' => $userId]);
        $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare('SELECT COUNT(*) AS cnt FROM user_temp_keys WHERE user_id = :u AND expires_at > NOW()');
        $stmt->execute([':u' => $userId]);
        $count = $stmt->fetch()['cnt'];

        Response::json([
            'success' => true,
            'keys' => $keys,
            'count' => (int)$count,
            'max_count' => 5,
        ]);
    }

    public function createTempKey(): void
    {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!Csrf::validate($_POST['_csrf'] ?? '')) {
            Response::json(['success' => false, 'message' => t('请求无效')]);
        }

        $userId = (int)Session::get('user_id');
        $label = trim($_POST['label'] ?? '');
        $keyValue = trim($_POST['key_value'] ?? '');
        $expiresAt = trim($_POST['expires_at'] ?? '');

        if (!$label || !$keyValue || !$expiresAt) {
            Response::json(['success' => false, 'message' => t('请填写所有字段')]);
        }

        if (!preg_match('/^[a-zA-Z0-9]{10,}$/', $keyValue)) {
            Response::json(['success' => false, 'message' => t('临时密钥必须至少10位，且只包含字母和数字')]);
        }

        if (!preg_match('/[A-Z]/', $keyValue) || !preg_match('/[a-z]/', $keyValue) || !preg_match('/[0-9]/', $keyValue)) {
            Response::json(['success' => false, 'message' => t('临时密钥必须包含大写字母、小写字母和数字')]);
        }

        $expiresAt = date('Y-m-d H:i:s', strtotime($expiresAt));
        if (!$expiresAt || $expiresAt === '1970-01-01 08:00:00') {
            Response::json(['success' => false, 'message' => t('到期时间格式错误')]);
        }

        $stmt = $this->db->prepare('SELECT COUNT(*) AS cnt FROM user_temp_keys WHERE user_id = :u AND expires_at > NOW()');
        $stmt->execute([':u' => $userId]);
        $count = $stmt->fetch()['cnt'];
        if ($count >= 5) {
            Response::json(['success' => false, 'message' => t('最多只能同时存在5个临时密钥')]);
        }

        $maxExpires = date('Y-m-d H:i:s', time() + 14 * 24 * 3600);
        if ($expiresAt > $maxExpires) {
            Response::json(['success' => false, 'message' => t('临时密钥最长有效期为14天')]);
        }

        $keyHash = password_hash($keyValue, PASSWORD_ARGON2ID);

        $stmt = $this->db->prepare(
            'INSERT INTO user_temp_keys (user_id, key_hash, label, expires_at) VALUES (:u, :k, :l, :e)'
        );
        $stmt->execute([':u' => $userId, ':k' => $keyHash, ':l' => $label, ':e' => $expiresAt]);

        ActivityLog::record([
            'action'   => 'security.temp_key_create',
            'category' => ActivityLog::CAT_SECURITY,
            'target_type' => 'temp_key',
            'target_id' => (int)$this->db->lastInsertId(),
            'target_name' => $label,
            'summary'  => '创建临时密钥「' . $label . '」，' . $expiresAt . ' 到期',
        ]);

        Response::json(['success' => true, 'message' => t('临时密钥已创建')]);
    }

    public function deleteTempKey(): void
    {
        if (!Csrf::validate($_POST['_csrf'] ?? '')) {
            Response::json(['success' => false, 'message' => t('请求无效')]);
        }

        $userId = (int)Session::get('user_id');
        $keyId = (int)$_POST['key_id'] ?? 0;

        if (!$keyId) {
            Response::json(['success' => false, 'message' => t('参数错误')]);
        }

        $stmt = $this->db->prepare('SELECT label FROM user_temp_keys WHERE id = :id AND user_id = :u LIMIT 1');
        $stmt->execute([':id' => $keyId, ':u' => $userId]);
        $keyLabel = (string)($stmt->fetchColumn() ?: '');

        $stmt = $this->db->prepare('DELETE FROM user_temp_keys WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $keyId, ':u' => $userId]);

        if ($stmt->rowCount() === 0) {
            Response::json(['success' => false, 'message' => t('密钥不存在')]);
        }

        ActivityLog::record([
            'action'   => 'security.temp_key_delete',
            'category' => ActivityLog::CAT_SECURITY,
            'target_type' => 'temp_key',
            'target_id' => $keyId,
            'target_name' => $keyLabel,
            'summary'  => '删除临时密钥' . ($keyLabel !== '' ? '「' . $keyLabel . '」' : ''),
        ]);

        Response::json(['success' => true, 'message' => t('临时密钥已删除')]);
    }

    public function generateTempKey(): void
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $key = '';
        for ($i = 0; $i < 16; $i++) {
            $key .= $chars[random_int(0, strlen($chars) - 1)];
        }
        Response::json(['success' => true, 'key' => $key]);
    }

    public function getBiometrics(): void
    {
        $userId = (int)Session::get('user_id');
        $stmt = $this->db->prepare(
            'SELECT id, device_type, created_at FROM user_biometrics 
             WHERE user_id = :u AND revoked = 0 ORDER BY created_at DESC'
        );
        $stmt->execute([':u' => $userId]);
        $credentials = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::json([
            'success' => true,
            'credentials' => $credentials,
        ]);
    }

    public function deleteBiometric(): void
    {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!Csrf::validate($_POST['_csrf'] ?? '')) {
            Response::json(['success' => false, 'message' => t('请求无效')]);
        }

        $userId = (int)Session::get('user_id');
        $credentialId = (int)$_POST['id'] ?? 0;

        if (!$credentialId) {
            Response::json(['success' => false, 'message' => t('参数错误')]);
        }

        $stmt = $this->db->prepare('UPDATE user_biometrics SET revoked = 1 WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $credentialId, ':u' => $userId]);

        if ($stmt->rowCount() === 0) {
            Response::json(['success' => false, 'message' => t('凭证不存在')]);
        }

        ActivityLog::record([
            'action'   => 'security.biometric_remove',
            'category' => ActivityLog::CAT_SECURITY,
            'target_type' => 'biometric',
            'target_id' => $credentialId,
            'summary'  => '删除生物识别凭证',
        ]);

        Response::json(['success' => true, 'message' => t('生物识别凭证已删除')]);
    }
}

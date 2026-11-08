<?php
/**
 * Nomen SSO - 入口文件
 */

// .env、Composer 依赖、自动加载都在这里
require __DIR__ . '/../src/bootstrap.php';

// 伪静态自检：这个地址没有对应文件，能走到这里就说明 rewrite 生效了
if (($_SERVER['REQUEST_URI'] ?? '') === '/__rewrite_probe') {
    header('Content-Type: text/plain');
    echo 'ok';
    exit;
}

// 还没装：全站引导到安装向导。静态资源由 nginx 直接发，不会走到这里。
if (!is_file(__DIR__ . '/../config/installed.php') && !is_file(__DIR__ . '/../.env')) {
    header('Location: /nomen-install/');
    exit;
}

// 多语言：先于一切输出，全局 t() 助手在这里定义
require_once __DIR__ . '/../src/I18n.php';

use Nomen\Src\{Api, Router, Session, Response, Database, RecoveryHelper, FamilyHelper, Features, AppData, Nav, Admin, Plugins};
use Nomen\Controllers\{AuthController, OAuthController, DashboardController, ApiController, ActivationController, BiometricController, TwoFactorController, NotificationController, RecoveryController, AdminController, AdminAppsController, AdminMountsController, AdminSettingsController, AdminHomepageController, AdminIdentityController, IdentityController, FamilyController, EmailController};

// 全局安全响应头（HSTS 由 nginx 统一设置，此处不重复）
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
// 只启用不影响内联脚本的指令：frame-ancestors 用于授权页防点击劫持
header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; object-src 'none'");

// 启动 Session
Session::start();

// 语言判定：?lang > 账号偏好 > cookie > 浏览器语言 > en（详见 src/I18n.php）
\Nomen\Src\I18n::init();

// 插件：清单在 plugins/*/plugin.json，启用列表在 config/plugins.php
\Nomen\Src\Plugins::boot();

// 核心导航与后台菜单。插件在 boot 里已经登记过自己的，核心后登记，同名项由核心接管。
Nav::add('notifications', [
    'label' => '消息中心', 'href' => '/notifications', 'icon' => 'bell', 'order' => 5,
    'spa' => true, 'visible' => static fn(?int $uid): bool => $uid !== null && $uid > 0,
]);
Nav::add('dashboard', [
    'label' => '仪表盘', 'href' => '/dashboard', 'icon' => 'chart', 'order' => 20,
    'spa' => true, 'visible' => static fn(?int $uid): bool => $uid !== null && $uid > 0,
]);
Nav::add('settings', [
    'label' => '设置', 'href' => '/settings', 'icon' => 'sliders', 'order' => 30,
    'spa' => true, 'visible' => static fn(?int $uid): bool => $uid !== null && $uid > 0,
]);
Nav::add('admin', [
    'label' => Admin::label(), 'href' => '/admin', 'icon' => 'shield', 'order' => 40,
    'visible' => static fn(?int $uid): bool => $uid !== null && $uid > 0 && AdminController::isAdminUser($uid),
]);

Admin::menu('dashboard', '/admin', '概览', 10, '总览');
Admin::menu('homepage', '/admin/homepage', '首页', 14, '内容');
Admin::menu('users', '/admin/users', '用户', 20, '用户与接入');
Admin::menu('apps', '/admin/apps', '应用', 30, '用户与接入');
Admin::menu('identities', '/admin/identities', '登录方式', 35, '用户与接入');
Admin::menu('mounts', '/admin/mounts', '接入点', 40, '用户与接入');
Admin::menu('recovery', '/admin/recovery', '恢复工单', 50, '安全');
Admin::menu('logs', '/admin/logs', '活动日志', 60, '安全');
Admin::menu('settings', '/admin/settings', '设置', 70, '系统');

// 核心自带的特殊页面。注册在插件之后，插件不能顶掉它。
// title 存键原文，渲染时才翻译 —— 插件在清单里写的标题同样吃这套
\Nomen\Src\SpecialPages::add('version', [
    'title' => '版本',
    'handler' => [\Nomen\Src\Special\Version::class, 'render'],
]);
\Nomen\Src\SpecialPages::add('plugins', [
    'title' => '插件',
    'access' => 'admin',
    'handler' => [\Nomen\Src\Special\Plugins::class, 'render'],
]);
\Nomen\Src\SpecialPages::add('lang', [
    'title' => '语言',
    'handler' => [\Nomen\Src\Special\Lang::class, 'render'],
]);

// 关键密钥健康检查：pepper 缺失会导致密码哈希强度被削弱
$appConfig = require __DIR__ . '/../config/app.php';
if (str_starts_with((string)$appConfig['pepper'], 'change-me-to')) {
    error_log('[SECURITY] APP_PEPPER 未配置，正在使用默认值，请在 .env 中设置随机字符串');
}

// 全局检查：已登录但一个收信地址都没验证的用户，重定向到激活页
$userId = Session::get('user_id');
$currentUri = '/' . trim((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$recoveryActive = $userId && RecoveryHelper::isActiveFor((int)$userId);
// 站点 API 自己按 token 判权限，不能被页面的「去激活 / 去修复」重定向接管
$isSiteApi = $currentUri === '/nomen-api' || str_starts_with($currentUri, '/nomen-api/');

// 恢复模式自己有一套更严的闸（见下），两者叠加会让修复用的 POST 被这一步弹走
if ($userId && !$recoveryActive && !$isSiteApi) {
    // 白名单：激活流程自己要用到的页面
    $whitelist = ['/activate', '/activate/send', '/activate/verify', '/activate/delete-account', '/logout', '/settings/emails/verify'];

    $isWhitelisted = in_array($currentUri, $whitelist)
        || str_starts_with($currentUri, '/activate/verify')
        || str_starts_with($currentUri, '/settings/emails/verify');

    if (!$isWhitelisted) {
        $db = Database::getInstance();
        // 至少一个收信地址验证通过才能用：自己的邮箱（users.email_verified），
        // 或者没有自己邮箱的孩子走家长那条（家长点确认 / 接受邀请会记 consent_at）。
        // 光填一个别人的地址就能用账号，等于给批量注册留口子。
        $stmt = $db->prepare('SELECT email_verified FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if ($user && empty($user['email_verified']) && !FamilyHelper::guardianConfirmed((int)$userId)) {
            Response::redirect('/activate');
        }
    }
}

// 恢复模式：安全修复完成前，除修复所需的接口外一律拉回修复页。
// 只拦页面型 GET —— 修复动作本身是 POST/AJAX，拦了会把接口打残。
if ($recoveryActive && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !$isSiteApi) {
    $recoveryWhitelist = ['/recovery', '/recovery/secure', '/recovery/sent', '/recovery/ticket', '/activate', '/logout', '/captcha'];

    $isAllowed = in_array($currentUri, $recoveryWhitelist)
        || str_starts_with($currentUri, '/2fa/')
        || str_starts_with($currentUri, '/biometric/')
        || str_starts_with($currentUri, '/settings/biometrics')
        || str_starts_with($currentUri, '/settings/emails/verify')
        || str_starts_with($currentUri, '/activate/');

    if (!$isAllowed) {
        Response::redirect('/recovery/secure');
    }
}

$router = new Router();

// 插件路由先灌进去；核心路由在后面注册，同名路径由核心覆盖
\Nomen\Src\Plugins::applyRoutes($router);
// 插件注册的后台页面（/admin/<slug>），同样在核心管理路由之前
Admin::applyRoutes($router);

// 插件静态资源：plugins/<slug>/assets/ 下的文件，只发白名单类型
$router->get('/plugin-assets/{slug}/{path:.*}', static function (string $slug, string $path): void {
    Plugins::serveAsset($slug, $path);
});

// 前端词典（app.js 与 Vue 共用；管理后台不挂，t() 拿不到词典就原样显示中文键）。
// 不带 .js 后缀：nginx 的静态 location 会把 *.js 拦在 PHP 前面（2026-11-05 线上踩过）
$router->get('/i18n/{locale}', fn(string $locale) => \Nomen\Src\I18n::serveScript($locale));

// 站点地图：Pages 的挂载点可以在后台改，这里按当前路径生成
$router->get('/sitemap.xml', function () {
    $base = rtrim((string)\Nomen\Src\Brand::get('base_url'), '/');
    $docs = Plugins::mountUrl('pages');

    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";

    if ($docs === null) {
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
        return;
    }

    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>'
        . htmlspecialchars($base . $docs . '/sitemap')
        . '</loc></sitemap></sitemapindex>';
});

$router->get('/robots.txt', function () {
    $base = rtrim((string)\Nomen\Src\Brand::get('base_url'), '/');

    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nAllow: /\n\nSitemap: " . $base . "/sitemap.xml\n";
});

// 人机验证
$router->get('/captcha', fn() => \Nomen\Src\Captcha::generate());

// 首页：已登录用户直接进仪表盘，带 redirect=no 时仍显示落地页
// 特殊页面：/special/version 这类，注册表在 src/SpecialPages.php
// 单项许可全文：/special/version/plugin/geoip/license
$router->get('/special/version/{type}/{name}/license', function (string $type, string $name) {
    \Nomen\Src\Special\License::render($type, $name);
});
// 附加条款与声明：/special/version/core/nomen/notice
$router->get('/special/version/{type}/{name}/notice', function (string $type, string $name) {
    \Nomen\Src\Special\License::renderNotice($type, $name);
});
// 插件作者自己写的说明：/special/version/plugin/geoip/readme
$router->get('/special/version/{type}/{name}/readme', function (string $type, string $name) {
    \Nomen\Src\Special\License::renderReadme($type, $name);
});
$router->get('/special/{page}', function (string $page) {
    \Nomen\Src\SpecialPages::render($page);
});

$router->get('/', function () {
    \Nomen\Src\Homepage::render();
});

// 认证相关
$router->get('/login',    [AuthController::class, 'login']);
$router->post('/login',   [AuthController::class, 'handleLogin']);
$router->post('/login/send-code', [AuthController::class, 'sendLoginCode']);
$router->post('/login/code', [AuthController::class, 'handleLoginWithCode']);
$router->post('/login/temp-key', [AuthController::class, 'handleLoginWithTempKey']);
$router->get('/fast-pass', [AuthController::class, 'fastPass']);
$router->get('/register', [AuthController::class, 'register']);
$router->post('/register',[AuthController::class, 'handleRegister']);
// 邮箱被未验证账号占用时的回收验证：只凭邮箱收到的验证码生效，不依赖登录会话
$router->get('/register/verify',         [AuthController::class, 'showRegisterVerify']);
$router->post('/register/verify',        [AuthController::class, 'handleRegisterVerify']);
$router->post('/register/verify/resend', [AuthController::class, 'resendRegisterVerifyCode']);
$router->get('/api/check-username', [AuthController::class, 'checkUsername']);
$router->get('/api/consent-age', [ApiController::class, 'consentAge']);
$router->get('/api/version',     [ApiController::class, 'siteVersion']);
$router->get('/logout',   [AuthController::class, 'logout']);

// 第三方登录（GitHub / Google / Microsoft / CaelLabID / 自定义 OAuth2）
$router->get('/auth/{slug}/redirect', [IdentityController::class, 'redirect']);
$router->get('/auth/{slug}/callback', [IdentityController::class, 'callback']);
$router->post('/settings/identities/unlink', [IdentityController::class, 'unlink'], ['auth']);

// 双因素验证（2FA）：新设备验证与「每次登录验证」共用这一条链路
$router->get('/2fa/verify',              [TwoFactorController::class, 'verifyPage']);
$router->post('/2fa/verify',             [TwoFactorController::class, 'verifyComplete']);
$router->post('/2fa/verify/send-code',   [TwoFactorController::class, 'sendCode']);
$router->get('/2fa/totp/setup',          [TwoFactorController::class, 'totpSetup']);
$router->post('/2fa/totp/verify',        [TwoFactorController::class, 'totpVerify']);
$router->post('/2fa/totp/disable',       [TwoFactorController::class, 'totpDisable']);
$router->get('/2fa/totp/status',         [TwoFactorController::class, 'totpStatus']);
$router->get('/2fa/recovery-codes',      [TwoFactorController::class, 'getRecoveryCodes']);
$router->post('/2fa/recovery-codes/generate', [TwoFactorController::class, 'generateRecoveryCodes']);
$router->post('/2fa/recovery-codes/delete',   [TwoFactorController::class, 'deleteRecoveryCodes']);
$router->post('/2fa/recovery-codes/verify',   [TwoFactorController::class, 'verifyRecoveryCode']);

// 生物识别（WebAuthn）
$router->get('/biometric/registration/options',  [BiometricController::class, 'registrationOptions']);
$router->post('/biometric/registration/verify',  [BiometricController::class, 'verifyRegistration']);
$router->get('/biometric/auth/options',          [BiometricController::class, 'authenticationOptions']);
$router->post('/biometric/auth/verify',          [BiometricController::class, 'verifyAuthentication']);

// 邮箱激活
$router->get('/activate',            [ActivationController::class, 'activate']);
$router->post('/activate/send',      [ActivationController::class, 'sendVerification']);
$router->get('/activate/verify',     [ActivationController::class, 'verify']);
$router->post('/activate/delete-account', [ActivationController::class, 'deleteAccount']);

// OAuth 端点
$router->get('/oauth/authorize',  [OAuthController::class, 'authorize']);
$router->post('/oauth/authorize', [OAuthController::class, 'handleAuthorize']);
$router->get('/oauth/deny',       [OAuthController::class, 'deny']);
$router->post('/oauth/token',     [OAuthController::class, 'token']);
$router->post('/oauth/revoke',    [OAuthController::class, 'revoke']);

// 用户仪表盘（需登录）
$router->get('/dashboard', [DashboardController::class, 'index'], ['auth']);
$router->get('/settings',  [DashboardController::class, 'settings'], ['auth']);
$router->post('/settings/profile',  [DashboardController::class, 'updateProfile'], ['auth']);
$router->post('/settings/avatar',  [DashboardController::class, 'uploadAvatar'], ['auth']);
$router->post('/settings/password', [DashboardController::class, 'changePassword'], ['auth']);
$router->post('/settings/emails/add',     [DashboardController::class, 'addEmail'], ['auth']);
$router->post('/settings/emails/resend',  [DashboardController::class, 'resendEmailVerify'], ['auth']);
$router->post('/settings/emails/primary', [DashboardController::class, 'setPrimaryEmail'], ['auth']);
$router->post('/settings/emails/remove',  [DashboardController::class, 'removeEmail'], ['auth']);
// 邮箱验证链接可能在其他浏览器中打开，只凭 token 生效，不做登录校验。
// 走独立的 EmailController —— DashboardController 的构造函数会先要求登录
$router->get('/settings/emails/verify', [EmailController::class, 'verify']);
$router->post('/settings/password/send-code', [DashboardController::class, 'sendPasswordResetCode'], ['auth']);
$router->post('/settings/password/reset-code', [DashboardController::class, 'resetPasswordWithCode'], ['auth']);
$router->post('/settings/devices/revoke', [DashboardController::class, 'revokeDevice'], ['auth']);
$router->post('/settings/devices/revoke-all', [DashboardController::class, 'revokeAllDevices'], ['auth']);
$router->post('/settings/two-factor/policy', [DashboardController::class, 'updateTwoFactorPolicy'], ['auth']);
$router->post('/settings/locale', [DashboardController::class, 'changeLocale'], ['auth']);
$router->post('/settings/skin', [DashboardController::class, 'changeSkin'], ['auth']);
$router->get('/settings/temp-keys', [DashboardController::class, 'getTempKeys'], ['auth']);
$router->post('/settings/temp-keys', [DashboardController::class, 'createTempKey'], ['auth']);
$router->post('/settings/temp-keys/delete', [DashboardController::class, 'deleteTempKey'], ['auth']);
$router->get('/settings/temp-keys/generate', [DashboardController::class, 'generateTempKey'], ['auth']);
$router->get('/settings/biometrics', [DashboardController::class, 'getBiometrics'], ['auth']);
$router->post('/settings/biometrics/delete', [DashboardController::class, 'deleteBiometric'], ['auth']);
$router->post('/dashboard/revoke/{clientId}', [DashboardController::class, 'revokeAuthorization'], ['auth']);

// 应用区真 SPA 的数据端点：与各页首屏 props 共用 AppData 构建（模板壳 / 这里两处同源）
$router->get('/api/app/dashboard', function () {
    $userId = (int)Session::get('user_id');
    Response::json(['ok' => true] + AppData::shell($userId, AppData::dashboard($userId)));
}, ['auth']);
$router->get('/api/app/settings', function () {
    $userId = (int)Session::get('user_id');
    Response::json(['ok' => true] + AppData::shell($userId, AppData::settings($userId)));
}, ['auth']);
if (Features::guardians()) {
    $router->get('/api/app/family', function () {
        $userId = (int)Session::get('user_id');
        Response::json(['ok' => true] + AppData::shell($userId, AppData::family($userId)));
    }, ['auth']);
}
$router->get('/api/app/notifications', function () {
    $userId = (int)Session::get('user_id');
    $page = max(1, (int)($_GET['page'] ?? 1));
    Response::json(['ok' => true] + AppData::shell($userId, AppData::notifications($userId, $page)));
}, ['auth']);
$router->get('/api/app/notifications/{id}', function ($id) {
    $userId = (int)Session::get('user_id');
    $data = AppData::notificationDetail($userId, (int)$id);
    if (!$data) {
        Response::json(['ok' => false, 'message' => t('Message not found')], 404);
    }
    Response::json(['ok' => true] + AppData::shell($userId, $data));
}, ['auth']);

// 开放平台四页（开发者侧）由 plugins/open-platform 自己注册路由，核心不再引

// 站内消息
$router->get('/notifications', [NotificationController::class, 'index'], ['auth']);
$router->get('/notifications/{id}', [NotificationController::class, 'show'], ['auth']);
$router->post('/notifications/read-all', [NotificationController::class, 'readAll'], ['auth']);
$router->post('/notifications/read', [NotificationController::class, 'readOne'], ['auth']);
$router->post('/settings/notifications', [DashboardController::class, 'updateNotificationSettings'], ['auth']);

// 家庭：孩子账号 + 家长账号的邀请 / 加入 / 停止监护（可选功能，默认关）
if (Features::guardians()) {
    $router->get('/family',                   [FamilyController::class, 'index'], ['auth']);
    $router->post('/family/accept',           [FamilyController::class, 'accept'], ['auth']);
    $router->post('/family/claim/send',       [FamilyController::class, 'claimSend'], ['auth']);
    $router->post('/family/claim',            [FamilyController::class, 'claimVerify'], ['auth']);
    $router->post('/family/invite-parent',    [FamilyController::class, 'inviteParent'], ['auth']);
    $router->post('/family/guardian/add',     [FamilyController::class, 'guardianAdd'], ['auth']);
    $router->post('/family/child/create',     [FamilyController::class, 'childCreate'], ['auth']);
    $router->post('/family/child/status',     [FamilyController::class, 'childStatus'], ['auth']);
    $router->post('/family/child/reset/send', [FamilyController::class, 'childResetSend'], ['auth']);
    $router->post('/family/child/reset',      [FamilyController::class, 'childResetVerify'], ['auth']);
    $router->post('/family/release',          [FamilyController::class, 'release'], ['auth']);
    $router->post('/family/leave',            [FamilyController::class, 'leave'], ['auth']);
}

// API 端点
$router->get('/api/userinfo',   [ApiController::class, 'userinfo']);
$router->get('/api/token-info', [ApiController::class, 'tokenInfo']);

// 站点 API（MediaWiki 式）：/nomen-api?action=query&meta=siteinfo&format=json
$router->any('/nomen-api', static fn() => Api::handle());

// 账号恢复（申诉 → 一次性恢复链接 → 强制安全修复）
$router->get('/recovery',                [RecoveryController::class, 'form']);
$router->post('/recovery',               [RecoveryController::class, 'submit']);
$router->post('/recovery/upload',        [RecoveryController::class, 'upload']);
$router->get('/recovery/sent',           [RecoveryController::class, 'sent']);
$router->get('/recovery/ticket',         [RecoveryController::class, 'ticket']);
$router->get('/recovery/secure',         [RecoveryController::class, 'secure']);
$router->post('/recovery/secure/finish', [RecoveryController::class, 'finish']);

// 管理后台：GET 一律交给 SPA 外壳，数据走 /api/admin/*
$router->get('/admin', function (): void {
    \Nomen\Src\AdminShell::render();
}, ['admin']);
$router->get('/admin/logs/export',          [AdminController::class, 'logExport'], ['admin']);
$router->get('/admin/{path:.*}', function (string $path): void {
    \Nomen\Src\AdminShell::render();
}, ['admin']);

$router->get('/api/admin/dashboard',        [AdminController::class, 'index'], ['admin']);
$router->get('/api/admin/version',          [AdminController::class, 'version'], ['admin']);
$router->get('/api/admin/users',            [AdminController::class, 'users'], ['admin']);
$router->get('/api/admin/users/{id}',       [AdminController::class, 'userDetail'], ['admin']);
$router->post('/admin/users/toggle-status', [AdminController::class, 'toggleStatus'], ['admin']);
$router->post('/admin/users/toggle-admin',  [AdminController::class, 'toggleAdmin'], ['admin']);
$router->get('/api/admin/apps',             [AdminAppsController::class, 'index'], ['admin']);
$router->get('/api/admin/apps/create',      [AdminAppsController::class, 'create'], ['admin']);
$router->get('/api/admin/apps/{clientId}',  [AdminAppsController::class, 'show'], ['admin']);
$router->post('/admin/apps/create',         [AdminAppsController::class, 'store'], ['admin']);
$router->post('/admin/apps/{clientId}/update',       [AdminAppsController::class, 'update'], ['admin']);
$router->post('/admin/apps/{clientId}/reset-secret', [AdminAppsController::class, 'resetSecret'], ['admin']);
$router->post('/admin/apps/{clientId}/toggle',       [AdminAppsController::class, 'toggle'], ['admin']);
$router->post('/admin/apps/{clientId}/delete',       [AdminAppsController::class, 'delete'], ['admin']);
$router->get('/api/admin/mounts',           [AdminMountsController::class, 'index'], ['admin']);
$router->post('/admin/mounts/{plugin}',     [AdminMountsController::class, 'updatePath'], ['admin']);
$router->get('/api/admin/recovery',         [AdminController::class, 'recovery'], ['admin']);
$router->get('/api/admin/recovery/{id}',    [AdminController::class, 'recoveryDetail'], ['admin']);
$router->post('/admin/recovery/approve',    [AdminController::class, 'recoveryApprove'], ['admin']);
$router->post('/admin/recovery/reject',     [AdminController::class, 'recoveryReject'], ['admin']);
$router->post('/admin/recovery/resend',     [AdminController::class, 'recoveryResend'], ['admin']);
$router->get('/api/admin/logs',             [AdminController::class, 'logs'], ['admin']);
$router->get('/api/admin/logs/{id}',        [AdminController::class, 'logDetail'], ['admin']);
$router->get('/api/admin/settings',         [AdminSettingsController::class, 'index'], ['admin']);
$router->post('/admin/settings',            [AdminSettingsController::class, 'update'], ['admin']);
$router->get('/api/admin/homepage',         [AdminHomepageController::class, 'show'], ['admin']);
$router->post('/admin/homepage',            [AdminHomepageController::class, 'update'], ['admin']);
$router->get('/api/admin/identities',       [AdminIdentityController::class, 'index'], ['admin']);
$router->post('/admin/identities/save',     [AdminIdentityController::class, 'save'], ['admin']);
$router->post('/admin/identities/delete',   [AdminIdentityController::class, 'delete'], ['admin']);
$router->post('/admin/identities/toggle',   [AdminIdentityController::class, 'toggle'], ['admin']);
$router->post('/admin/identities/toggle-method', [AdminIdentityController::class, 'toggleMethod'], ['admin']);

// 开放平台

// 分发请求
$router->dispatch();

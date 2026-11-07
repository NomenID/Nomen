<?php
namespace Nomen\Controllers;

use Nomen\Src\{ActivityLog, Admin, Brand, Cache, Csrf, Email, Hooks, Response, Settings};
use PDOException;

class AdminSettingsController
{
    public const LOGIN_2FA_OPTIONS = [
        'off'         => '关闭（只验密码）',
        'new_device'  => '仅新设备验证',
        'every_login' => '每次登录都验证',
    ];

    public const CACHE_OPTIONS = [
        'auto'  => '自动（Redis 可用就用，否则文件）',
        'redis' => 'Redis',
        'file'  => '文件',
        'db'    => '数据库',
    ];

    /** 内置选项 + 插件注册的驱动 */
    private static function cacheOptions(): array
    {
        return self::CACHE_OPTIONS + array_diff_key(Cache::available(), self::CACHE_OPTIONS);
    }

    public function index(): void
    {
        Admin::requireAdmin();

        $policy = (string)Settings::get('security.login_2fa', 'off');
        if (!isset(self::LOGIN_2FA_OPTIONS[$policy])) {
            $policy = 'off';
        }

        $backend = (string)Settings::get('cache.backend', 'auto');
        if (!isset(self::cacheOptions()[$backend])) {
            $backend = 'auto';
        }

        Response::json(Hooks::filter('admin.settings.data', [
            'success'          => true,
            'policy'           => $policy,
            'login2faOptions'  => self::LOGIN_2FA_OPTIONS,
            'backend'          => $backend,
            'cacheOptions'     => self::cacheOptions(),
            'effectiveBackend' => Cache::driverName(),
            'mailConfigured'   => Email::isConfigured(),
            'settingsReady'    => Settings::ready(),
            'apiEnabled'       => (string)Settings::get('api.nomen_api', '1') !== '0',
            'apiEndpoint'      => rtrim((string)Brand::get('base_url'), '/') . '/nomen-api',
        ]));
    }

    public function update(): void
    {
        $admin = Admin::requireAdmin();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => '请求无效，请重试'], 422);
        }

        $policy = (string)($_POST['login_2fa'] ?? '');
        $backend = (string)($_POST['cache_backend'] ?? '');

        if (!isset(self::LOGIN_2FA_OPTIONS[$policy]) || !isset(self::cacheOptions()[$backend])) {
            Response::json(['success' => false, 'message' => '选项不在列表里，请重新选择'], 422);
        }

        $oldPolicy = (string)Settings::get('security.login_2fa', 'off');
        $oldBackend = (string)Settings::get('cache.backend', 'auto');
        $oldApi = (string)Settings::get('api.nomen_api', '1');
        $apiEnabled = array_key_exists('api_nomen', $_POST)
            ? ((string)$_POST['api_nomen'] === '1' ? '1' : '0')
            : ($oldApi === '1' ? '1' : '0');

        try {
            Settings::setMany([
                'security.login_2fa' => $policy,
                'cache.backend'      => $backend,
                'api.nomen_api'      => $apiEnabled,
            ]);
        } catch (PDOException $e) {
            error_log('[settings] 保存失败: ' . $e->getMessage());
            Response::json(['success' => false, 'message' => '数据库还没升级（缺 site_settings 表），先跑 php bin/nomen upgrade'], 500);
        }

        ActivityLog::record([
            'action'         => 'admin.site_settings',
            'category'       => ActivityLog::CAT_ADMIN,
            'actor_type'     => 'admin',
            'actor_id'       => (int)$admin['id'],
            'actor_username' => (string)$admin['username'],
            'summary'        => '修改站点设置',
            'changes'        => ActivityLog::diff(
                ['login_2fa' => $oldPolicy, 'cache_backend' => $oldBackend, 'api_nomen' => $oldApi],
                ['login_2fa' => $policy, 'cache_backend' => $backend, 'api_nomen' => $apiEnabled],
                ['login_2fa', 'cache_backend', 'api_nomen']
            ),
        ]);

        Hooks::run('admin.settings.saved', [$policy, $backend, (int)$admin['id'], $apiEnabled]);

        Response::json(['success' => true, 'message' => '设置已保存，下次请求生效']);
    }
}

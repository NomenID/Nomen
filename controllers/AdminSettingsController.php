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
            'policies'         => Brand::configuredPolicies(),
            'additionalTerms'  => Brand::additionalTerms(),
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

        $updates = [
            'security.login_2fa' => $policy,
            'cache.backend'      => $backend,
            'api.nomen_api'      => $apiEnabled,
        ];

        if (array_key_exists('policies', $_POST)) {
            $list = self::decodeList($_POST['policies']);
            if ($list === null) {
                Response::json(['success' => false, 'message' => '政策链接格式不正确'], 422);
            }
            $updates['brand.policies'] = self::encodeList(self::normalizePolicies($list));
        }
        if (array_key_exists('additional_terms', $_POST)) {
            $list = self::decodeList($_POST['additional_terms']);
            if ($list === null) {
                Response::json(['success' => false, 'message' => '附加条款格式不正确'], 422);
            }
            $updates['brand.additional_terms'] = self::encodeList(self::normalizeTerms($list));
        }

        try {
            Settings::setMany($updates);
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

    /** POST /admin/settings/cache/clear - 只清本站前缀下的缓存 */
    public function clearCache(): void
    {
        $admin = Admin::requireAdmin();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => '请求无效，请重试'], 422);
        }

        $result = Cache::flush();
        Settings::forgetCache();

        if (!$result['supported']) {
            Response::json([
                'success'   => false,
                'supported' => false,
                'backend'   => $result['backend'],
            ], 422);
        }

        ActivityLog::record([
            'action'         => 'admin.cache_clear',
            'category'       => ActivityLog::CAT_ADMIN,
            'actor_type'     => 'admin',
            'actor_id'       => (int)$admin['id'],
            'actor_username' => (string)$admin['username'],
            'summary'        => '清空缓存（' . $result['backend'] . '，' . $result['deleted'] . ' 条）',
        ]);

        Response::json([
            'success'   => true,
            'supported' => true,
            'backend'   => $result['backend'],
            'deleted'   => (int)$result['deleted'],
        ]);
    }

    /** @return array<int, array<string, mixed>>|null */
    private static function decodeList(mixed $raw): ?array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /** @param array<int, mixed> $list */
    private static function normalizePolicies(array $list): array
    {
        $out = [];
        foreach ($list as $policy) {
            if (!is_array($policy)) {
                continue;
            }
            $url = trim((string)($policy['url'] ?? ''));
            $label = trim((string)($policy['label'] ?? ''));
            $role = (string)($policy['role'] ?? 'other');
            if (!in_array($role, ['privacy', 'terms', 'other'], true)) {
                $role = 'other';
            }
            if ($url === '' || ($label === '' && $role === 'other')) {
                continue;
            }
            $out[] = ['role' => $role, 'label' => $label, 'url' => $url];
            if (count($out) === 3) {
                break;
            }
        }

        return $out;
    }

    /** @param array<int, mixed> $list */
    private static function normalizeTerms(array $list): array
    {
        $out = [];
        foreach ($list as $term) {
            if (!is_array($term)) {
                continue;
            }
            $label = trim((string)($term['label'] ?? ''));
            $url = trim((string)($term['url'] ?? ''));
            if ($label !== '' && $url !== '') {
                $out[] = ['label' => $label, 'url' => $url];
            }
        }

        return $out;
    }

    /** @param array<int, array<string, mixed>> $list */
    private static function encodeList(array $list): string
    {
        return $list === [] ? '' : (string)json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

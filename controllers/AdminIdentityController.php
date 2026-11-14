<?php
namespace Nomen\Controllers;

use InvalidArgumentException;
use Nomen\Src\{ActivityLog, Admin, Csrf, IconUpload, IdentityProviders, LoginMethods, Response, Settings};

class AdminIdentityController
{
    public function index(): void
    {
        Admin::requireAdmin();

        $providers = array_map(static function (array $row): array {
            $hasSecret = (string)$row['client_secret'] !== '';
            unset($row['client_secret']);
            $row['has_secret'] = $hasSecret;
            $row['callback_url'] = IdentityProviders::callbackUrl((string)$row['slug']);

            return $row;
        }, IdentityProviders::all());

        Response::json([
            'success'           => true,
            'providers'         => $providers,
            'presets'           => IdentityProviders::presets(),
            'methods'           => LoginMethods::all(),
            'default_method'    => LoginMethods::default(),
            'extra_methods'     => LoginMethods::extra(),
            'providers_enabled' => count(IdentityProviders::all(true)),
        ]);
    }

    private static function methodName(string $method): string
    {
        $labels = [
            'password'   => '密码登录',
            'email_code' => '邮箱验证码登录',
            'biometric'  => '生物识别登录',
            'temp_key'   => '临时密钥登录',
            'oauth'      => '第三方登录',
        ];

        if (isset($labels[$method])) {
            return $labels[$method];
        }

        foreach (LoginMethods::extra() as $extra) {
            if ($extra['id'] === $method) {
                return $extra['label'];
            }
        }

        return $method;
    }

    public function defaultMethod(): void
    {
        $admin = Admin::requireAdmin();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => '请求无效，请重试'], 422);
        }

        $method = (string)($_POST['method'] ?? '');
        if (!LoginMethods::isDefaultable($method)) {
            Response::json(['success' => false, 'message' => '该登录方式当前不可用，设不了为默认'], 422);
        }

        Settings::set('login.default', $method);

        ActivityLog::record([
            'action'         => 'admin.login_method_default',
            'category'       => ActivityLog::CAT_ADMIN,
            'actor_type'     => 'admin',
            'actor_id'       => (int)$admin['id'],
            'actor_username' => (string)$admin['username'],
            'summary'        => '默认登录方式设为：' . self::methodName($method),
        ]);

        Response::json([
            'success'        => true,
            'message'        => '默认登录方式已改为：' . self::methodName($method),
            'default_method' => $method,
        ]);
    }

    public function toggleMethod(): void
    {
        $admin = Admin::requireAdmin();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => '请求无效，请重试'], 422);
        }

        $method = (string)($_POST['method'] ?? '');
        if (!in_array($method, LoginMethods::METHODS, true)) {
            Response::json(['success' => false, 'message' => '登录方式不存在'], 404);
        }

        $on = !empty($_POST['on']);
        $next = LoginMethods::all();
        $next[$method] = $on;

        if (!LoginMethods::hasAvailableWay($next)) {
            Response::json([
                'success' => false,
                'message' => '这样会没有任何可用的登录方式（第三方登录要算数，至少得有一个已启用的提供方）',
            ], 422);
        }

        Settings::set('login.method_' . $method, $on ? '1' : '0');

        $labels = [
            'password'   => '密码登录',
            'email_code' => '邮箱验证码登录',
            'biometric'  => '生物识别登录',
            'temp_key'   => '临时密钥登录',
            'oauth'      => '第三方登录',
        ];

        ActivityLog::record([
            'action'         => 'admin.login_method_toggle',
            'category'       => ActivityLog::CAT_ADMIN,
            'actor_type'     => 'admin',
            'actor_id'       => (int)$admin['id'],
            'actor_username' => (string)$admin['username'],
            'summary'        => ($on ? '开启' : '关闭') . '登录方式：' . ($labels[$method] ?? $method),
        ]);

        Response::json([
            'success' => true,
            'message' => ($on ? '已开启' : '已关闭') . '：' . ($labels[$method] ?? $method),
            'methods' => LoginMethods::all(),
        ]);
    }

    public function save(): void
    {
        $admin = Admin::requireAdmin();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => '请求无效，请重试'], 422);
        }

        $id = (int)($_POST['id'] ?? 0);

        // 图标：传了新文件就替换；勾了清除就置空；否则保留原值（预置 logo 走表单里的路径字段）
        if (!empty($_FILES['logo']['name'])) {
            $file = IconUpload::store($_FILES['logo']);
            if ($file === null) {
                Response::json(['success' => false, 'message' => '图标上传失败：只支持 PNG / JPG / GIF / WebP，2MB 以内'], 422);
            }
            $_POST['logo'] = IconUpload::url($file);
        } elseif (!empty($_POST['logo_clear'])) {
            $_POST['logo'] = '';
        }

        try {
            [$provider] = IdentityProviders::save($_POST, $id > 0 ? $id : null);
        } catch (InvalidArgumentException $e) {
            Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        ActivityLog::record([
            'action'         => $id > 0 ? 'admin.identity_update' : 'admin.identity_create',
            'category'       => ActivityLog::CAT_ADMIN,
            'actor_type'     => 'admin',
            'actor_id'       => (int)$admin['id'],
            'actor_username' => (string)$admin['username'],
            'target_type'    => 'identity_provider',
            'target_id'      => (string)($provider['id'] ?? ''),
            'target_name'    => (string)($provider['name'] ?? ''),
            'summary'        => ($id > 0 ? '更新第三方登录：' : '添加第三方登录：') . ($provider['name'] ?? ''),
        ]);

        $hasSecret = (string)($provider['client_secret'] ?? '') !== '';
        unset($provider['client_secret']);
        $provider['callback_url'] = IdentityProviders::callbackUrl((string)$provider['slug']);
        $provider['has_secret'] = $hasSecret;

        Response::json([
            'success'  => true,
            'message'  => $id > 0 ? '已保存' : '已添加',
            'provider' => $provider,
        ]);
    }

    public function delete(): void
    {
        $admin = Admin::requireAdmin();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => '请求无效，请重试'], 422);
        }

        $id = (int)($_POST['id'] ?? 0);
        $provider = IdentityProviders::findById($id);
        if ($provider === null) {
            Response::json(['success' => false, 'message' => '提供方不存在'], 404);
        }

        IdentityProviders::delete($id);

        ActivityLog::record([
            'action'         => 'admin.identity_delete',
            'category'       => ActivityLog::CAT_ADMIN,
            'actor_type'     => 'admin',
            'actor_id'       => (int)$admin['id'],
            'actor_username' => (string)$admin['username'],
            'target_type'    => 'identity_provider',
            'target_id'      => (string)$id,
            'target_name'    => (string)$provider['name'],
            'summary'        => '删除第三方登录：' . $provider['name'],
        ]);

        Response::json(['success' => true, 'message' => '已删除']);
    }

    public function toggle(): void
    {
        $admin = Admin::requireAdmin();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => '请求无效，请重试'], 422);
        }

        $id = (int)($_POST['id'] ?? 0);
        $provider = IdentityProviders::findById($id);
        if ($provider === null) {
            Response::json(['success' => false, 'message' => '提供方不存在'], 404);
        }

        $enabled = (int)$provider['enabled'] !== 1;
        IdentityProviders::setEnabled($id, $enabled);

        ActivityLog::record([
            'action'         => 'admin.identity_toggle',
            'category'       => ActivityLog::CAT_ADMIN,
            'actor_type'     => 'admin',
            'actor_id'       => (int)$admin['id'],
            'actor_username' => (string)$admin['username'],
            'target_type'    => 'identity_provider',
            'target_id'      => (string)$id,
            'target_name'    => (string)$provider['name'],
            'summary'        => ($enabled ? '启用' : '停用') . '第三方登录：' . $provider['name'],
        ]);

        Response::json([
            'success' => true,
            'message' => $enabled ? '已启用' : '已停用',
            'enabled' => $enabled ? 1 : 0,
        ]);
    }
}

<?php
namespace Nomen\Controllers;

use InvalidArgumentException;
use Nomen\Src\{ActivityLog, Admin, Csrf, IdentityProviders, Response};

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
            'success'   => true,
            'providers' => $providers,
            'presets'   => IdentityProviders::presets(),
        ]);
    }

    public function save(): void
    {
        $admin = Admin::requireAdmin();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => '请求无效，请重试'], 422);
        }

        $id = (int)($_POST['id'] ?? 0);

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

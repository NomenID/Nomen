<?php
namespace Nomen\Controllers;

use InvalidArgumentException;
use Nomen\Src\{ActivityLog, Admin, Csrf, Hooks, IconUpload, OAuthClients, Response};

/**
 * 管理后台的应用管理：不装开放平台插件也能把接入配好。
 * 所有 GET 走 /api/admin/apps*，动作走原路径并回 JSON。
 */
class AdminAppsController
{
    public function index(): void
    {
        Admin::requireAdmin();

        Response::json([
            'success' => true,
            'apps'    => Hooks::filter('admin.apps.list', OAuthClients::all()),
        ]);
    }

    public function create(): void
    {
        Admin::requireAdmin();

        Response::json([
            'success'         => true,
            'app'             => null,
            'appScopes'       => ['openid'],
            'redirectUris'    => [],
            'availableScopes' => OAuthClients::scopes(),
        ]);
    }

    public function store(): void
    {
        $admin = Admin::requireAdmin();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => '请求无效，请重试'], 422);
        }

        $icon = null;
        if (!empty($_FILES['icon']['name'])) {
            $icon = IconUpload::store($_FILES['icon']);
            if ($icon === null) {
                Response::json(['success' => false, 'message' => '图标上传失败，请检查文件格式和大小'], 422);
            }
        }

        try {
            $created = OAuthClients::create($_POST, (int)$admin['id'], null, $icon);
        } catch (InvalidArgumentException $e) {
            Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        ActivityLog::record([
            'action'        => 'admin.app_create',
            'category'      => ActivityLog::CAT_DEV,
            'actor_type'    => 'admin',
            'actor_id'      => (int)$admin['id'],
            'actor_username'=> (string)$admin['username'],
            'target_type'   => 'oauth_client',
            'target_id'     => $created['client_id'],
            'target_name'   => $created['name'],
            'summary'       => '管理员创建应用「' . $created['name'] . '」',
        ]);

        Response::json([
            'success'       => true,
            'message'       => '应用已创建，密钥只显示这一次',
            'client_id'     => $created['client_id'],
            'client_secret' => $created['client_secret'],
        ]);
    }

    public function show(string $clientId): void
    {
        Admin::requireAdmin();
        $app = OAuthClients::find($clientId);

        if (!$app) {
            Response::json(['success' => false, 'message' => '应用不存在'], 404);
        }

        $redirectUris = json_decode((string)$app['redirect_uris'], true) ?: [];
        $scopes = preg_split('/[\s,]+/', trim((string)$app['scopes']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $isPublic = ($app['client_type'] ?? 'confidential') === 'public';
        $pkce = $isPublic || (int)($app['pkce_required'] ?? 0) === 1;

        Response::json([
            'success'         => true,
            'app'             => Hooks::filter('admin.apps.detail', $app, $clientId),
            'redirectUris'    => array_values($redirectUris),
            'appScopes'       => array_values($scopes),
            'availableScopes' => OAuthClients::scopes(),
            'stats'           => OAuthClients::stats($clientId),
            'isPublic'        => $isPublic,
            'pkceOn'          => $pkce,
            'authorizeSample' => OAuthClients::authorizeSample($app, array_values($redirectUris), $pkce),
            'tokenSample'     => OAuthClients::tokenSample($app, array_values($redirectUris), $pkce),
        ]);
    }

    public function update(string $clientId): void
    {
        $admin = Admin::requireAdmin();
        $app = OAuthClients::find($clientId);

        if (!$app) {
            Response::json(['success' => false, 'message' => '应用不存在'], 404);
        }
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => '请求无效，请重试'], 422);
        }

        $icon = null;
        if (!empty($_FILES['icon']['name'])) {
            $icon = IconUpload::store($_FILES['icon']);
            if ($icon === null) {
                Response::json(['success' => false, 'message' => '图标上传失败，请检查文件格式和大小'], 422);
            }
        }

        try {
            $data = OAuthClients::update($clientId, $_POST, $icon);
        } catch (InvalidArgumentException $e) {
            Response::json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $after = array_merge($app, $data);
        if ($icon !== null) {
            $after['icon'] = $icon;
        }
        $beforeFields = OAuthClients::logFields($app);
        $afterFields  = OAuthClients::logFields($after);

        ActivityLog::record([
            'action'        => 'admin.app_update',
            'category'      => ActivityLog::CAT_DEV,
            'actor_type'    => 'admin',
            'actor_id'      => (int)$admin['id'],
            'actor_username'=> (string)$admin['username'],
            'target_type'   => 'oauth_client',
            'target_id'     => $clientId,
            'target_name'   => (string)$app['name'],
            'summary'       => '管理员更新应用「' . $app['name'] . '」',
            'changes'       => ActivityLog::diff($beforeFields, $afterFields, array_keys($beforeFields)),
        ]);

        Response::json(['success' => true, 'message' => '应用信息已更新']);
    }

    public function resetSecret(string $clientId): void
    {
        $admin = Admin::requireAdmin();
        $app = OAuthClients::find($clientId);

        if (!$app) {
            Response::json(['success' => false, 'message' => '应用不存在'], 404);
        }
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => '请求无效，请重试'], 422);
        }
        if (($app['client_type'] ?? 'confidential') === 'public') {
            Response::json(['success' => false, 'message' => '公共客户端不使用 Client Secret，无需重置'], 422);
        }

        $secret = OAuthClients::resetSecret($clientId);

        ActivityLog::record([
            'action'        => 'admin.app_reset_secret',
            'category'      => ActivityLog::CAT_DEV,
            'actor_type'    => 'admin',
            'actor_id'      => (int)$admin['id'],
            'actor_username'=> (string)$admin['username'],
            'target_type'   => 'oauth_client',
            'target_id'     => $clientId,
            'target_name'   => (string)$app['name'],
            'summary'       => '管理员重置应用「' . $app['name'] . '」的密钥',
        ]);

        Response::json([
            'success'       => true,
            'message'       => '密钥已重置，旧令牌已作废',
            'client_secret' => $secret,
        ]);
    }

    public function toggle(string $clientId): void
    {
        $admin = Admin::requireAdmin();
        $app = OAuthClients::find($clientId);

        if (!$app) {
            Response::json(['success' => false, 'message' => '应用不存在'], 404);
        }
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => '请求无效，请重试'], 422);
        }

        $status = (int)($app['status'] ?? 1) === 1 ? 0 : 1;
        OAuthClients::setStatus($clientId, $status);

        ActivityLog::record([
            'action'        => 'admin.app_toggle',
            'category'      => ActivityLog::CAT_DEV,
            'actor_type'    => 'admin',
            'actor_id'      => (int)$admin['id'],
            'actor_username'=> (string)$admin['username'],
            'target_type'   => 'oauth_client',
            'target_id'     => $clientId,
            'target_name'   => (string)$app['name'],
            'summary'       => ($status === 1 ? '启用' : '停用') . '应用「' . $app['name'] . '」',
            'changes'       => ['status' => [
                'old' => (int)$app['status'] === 1 ? '启用' : '停用',
                'new' => $status === 1 ? '启用' : '停用',
            ]],
        ]);

        Response::json([
            'success' => true,
            'message' => $status === 1 ? '应用已启用' : '应用已停用',
            'status'  => $status,
        ]);
    }

    public function delete(string $clientId): void
    {
        $admin = Admin::requireAdmin();
        $app = OAuthClients::find($clientId);

        if (!$app) {
            Response::json(['success' => false, 'message' => '应用不存在'], 404);
        }
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => '请求无效，请重试'], 422);
        }

        OAuthClients::delete($clientId);

        ActivityLog::record([
            'action'        => 'admin.app_delete',
            'category'      => ActivityLog::CAT_DEV,
            'actor_type'    => 'admin',
            'actor_id'      => (int)$admin['id'],
            'actor_username'=> (string)$admin['username'],
            'target_type'   => 'oauth_client',
            'target_id'     => $clientId,
            'target_name'   => (string)$app['name'],
            'summary'       => '管理员删除应用「' . $app['name'] . '」',
        ]);

        Response::json(['success' => true, 'message' => '应用已删除']);
    }
}

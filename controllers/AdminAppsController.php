<?php
namespace Nomen\Controllers;

use InvalidArgumentException;
use Nomen\Src\{ActivityLog, Admin, Csrf, IconUpload, OAuthClients, Response, Session};

/**
 * 管理后台的应用管理：不装开放平台插件也能把接入配好。
 */
class AdminAppsController
{
    public function index(): void
    {
        $admin = Admin::requireAdmin();

        Response::view('admin/apps', [
            'layout'     => 'admin',
            'pageTitle'  => '应用',
            'activeMenu' => 'apps',
            'admin'      => $admin,
            'apps'       => OAuthClients::all(),
            'success'    => (string)(Session::getFlash('success') ?: ''),
            'error'      => (string)(Session::getFlash('error') ?: ''),
        ]);
    }

    public function create(): void
    {
        Admin::requireAdmin();

        $this->form(null);
    }

    public function store(): void
    {
        $admin = Admin::requireAdmin();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Session::flash('error', '请求无效，请重试');
            Response::redirect('/admin/apps/create');
        }

        $icon = null;
        if (!empty($_FILES['icon']['name'])) {
            $icon = IconUpload::store($_FILES['icon']);
            if ($icon === null) {
                Session::flash('error', '图标上传失败，请检查文件格式和大小');
                Response::redirect('/admin/apps/create');
            }
        }

        try {
            $created = OAuthClients::create($_POST, (int)$admin['id'], null, $icon);
        } catch (InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
            Response::redirect('/admin/apps/create');
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

        Session::flash('new_client_id', $created['client_id']);
        Session::flash('new_client_secret', $created['client_secret']);
        Session::flash('success', '应用已创建，密钥只显示这一次');

        Response::redirect('/admin/apps/' . $created['client_id']);
    }

    public function show(string $clientId): void
    {
        $admin = Admin::requireAdmin();
        $app = OAuthClients::find($clientId);

        if (!$app) {
            Response::error(404, '应用不存在');
            return;
        }

        $redirectUris = json_decode((string)$app['redirect_uris'], true) ?: [];
        $scopes = preg_split('/[\s,]+/', trim((string)$app['scopes']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $isPublic = ($app['client_type'] ?? 'confidential') === 'public';
        $pkce = $isPublic || (int)($app['pkce_required'] ?? 0) === 1;

        Response::view('admin/app-detail', [
            'layout'            => 'admin',
            'pageTitle'         => '应用：' . $app['name'],
            'activeMenu'        => 'apps',
            'admin'             => $admin,
            'app'               => $app,
            'redirectUris'      => array_values($redirectUris),
            'appScopes'         => array_values($scopes),
            'availableScopes'   => OAuthClients::scopes(),
            'stats'             => OAuthClients::stats($clientId),
            'isPublic'          => $isPublic,
            'pkceOn'            => $pkce,
            'authorizeSample'   => OAuthClients::authorizeSample($app, array_values($redirectUris), $pkce),
            'tokenSample'       => OAuthClients::tokenSample($app, array_values($redirectUris), $pkce),
            'newClientId'       => (string)(Session::getFlash('new_client_id') ?: ''),
            'newClientSecret'   => (string)(Session::getFlash('new_client_secret') ?: ''),
            'success'           => (string)(Session::getFlash('success') ?: ''),
            'error'             => (string)(Session::getFlash('error') ?: ''),
        ]);
    }

    public function update(string $clientId): void
    {
        $admin = Admin::requireAdmin();
        $app = OAuthClients::find($clientId);

        if (!$app) {
            Response::error(404, '应用不存在');
            return;
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Session::flash('error', '请求无效，请重试');
            Response::redirect('/admin/apps/' . $clientId);
        }

        $icon = null;
        if (!empty($_FILES['icon']['name'])) {
            $icon = IconUpload::store($_FILES['icon']);
            if ($icon === null) {
                Session::flash('error', '图标上传失败，请检查文件格式和大小');
                Response::redirect('/admin/apps/' . $clientId);
            }
        }

        try {
            $data = OAuthClients::update($clientId, $_POST, $icon);
        } catch (InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
            Response::redirect('/admin/apps/' . $clientId);
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

        Session::flash('success', '应用信息已更新');
        Response::redirect('/admin/apps/' . $clientId);
    }

    public function resetSecret(string $clientId): void
    {
        $admin = Admin::requireAdmin();
        $app = OAuthClients::find($clientId);

        if (!$app) {
            Response::error(404, '应用不存在');
            return;
        }
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Session::flash('error', '请求无效，请重试');
            Response::redirect('/admin/apps/' . $clientId);
        }
        if (($app['client_type'] ?? 'confidential') === 'public') {
            Session::flash('error', '公共客户端不使用 Client Secret，无需重置');
            Response::redirect('/admin/apps/' . $clientId);
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

        Session::flash('new_client_id', $clientId);
        Session::flash('new_client_secret', $secret);
        Session::flash('success', '密钥已重置，旧令牌已作废');
        Response::redirect('/admin/apps/' . $clientId);
    }

    public function toggle(string $clientId): void
    {
        $admin = Admin::requireAdmin();
        $app = OAuthClients::find($clientId);

        if (!$app) {
            Response::error(404, '应用不存在');
            return;
        }
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Session::flash('error', '请求无效，请重试');
            Response::redirect('/admin/apps/' . $clientId);
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

        Session::flash('success', $status === 1 ? '应用已启用' : '应用已停用');
        Response::redirect('/admin/apps/' . $clientId);
    }

    public function delete(string $clientId): void
    {
        $admin = Admin::requireAdmin();
        $app = OAuthClients::find($clientId);

        if (!$app) {
            Response::error(404, '应用不存在');
            return;
        }
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Session::flash('error', '请求无效，请重试');
            Response::redirect('/admin/apps/' . $clientId);
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

        Session::flash('success', '应用已删除');
        Response::redirect('/admin/apps');
    }

    private function form(?array $app): void
    {
        $scopes = $app ? preg_split('/[\s,]+/', trim((string)$app['scopes']), -1, PREG_SPLIT_NO_EMPTY) : ['openid'];
        $redirectUris = $app ? (json_decode((string)$app['redirect_uris'], true) ?: []) : [];

        Response::view('admin/app-form', [
            'layout'          => 'admin',
            'pageTitle'       => $app ? '编辑应用' : '新建应用',
            'activeMenu'      => 'apps',
            'app'             => $app,
            'appScopes'       => array_values($scopes ?: ['openid']),
            'redirectUris'    => array_values($redirectUris),
            'availableScopes' => OAuthClients::scopes(),
            'error'           => (string)(Session::getFlash('error') ?: ''),
        ]);
    }
}

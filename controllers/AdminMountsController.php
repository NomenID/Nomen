<?php
namespace Nomen\Controllers;

use Nomen\Src\{ActivityLog, Admin, Brand, Csrf, Plugins, Response, Session};

class AdminMountsController
{
    public function index(): void
    {
        $admin = Admin::requireAdmin();
        $base = rtrim((string)Brand::get('base_url'), '/');

        $endpoints = [
            ['授权', '/oauth/authorize', '浏览器跳转到这里，用户确认后带 code 回到回调地址'],
            ['令牌', '/oauth/token', '用 code 换 access_token / refresh_token'],
            ['用户信息', '/api/userinfo', '带 Bearer token 读取 sub、用户名、昵称、头像'],
            ['令牌校验', '/api/token-info', '校验 token 是否有效'],
            ['撤销', '/oauth/revoke', '撤销 access_token 或 refresh_token'],
        ];

        Response::view('admin/mounts', [
            'layout'     => 'admin',
            'pageTitle'  => '接入点',
            'activeMenu' => 'mounts',
            'admin'      => $admin,
            'base'       => $base,
            'endpoints'  => $endpoints,
            'mounts'     => Plugins::mounts(),
            'success'    => (string)(Session::getFlash('success') ?: ''),
            'error'      => (string)(Session::getFlash('error') ?: ''),
        ]);
    }

    public function updatePath(string $plugin): void
    {
        $admin = Admin::requireAdmin();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Session::flash('error', '请求无效，请重试');
            Response::redirect('/admin/mounts');
        }

        $id = trim((string)($_POST['mount_id'] ?? ''));
        $key = trim((string)($_POST['config_key'] ?? ''));
        $path = trim((string)($_POST['path'] ?? ''));

        $mount = null;
        foreach (Plugins::mounts() as $item) {
            if ($item['plugin'] === $plugin && $item['id'] === $id) {
                $mount = $item;
                break;
            }
        }

        if ($mount === null || empty($mount['config']) || $key !== $mount['config']) {
            Session::flash('error', '这个挂载点不支持在后台改路径');
            Response::redirect('/admin/mounts');
        }

        if ($path === '' || !preg_match('#^/[a-z0-9][a-z0-9\-/]*$#', $path) || str_contains($path, '//')) {
            Session::flash('error', '路径要以 / 开头，只能用小写字母、数字、连字符和斜杠');
            Response::redirect('/admin/mounts');
        }

        $path = '/' . trim($path, '/');

        if (!Plugins::setConfig($plugin, [$key => $path])) {
            Session::flash('error', '写不进 config/plugins/，检查目录权限');
            Response::redirect('/admin/mounts');
        }

        ActivityLog::record([
            'action'        => 'admin.mount_update',
            'category'      => ActivityLog::CAT_ADMIN,
            'actor_type'    => 'admin',
            'actor_id'      => (int)$admin['id'],
            'actor_username'=> (string)$admin['username'],
            'target_type'   => 'plugin_mount',
            'target_id'     => $plugin . ':' . $id,
            'target_name'   => (string)$mount['title'],
            'summary'       => '把 ' . $mount['title'] . ' 的挂载点改成 ' . $path,
        ]);

        Session::flash('success', '挂载点已改到 ' . $path . '，下一次请求生效');
        Response::redirect('/admin/mounts');
    }
}

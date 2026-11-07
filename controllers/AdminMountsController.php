<?php
namespace Nomen\Controllers;

use Nomen\Src\{ActivityLog, Admin, Brand, Csrf, Hooks, Plugins, Response};

class AdminMountsController
{
    public function index(): void
    {
        Admin::requireAdmin();
        $base = rtrim((string)Brand::get('base_url'), '/');

        $endpoints = [
            ['label' => 'Authorize', 'path' => '/oauth/authorize', 'description' => '浏览器跳转到这里，用户确认后带 code 回到回调地址'],
            ['label' => 'Token', 'path' => '/oauth/token', 'description' => '用 code 换 access_token / refresh_token'],
            ['label' => 'User info', 'path' => '/api/userinfo', 'description' => '带 Bearer token 读取 sub、用户名、昵称、头像'],
            ['label' => 'Token verification', 'path' => '/api/token-info', 'description' => '校验 token 是否有效'],
            ['label' => 'Revoke', 'path' => '/oauth/revoke', 'description' => '撤销 access_token 或 refresh_token'],
        ];

        Response::json([
            'success'   => true,
            'base'      => $base,
            'endpoints' => $endpoints,
            'mounts'    => Hooks::filter('admin.mounts.list', Plugins::mounts()),
        ]);
    }

    public function updatePath(string $plugin): void
    {
        $admin = Admin::requireAdmin();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => '请求无效，请重试'], 422);
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
            Response::json(['success' => false, 'message' => '这个挂载点不支持在后台改路径'], 422);
        }

        if ($path === '' || !preg_match('#^/[a-z0-9][a-z0-9\-/]*$#', $path) || str_contains($path, '//')) {
            Response::json(['success' => false, 'message' => '路径要以 / 开头，只能用小写字母、数字、连字符和斜杠'], 422);
        }

        $path = '/' . trim($path, '/');

        if (!Plugins::setConfig($plugin, [$key => $path])) {
            Response::json(['success' => false, 'message' => '写不进 config/plugins/，检查目录权限'], 500);
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

        Response::json([
            'success' => true,
            'message' => '挂载点已改到 ' . $path . '，下一次请求生效',
            'path'    => $path,
        ]);
    }
}

<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Controllers;

use Nomen\Src\{ActivityLog, Admin, Csrf, Response, SiteHealth};

class SiteHealthController
{
    /** GET /api/admin/site-health */
    public function index(): void
    {
        Admin::requireAdmin();

        Response::json(['success' => true] + SiteHealth::run());
    }

    /** POST /admin/site-health/ignore */
    public function ignore(): void
    {
        $this->toggle(true);
    }

    /** POST /admin/site-health/unignore */
    public function unignore(): void
    {
        $this->toggle(false);
    }

    private function toggle(bool $ignore): void
    {
        $admin = Admin::requireAdmin();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => '请求无效，请重试'], 422);
        }

        $id = trim((string)($_POST['id'] ?? ''));
        if (!($ignore ? SiteHealth::ignoreId($id) : SiteHealth::unignoreId($id))) {
            Response::json(['success' => false, 'message' => t('Unknown check.')], 422);
        }

        ActivityLog::record([
            'action'         => $ignore ? 'admin.site_health_ignore' : 'admin.site_health_unignore',
            'category'       => ActivityLog::CAT_ADMIN,
            'actor_type'     => 'admin',
            'actor_id'       => (int)$admin['id'],
            'actor_username' => (string)$admin['username'],
            'summary'        => ($ignore ? '忽略站点健康检查：' : '恢复站点健康检查：') . $id,
        ]);

        Response::json([
            'success' => true,
            'ignored' => SiteHealth::ignoredIds(),
        ]);
    }
}

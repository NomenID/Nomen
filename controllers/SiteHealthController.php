<?php
namespace Nomen\Controllers;

use Nomen\Src\{Admin, Response, SiteHealth};

class SiteHealthController
{
    /** GET /api/admin/site-health */
    public function index(): void
    {
        Admin::requireAdmin();

        Response::json(['success' => true] + SiteHealth::run());
    }
}

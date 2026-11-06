<?php
namespace Nomen\Controllers;

use Nomen\Src\{AppData, Database, Session, Response, NotificationHelper};
use PDO;

class NotificationController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        if (!Session::get('user_id')) {
            Response::redirect('/login?redirect_to=' . urlencode($_SERVER['REQUEST_URI']));
        }
    }

    public function index(): void
    {
        $userId = (int)Session::get('user_id');
        $page = max(1, (int)($_GET['page'] ?? 1));

        // 真 SPA：壳只出挂载点 + props（AppData 与 /api/app 端点共用同一份构建）
        $appProps = AppData::shell($userId, AppData::notifications($userId, $page));
        Response::view('app-shell', ['appProps' => $appProps, 'title' => t('消息中心')], 'app');
    }

    public function show($id): void
    {
        $userId = (int)Session::get('user_id');
        $id = (int)$id;
        $data = AppData::notificationDetail($userId, $id);

        if (!$data) {
            Response::error(404, t('消息不存在'));
        }

        $appProps = AppData::shell($userId, $data);
        Response::view('app-shell', ['appProps' => $appProps, 'title' => t('消息详情')], 'app');
    }

    public function readAll(): void
    {
        if (!\Nomen\Src\Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => t('请求无效')]);
        }

        $userId = (int)Session::get('user_id');
        NotificationHelper::markAllRead($userId);

        Response::json(['success' => true, 'message' => t('全部已读')]);
    }

    public function readOne(): void
    {
        if (!\Nomen\Src\Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => t('请求无效')]);
        }

        $userId = (int)Session::get('user_id');
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            Response::json(['success' => false, 'message' => t('参数无效')]);
        }

        NotificationHelper::markRead($userId, $id);

        Response::json(['success' => true]);
    }
}

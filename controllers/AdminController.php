<?php
namespace Nomen\Controllers;

use Nomen\Src\{ActivityLog, Csrf, Database, Email, EmailTemplate, FamilyHelper, Features, Hooks, I18n, Plugins, RecoveryHelper, RedisClient, Response, Session, TextDiff};
use PDO;

/**
 * 管理后台。每个方法首行都做一次 requireAdmin()：
 * Router 的 admin 中间件是第一道闸，这里是第二道，避免将来漏配中间件就裸奔。
 */
class AdminController
{
    private const PER_PAGE = 30;
    private const LOGS_PER_PAGE = 50;

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }

    private static function config(): array
    {
        return require __DIR__ . '/../config/app.php';
    }

    private function requireAdmin(): array
    {
        $userId = (int)Session::get('user_id');
        $stmt = $this->db->prepare('SELECT id, username, role FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $admin = $stmt->fetch();

        if (!$admin || $admin['role'] !== 'admin') {
            Response::error(403);
        }
        return $admin;
    }

    private static function isAjax(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * 是否管理员（查库判定，供导航等处复用）
     */
    public static function isAdminUser(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $stmt = Database::getInstance()->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        return $stmt->fetchColumn() === 'admin';
    }

    // ========== 概览 ==========

    public function index(): void
    {
        $admin = $this->requireAdmin();

        $today = $this->db->quote(date('Y-m-d'));
        $stats = $this->db->query(
            'SELECT
                (SELECT COUNT(*) FROM users) AS total_users,
                (SELECT COUNT(*) FROM users WHERE created_at >= ' . $today . ') AS new_users_today,
                (SELECT COUNT(*) FROM users WHERE email_verified = 1) AS verified_users,
                (SELECT COUNT(*) FROM users WHERE status != 1) AS disabled_users,
                (SELECT COUNT(*) FROM users WHERE role = \'admin\') AS admins,
                (SELECT COUNT(*) FROM account_recovery_requests WHERE status = \'pending\') AS pending_recovery,
                (SELECT COUNT(*) FROM account_recovery_requests WHERE status = \'approved\') AS approved_recovery,
                (SELECT COUNT(*) FROM user_devices WHERE last_seen_at >= ' . Database::dialectFor($this->db)->nowMinusDays(30) . ') AS active_devices,
                (SELECT COUNT(*) FROM oauth_clients WHERE status = 1) AS active_apps,
                (SELECT COUNT(*) FROM family_groups WHERE status = \'unclaimed\') AS unclaimed_families,
                (SELECT COUNT(*) FROM family_members WHERE kind = \'child\' AND status IN (\'pending\', \'active\')) AS managed_children,
                (SELECT COUNT(*) FROM user_authorizations) AS total_authorizations'
        )->fetch();

        Response::view('admin/dashboard', [
            'layout'      => 'admin',
            'pageTitle'   => '概览',
            'activeMenu'  => 'dashboard',
            'admin'       => $admin,
            'stats'       => $stats,
            'redis'       => self::redisHealth(),
            'recentLogs'  => ActivityLog::listFor([], 1, 10)['items'],
            // 安装向导没删就一直提醒：它留着谁都能重跑安装
            'installerPresent' => is_file(dirname(__DIR__) . '/public/nomen-install/index.php'),
        ]);
    }

    /**
     * Redis 状态。
     *
     * 限流和会话都压在它身上，而它出问题时恰恰是「静默」的 —— 限流照旧放行、
     * 登录状态莫名丢失，页面上看不到任何异常。所以把关键几项摆到概览页上。
     */
    private static function redisHealth(): array
    {
        $client = RedisClient::get();
        if ($client === null) {
            return ['ok' => false, 'error' => '连接失败，或 phpredis 扩展没装（日志里有具体原因）'];
        }

        try {
            $memory = $client->info('memory');
            $server = $client->info('server');

            $used = (int)($memory['used_memory'] ?? 0);
            $max  = (int)($memory['maxmemory'] ?? 0);
            $percent = $max > 0 ? (int)round($used / $max * 100) : null;

            return [
                'ok'      => true,
                'warning' => $percent !== null && $percent >= 80,
                'version' => (string)($server['redis_version'] ?? '未知'),
                'used'    => (string)($memory['used_memory_human'] ?? ''),
                'max'     => $max > 0 ? (string)($memory['maxmemory_human'] ?? '') : '未限制',
                'percent' => $percent,
                'policy'  => (string)($memory['maxmemory_policy'] ?? ''),
                'keys'    => (int)$client->dbSize(),
                'index'   => Session::indexReady(),
            ];
        } catch (\Throwable $e) {
            error_log('[redis] 后台状态读取失败: ' . $e->getMessage());
            return ['ok' => false, 'error' => '读取状态失败：' . $e->getMessage()];
        }
    }

    // ========== 用户 ==========

    public function users(): void
    {
        $admin = $this->requireAdmin();

        $search = trim((string)($_GET['q'] ?? ''));
        $filter = (string)($_GET['filter'] ?? '');
        $page   = max(1, (int)($_GET['page'] ?? 1));

        $where = ['1=1'];
        $params = [];
        if ($search !== '') {
            $where[] = '(username LIKE :kw OR display_name LIKE :kw2 OR email LIKE :kw3)';
            $kw = '%' . $search . '%';
            $params[':kw'] = $kw;
            $params[':kw2'] = $kw;
            $params[':kw3'] = $kw;
        }
        if ($filter === 'admin') {
            $where[] = "role = 'admin'";
        } elseif ($filter === 'disabled') {
            $where[] = 'status != 1';
        } elseif ($filter === 'unverified') {
            $where[] = 'email_verified = 0';
        }
        $whereSql = implode(' AND ', $where);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE {$whereSql}");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $offset = ($page - 1) * self::PER_PAGE;
        $stmt = $this->db->prepare(
            "SELECT id, username, display_name, email, email_verified, role, status, country,
                    birth_date, gender, created_at, last_login_at, last_login_ip,
                    (SELECT COUNT(*) FROM user_biometrics b WHERE b.user_id = users.id AND b.revoked = 0) AS biometric_count,
                    (SELECT COUNT(*) FROM user_totp_secrets t WHERE t.user_id = users.id AND t.verified = 1) AS totp_count,
                    (SELECT COUNT(*) FROM user_recovery_codes c WHERE c.user_id = users.id AND c.used_at IS NULL) AS recovery_code_count,
                    (SELECT COUNT(*) FROM user_authorizations a WHERE a.user_id = users.id) AS app_count
             FROM users WHERE {$whereSql}
             ORDER BY created_at DESC LIMIT :lim OFFSET :off"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':lim', self::PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll();

        foreach ($users as &$user) {
            // 生物识别是快速辅助验证方式，不计入登录方式数量
            $user['login_method_count'] = array_sum([
                !empty($user['email_verified']) ? 1 : 0,
                (int)$user['totp_count'] > 0 ? 1 : 0,
                (int)$user['recovery_code_count'] > 0 ? 1 : 0,
                1, // 密码恒定存在
            ]);
        }
        unset($user);

        Response::view('admin/users', [
            'layout'     => 'admin',
            'pageTitle'  => '用户管理',
            'activeMenu' => 'users',
            'admin'      => $admin,
            'users'      => $users,
            'search'     => $search,
            'filter'     => $filter,
            'pagination' => ['page' => $page, 'pages' => max(1, (int)ceil($total / self::PER_PAGE)), 'total' => $total],
            'query'      => array_filter(['q' => $search, 'filter' => $filter]),
        ]);
    }

    public function userDetail(string $id): void
    {
        $admin = $this->requireAdmin();
        $userId = (int)$id;

        $stmt = $this->db->prepare(
            'SELECT id, username, display_name, email, email_verified, role, status, country,
                    birth_date, gender, created_at, last_login_at, last_login_ip, two_factor_policy, quick_login
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) {
            Response::error(404, '用户不存在');
        }

        $groups = RecoveryHelper::groupedMethods($userId);

        $stmt = $this->db->prepare(
            'SELECT ua.created_at, oc.name AS client_name, ua.client_id, ua.scopes
             FROM user_authorizations ua
             LEFT JOIN oauth_clients oc ON oc.client_id = ua.client_id
             WHERE ua.user_id = :u ORDER BY ua.created_at DESC'
        );
        $stmt->execute([':u' => $userId]);
        $authorizations = $stmt->fetchAll();

        $stmt = $this->db->prepare(
            'SELECT * FROM account_recovery_requests WHERE user_id = :u ORDER BY created_at DESC'
        );
        $stmt->execute([':u' => $userId]);
        $recoveryHistory = $stmt->fetchAll();

        // 家庭组：这个账号的孩子视角（它在哪个组）和监护人视角（它管着哪些组）
        $membership = Features::guardians() ? FamilyHelper::childMembership($userId) : null;
        $familyChildren = [];
        $familyChildrenByGroup = [];
        if (Features::guardians()) {
            foreach (FamilyHelper::groupsOfGuardian($userId) as $familyGroup) {
                $familyChildren[] = $familyGroup;
                $familyChildrenByGroup[(int)$familyGroup['id']] = FamilyHelper::childrenOf((int)$familyGroup['id']);
            }
        }

        Response::view('admin/user-detail', [
            'layout'       => 'admin',
            'pageTitle'    => '用户 · ' . $user['username'],
            'activeMenu'   => 'users',
            'admin'        => $admin,
            'user'         => $user,
            'methods'      => $groups['login'],
            'assistMethods' => $groups['assist'],
            'authorizations' => $authorizations,
            'recoveryHistory' => $recoveryHistory,
            'membership'   => $membership,
            'familyGuardians' => $membership ? FamilyHelper::guardiansOf((int)$membership['group_id']) : [],
            'ownedGroups'  => $familyChildren,
            'ownedGroupsChildren' => $familyChildrenByGroup,
            'guardianEmail' => Features::guardians() ? FamilyHelper::guardianEmailFor($userId) : null,
            'recentLogs'   => ActivityLog::recentFor($userId, 20),
        ]);
    }

    public function toggleStatus(): void
    {
        $admin = $this->requireAdmin();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => '请求无效'], 403);
        }

        $userId = (int)($_POST['user_id'] ?? 0);
        $stmt = $this->db->prepare('SELECT id, username, role, status FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $target = $stmt->fetch();

        if (!$target) {
            Response::json(['success' => false, 'message' => '用户不存在']);
        }
        if ((int)$target['id'] === (int)$admin['id']) {
            Response::json(['success' => false, 'message' => '不能禁用你自己的账户']);
        }
        if ($target['role'] === 'admin' && (int)$target['status'] === 1) {
            Response::json(['success' => false, 'message' => '不能禁用其他管理员，请先撤销其管理员身份']);
        }

        $newStatus = (int)$target['status'] === 1 ? 0 : 1;
        $this->db->prepare('UPDATE users SET status = :s WHERE id = :id')
            ->execute([':s' => $newStatus, ':id' => $userId]);

        ActivityLog::record([
            'action'   => $newStatus === 1 ? 'admin.user_enable' : 'admin.user_disable',
            'category' => ActivityLog::CAT_ADMIN,
            'target_type' => 'user',
            'target_id' => $userId,
            'target_name' => $target['username'],
            'summary'  => ($newStatus === 1 ? '启用' : '禁用') . '用户 ' . $target['username'],
            'changes'  => ['status' => [
                'old' => (int)$target['status'] === 1 ? '启用' : '禁用',
                'new' => $newStatus === 1 ? '启用' : '禁用',
            ]],
        ]);

        Hooks::run('admin.user.status_changed', [(int)$userId, $newStatus, (int)$admin['id']]);

        Response::json([
            'success' => true,
            'message' => $newStatus === 1 ? '已启用该账户' : '已禁用该账户',
            'status'  => $newStatus,
        ]);
    }

    public function toggleAdmin(): void
    {
        $admin = $this->requireAdmin();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => '请求无效'], 403);
        }

        $userId = (int)($_POST['user_id'] ?? 0);
        $stmt = $this->db->prepare('SELECT id, username, role FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $target = $stmt->fetch();

        if (!$target) {
            Response::json(['success' => false, 'message' => '用户不存在']);
        }
        if ((int)$target['id'] === (int)$admin['id']) {
            Response::json(['success' => false, 'message' => '不能修改你自己的管理员身份']);
        }

        $newRole = $target['role'] === 'admin' ? 'user' : 'admin';
        $this->db->prepare('UPDATE users SET role = :r WHERE id = :id')
            ->execute([':r' => $newRole, ':id' => $userId]);

        ActivityLog::record([
            'action'   => $newRole === 'admin' ? 'admin.role_grant' : 'admin.role_revoke',
            'category' => ActivityLog::CAT_ADMIN,
            'target_type' => 'user',
            'target_id' => $userId,
            'target_name' => $target['username'],
            'summary'  => ($newRole === 'admin' ? '设为管理员：' : '撤销管理员：') . $target['username'],
            'changes'  => ['role' => [
                'old' => $target['role'] === 'admin' ? '管理员' : '普通用户',
                'new' => $newRole === 'admin' ? '管理员' : '普通用户',
            ]],
        ]);

        Hooks::run('admin.user.role_changed', [(int)$userId, $newRole, (int)$admin['id']]);

        Response::json([
            'success' => true,
            'message' => $newRole === 'admin' ? '已设为管理员' : '已撤销管理员身份',
            'role'    => $newRole,
        ]);
    }

    // ========== 恢复工单 ==========

    public function recovery(): void
    {
        $admin = $this->requireAdmin();

        $status = (string)($_GET['status'] ?? '');
        $search = trim((string)($_GET['q'] ?? ''));
        $page   = max(1, (int)($_GET['page'] ?? 1));

        $where = ['1=1'];
        $params = [];
        if (in_array($status, ['pending', 'approved', 'rejected', 'completed'], true)) {
            $where[] = 'r.status = :st';
            $params[':st'] = $status;
        }
        if ($search !== '') {
            $where[] = '(r.username LIKE :kw OR r.contact_email LIKE :kw2 OR r.account_email LIKE :kw3)';
            $kw = '%' . $search . '%';
            $params[':kw'] = $kw;
            $params[':kw2'] = $kw;
            $params[':kw3'] = $kw;
        }
        $whereSql = implode(' AND ', $where);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM account_recovery_requests r WHERE {$whereSql}");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT r.* FROM account_recovery_requests r WHERE {$whereSql}
             ORDER BY (r.status = 'pending') DESC, r.created_at DESC LIMIT :lim OFFSET :off"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':lim', self::PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(':off', ($page - 1) * self::PER_PAGE, PDO::PARAM_INT);
        $stmt->execute();

        Response::view('admin/recovery', [
            'layout'     => 'admin',
            'pageTitle'  => '恢复工单',
            'activeMenu' => 'recovery',
            'admin'      => $admin,
            'requests'   => $stmt->fetchAll(),
            'status'     => $status,
            'search'     => $search,
            'pagination' => ['page' => $page, 'pages' => max(1, (int)ceil($total / self::PER_PAGE)), 'total' => $total],
            'query'      => array_filter(['status' => $status, 'q' => $search]),
        ]);
    }

    public function recoveryDetail(string $id): void
    {
        $admin = $this->requireAdmin();

        $stmt = $this->db->prepare('SELECT * FROM account_recovery_requests WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int)$id]);
        $request = $stmt->fetch();
        if (!$request) {
            Response::error(404, '工单不存在');
        }

        $stmt = $this->db->prepare(
            'SELECT id, username, email, email_verified, status, birth_date, gender, created_at, last_login_at
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => (int)$request['user_id']]);
        $user = $stmt->fetch() ?: [];

        // 工单时间线只看得出「谁在什么时候用哪个应用登录过」这类信息；
        // oauth.token_issued 是接入方服务器换令牌记录的，会带出源站 IP，别放这儿（全局审计在 /admin/logs）
        $recentLogs = array_values(array_filter(
            ActivityLog::recentFor((int)$request['user_id'], 20),
            static fn(array $row) => $row['action'] !== 'oauth.token_issued'
        ));

        Response::view('admin/recovery-detail', [
            'layout'     => 'admin',
            'pageTitle'  => '工单 #' . (int)$request['id'],
            'activeMenu' => 'recovery',
            'admin'      => $admin,
            'request'    => $request,
            'user'       => $user,
            'attachments' => RecoveryHelper::listAttachments((int)$request['id']),
            'recentLogs' => $recentLogs,
            'linkTtlDays' => (int)ceil(((int)(self::config()['recovery_token_ttl'] ?? 604800)) / 86400),
        ]);
    }

    public function recoveryApprove(): void
    {
        $admin = $this->requireAdmin();
        $this->reviewRequest($admin, 'approve');
    }

    public function recoveryReject(): void
    {
        $admin = $this->requireAdmin();
        $this->reviewRequest($admin, 'reject');
    }

    public function recoveryResend(): void
    {
        $admin = $this->requireAdmin();
        $this->reviewRequest($admin, 'resend');
    }

    private function reviewRequest(array $admin, string $mode): void
    {
        $isAjax = self::isAjax();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $this->reviewFail($isAjax, '请求无效');
        }

        $requestId = (int)($_POST['request_id'] ?? 0);
        $note = trim((string)($_POST['admin_note'] ?? ''));

        $stmt = $this->db->prepare('SELECT * FROM account_recovery_requests WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $requestId]);
        $request = $stmt->fetch();

        if (!$request) {
            $this->reviewFail($isAjax, '工单不存在');
        }

        if ($mode === 'reject') {
            if ($note === '') {
                $this->reviewFail($isAjax, '请填写拒绝原因（记录在工单内，不会发给用户）');
            }

            $this->db->prepare(
                "UPDATE account_recovery_requests
                 SET status = 'rejected', admin_note = :note, reviewed_by = :by, reviewed_at = NOW(),
                     recovery_token_hash = NULL, recovery_token_expires_at = NULL
                 WHERE id = :id"
            )->execute([':note' => $note, ':by' => (int)$admin['id'], ':id' => $requestId]);

            ActivityLog::record([
                'action'   => 'recovery.rejected',
                'category' => ActivityLog::CAT_RECOVERY,
                'target_type' => 'recovery_request',
                'target_id' => $requestId,
                'target_name' => (string)$request['username'],
                'summary'  => '拒绝恢复申诉：' . mb_substr($note, 0, 120, 'UTF-8'),
            ]);

            $this->sendOutcomeEmail($request, 'reject');

            Session::flash('success', '已拒绝该申诉，并已通知申请人');
            Response::redirect('/admin/recovery/' . $requestId);
        }

        // approve / resend 都是重新签发一次性恢复链接（旧链接立刻失效）
        $ttl = (int)(self::config()['recovery_token_ttl'] ?? 604800);
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        $this->db->prepare(
            "UPDATE account_recovery_requests
             SET status = 'approved', admin_note = :note, reviewed_by = :by, reviewed_at = NOW(),
                 recovery_token_hash = :hash, recovery_token_expires_at = :expires, recovery_token_used_at = NULL
             WHERE id = :id"
        )->execute([
            ':note' => $note !== '' ? $note : ($request['admin_note'] ?? null),
            ':by' => (int)$admin['id'],
            ':hash' => hash('sha512', $token),
            ':expires' => $expiresAt,
            ':id' => $requestId,
        ]);

        ActivityLog::record([
            'action'   => $mode === 'resend' ? 'recovery.link_resent' : 'recovery.approved',
            'category' => ActivityLog::CAT_RECOVERY,
            'target_type' => 'recovery_request',
            'target_id' => $requestId,
            'target_name' => (string)$request['username'],
            'summary'  => ($mode === 'resend' ? '重发恢复链接给 ' : '通过恢复申诉：') . $request['username'],
        ]);

        $this->sendOutcomeEmail($request, 'approve', $token, $expiresAt);

        Session::flash('success', $mode === 'resend'
            ? '恢复链接已重新发送，旧链接已失效'
            : '已通过该申诉，恢复链接已发送');
        Response::redirect('/admin/recovery/' . $requestId);
    }

    private function reviewFail(bool $isAjax, string $message): void
    {
        if ($isAjax) {
            Response::json(['success' => false, 'message' => $message]);
        }
        Session::flash('error', $message);
        Response::redirect('/admin/recovery');
    }

    // ========== 活动日志 ==========

    public function logs(): void
    {
        $admin = $this->requireAdmin();
        $filter = self::logFilter();

        $page = max(1, (int)($_GET['page'] ?? 1));
        $result = ActivityLog::listFor($filter, $page, self::LOGS_PER_PAGE);

        $query = $filter;
        if (isset($query['keyword'])) {
            $query['q'] = $query['keyword'];
            unset($query['keyword']);
        }

        Response::view('admin/logs', [
            'layout'     => 'admin',
            'pageTitle'  => '活动日志',
            'activeMenu' => 'logs',
            'admin'      => $admin,
            'logs'       => $result['items'],
            'filter'     => $filter,
            'pagination' => ['page' => $result['page'], 'pages' => $result['pages'], 'total' => $result['total']],
            'query'      => $query,
        ]);
    }

    public function logDetail(string $id): void
    {
        $admin = $this->requireAdmin();
        $log = ActivityLog::find((int)$id);

        if ($log === null) {
            Response::error(404, '这条日志不存在');
        }

        Response::view('admin/log-detail', [
            'layout'     => 'admin',
            'pageTitle'  => '日志 #' . (int)$log['id'],
            'activeMenu' => 'logs',
            'admin'      => $admin,
            'log'        => $log,
        ]);
    }

    public function logExport(): void
    {
        $this->requireAdmin();

        $rows = ActivityLog::allFor(self::logFilter());

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="nomen-logs-' . date('Ymd-His') . '.csv"');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['编号', '时间', '操作者类型', '操作者 ID', '操作者', '日志类型', '动作', '动作代码',
            '目标类型', '目标 ID', '目标名称', '摘要', '变动', 'IP', '设备', 'User-Agent']);

        foreach ($rows as $row) {
            fputcsv($out, array_map(static fn($value): string => self::csvCell($value), [
                $row['id'],
                $row['created_at'],
                ActivityLog::actorLabel($row),
                $row['actor_id'],
                $row['actor_username'],
                ActivityLog::categoryLabel((string)$row['category']),
                ActivityLog::actionLabel((string)$row['action']),
                $row['action'],
                $row['target_type'] ? self::targetTypeLabel((string)$row['target_type']) : '',
                $row['target_id'],
                $row['target_name'],
                $row['summary'],
                self::changesText($row['changes'] ?? null),
                $row['ip_address'],
                $row['device_name'],
                $row['user_agent'],
            ]));
        }

        fclose($out);
        exit;
    }

    /** @return array<string, mixed> */
    private static function logFilter(): array
    {
        $from = (string)($_GET['from'] ?? '');
        $to   = (string)($_GET['to'] ?? '');

        return array_filter([
            'actor_type' => (string)($_GET['actor_type'] ?? ''),
            'category'   => (string)($_GET['category'] ?? ''),
            'action'     => (string)($_GET['action'] ?? ''),
            'actor_id'   => (int)($_GET['actor_id'] ?? 0),
            'actor'      => trim((string)($_GET['actor'] ?? '')),
            'target'     => trim((string)($_GET['target'] ?? '')),
            'keyword'    => trim((string)($_GET['q'] ?? '')),
            'from'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : '',
            'to'         => preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : '',
            'dir'        => ($_GET['dir'] ?? '') === 'newer' ? 'newer' : '',
        ], static fn($v) => $v !== '' && $v !== 0);
    }

    // ========== 模板辅助（供 admin 模板直接调用） ==========

    /**
     * 静默比对结果渲染：null 表示账号当时没有这项记录，不是填错
     */
    public static function matchBadge($value): string
    {
        if ($value === null || $value === '') {
            return '<span class="admin-badge admin-badge--muted">账号无记录</span>';
        }
        return (int)$value === 1
            ? '<span class="admin-badge admin-badge--success">匹配</span>'
            : '<span class="admin-badge admin-badge--danger">不匹配</span>';
    }

    private const FIELD_LABELS = [
        'username'           => '用户名',
        'display_name'       => '昵称',
        'bio'                => '简介',
        'email'              => '邮箱',
        'email_verified'     => '邮箱已验证',
        'country'            => '国家 / 地区',
        'birth_date'         => '出生日期',
        'gender'             => '性别',
        'avatar'             => '头像',
        'two_factor_policy'  => '两步验证策略',
        'quick_login'        => '快速登录',
        'notify_system_important' => '通知：系统重要',
        'notify_system_general'   => '通知：系统一般',
        'notify_system_interact'  => '通知：系统互动',
        'name'               => '名称',
        'icon'               => '图标',
        'description'        => '简介',
        'homepage_url'       => '主页',
        'redirect_uris'      => '回调 URL',
        'scopes'             => '授权范围',
        'client_type'        => '客户端类型',
        'pkce_required'      => '强制 PKCE',
        'status'             => '状态',
        'role'               => '角色',
        'slug'               => '地址',
        'title'              => '标题',
        'summary'            => '摘要',
        'sort_order'         => '排序',
        'body'               => '正文',
    ];

    private const TARGET_LABELS = [
        'user'             => '用户',
        'oauth_client'     => '应用',
        'recovery_request' => '恢复工单',
        'user_email'       => '邮箱',
        'temp_key'         => '临时密钥',
        'family_group'     => '家庭',
        'developer'        => '开发者',
        'recovery_code'    => '恢复码',
        'guardian_email'   => '家长邮箱',
        'device'           => '设备',
        'biometric'        => '生物识别',
        'plugin_mount'     => '挂载点',
        'avatar'           => '头像',
        'page'             => '页面',
    ];

    public static function targetTypeLabel(string $type): string
    {
        return self::TARGET_LABELS[$type] ?? $type;
    }

    public static function targetUrl(array $log): ?string
    {
        $type = (string)($log['target_type'] ?? '');
        $id   = (string)($log['target_id'] ?? '');
        $name = (string)($log['target_name'] ?? '');

        if ($type === 'user' && ctype_digit($id)) {
            return '/admin/users/' . $id;
        }
        if ($type === 'oauth_client' && $id !== '') {
            return '/admin/apps/' . rawurlencode($id);
        }
        if ($type === 'recovery_request' && ctype_digit($id)) {
            return '/admin/recovery/' . $id;
        }
        if ($type === 'page' && $name !== '') {
            $base = Plugins::mountUrl('pages');
            if ($base !== null) {
                return $base . '/' . rawurlencode($name);
            }
        }

        return null;
    }

    public static function actorUrl(array $log): ?string
    {
        $id = (int)($log['actor_id'] ?? 0);

        return $id > 0 ? '/admin/users/' . $id : null;
    }

    /**
     * 变更明细。默认紧凑模式（列表用），$full 为 true 时输出完整对照。
     * 内容全部转义，原始数据来自用户输入。
     */
    public static function changesHtml(?string $changes, bool $full = false): string
    {
        if (!$changes) {
            return '—';
        }

        $decoded = json_decode($changes, true);
        if (!is_array($decoded) || !$decoded) {
            return '—';
        }

        $blocks = [];
        foreach ($decoded as $field => $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $field = (string)$field;
            $label = self::FIELD_LABELS[$field] ?? $field;
            $old = $pair['old'] ?? null;
            $new = $pair['new'] ?? null;
            $blocks[] = $full
                ? self::diffBlockHtml($field, $label, $old, $new)
                : self::diffPreviewHtml($field, $label, $old, $new);
        }

        if (!$blocks) {
            return '—';
        }

        return '<div class="admin-diff ' . ($full ? 'admin-diff--full' : 'admin-diff--compact') . '">'
            . implode('', $blocks) . '</div>';
    }

    private static function diffPreviewHtml(string $field, string $label, $old, $new): string
    {
        $oldText = self::plainString($old);
        $newText = self::plainString($new);

        if (self::isLongText($oldText, $newText)) {
            $oldPart = self::textStats($oldText);
            $newPart = self::textStats($newText);
        } else {
            $inline = TextDiff::inline($oldText, $newText);
            $oldPart = self::segmentsHtml($inline['old'], 'old');
            $newPart = self::segmentsHtml($inline['new'], 'new');
        }

        return '<div class="admin-diff__row">'
            . '<span class="admin-diff__field">' . self::e($label) . '</span>'
            . '<span class="admin-diff__old">' . $oldPart . '</span>'
            . '<span class="admin-diff__arrow">→</span>'
            . '<span class="admin-diff__new">' . $newPart . '</span>'
            . '</div>';
    }

    private static function diffBlockHtml(string $field, string $label, $old, $new): string
    {
        $head = '<div class="admin-diff__block"><div class="admin-diff__legend">' . self::e($label) . '</div>';

        if ($field === 'avatar') {
            return $head . self::imageDiffHtml(self::plainString($old), self::plainString($new), 'avatars', '旧头像', '新头像') . '</div>';
        }
        if ($field === 'icon') {
            return $head . self::imageDiffHtml(self::plainString($old), self::plainString($new), 'develops', '旧图标', '新图标') . '</div>';
        }

        $oldText = self::plainString($old);
        $newText = self::plainString($new);

        if (self::isLongText($oldText, $newText)) {
            return $head . self::textDiffTable($oldText, $newText) . '</div>';
        }

        $inline = TextDiff::inline($oldText, $newText);

        return $head
            . '<div class="admin-diff__pair">'
            . '<div class="admin-diff__side admin-diff__side--old">' . self::segmentsHtml($inline['old'], 'old') . '</div>'
            . '<div class="admin-diff__arrow">→</div>'
            . '<div class="admin-diff__side admin-diff__side--new">' . self::segmentsHtml($inline['new'], 'new') . '</div>'
            . '</div></div>';
    }

    private static function imageDiffHtml(string $old, string $new, string $dir, string $oldCaption, string $newCaption): string
    {
        $figure = static function (string $file, string $caption) use ($dir): string {
            if ($file === '') {
                $body = '<span class="admin-avatar-diff__missing">未设置</span>';
            } else {
                $url = self::imageUrl($file, $dir);
                $body = $url !== null
                    ? '<img src="' . self::e($url) . '" alt="" loading="lazy">'
                    : '<span class="admin-avatar-diff__missing">文件已删除</span>';
                $body .= '<span class="admin-avatar-diff__name">' . self::e($file) . '</span>';
            }

            return '<figure class="admin-avatar-diff__item">' . $body
                . '<figcaption>' . self::e($caption) . '</figcaption></figure>';
        };

        return '<div class="admin-avatar-diff' . ($dir === 'avatars' ? '' : ' admin-avatar-diff--icon') . '">'
            . $figure($old, $oldCaption)
            . '<span class="admin-avatar-diff__arrow">→</span>'
            . $figure($new, $newCaption)
            . '</div>';
    }

    private static function imageUrl(string $file, string $dir): ?string
    {
        if ($file === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $file)) {
            return $file;
        }

        $file = basename($file);
        if (!is_file(dirname(__DIR__) . '/public/assets/' . $dir . '/' . $file)) {
            return null;
        }

        return '/assets/' . $dir . '/' . rawurlencode($file);
    }

    private static function textDiffTable(string $old, string $new): string
    {
        $ops = TextDiff::lines($old, $new);
        if (!$ops) {
            return '<p class="admin-diff__blank">两边内容相同。</p>';
        }

        $rows = '';
        $total = count($ops);
        $hidden = 0;
        if ($total > 3000) {
            $hidden = $total - 3000;
            $ops = array_slice($ops, 0, 3000);
        }
        $count = count($ops);
        $i = 0;

        while ($i < $count) {
            if ($ops[$i]['type'] === 'same') {
                $rows .= self::diffRow($ops[$i], $ops[$i]);
                $i++;
                continue;
            }

            $dels = [];
            $adds = [];
            while ($i < $count && $ops[$i]['type'] !== 'same') {
                if ($ops[$i]['type'] === 'del') {
                    $dels[] = $ops[$i];
                } else {
                    $adds[] = $ops[$i];
                }
                $i++;
            }

            $pairs = min(count($dels), count($adds));
            for ($p = 0; $p < $pairs; $p++) {
                $rows .= self::diffRow($dels[$p], $adds[$p], true);
            }
            for ($p = $pairs; $p < count($dels); $p++) {
                $rows .= self::diffRow($dels[$p], null);
            }
            for ($p = $pairs; $p < count($adds); $p++) {
                $rows .= self::diffRow(null, $adds[$p]);
            }
        }

        if ($hidden > 0) {
            $rows .= '<tr><td colspan="4" class="admin-difftable__more">还有 ' . $hidden . ' 行未显示</td></tr>';
        }

        return '<div class="admin-difftable-wrap"><table class="admin-difftable">'
            . '<thead><tr><th colspan="2">旧版本</th><th colspan="2">新版本</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';
    }

    private static function diffRow(?array $old, ?array $new, bool $paired = false): string
    {
        $oldText = $old['text'] ?? null;
        $newText = $new['text'] ?? null;

        if ($paired && $oldText !== null && $newText !== null) {
            $inline = TextDiff::inline($oldText, $newText);
            $oldCell = self::segmentsHtml($inline['old'], 'old');
            $newCell = self::segmentsHtml($inline['new'], 'new');
        } else {
            $oldCell = $oldText === null ? '' : self::e($oldText);
            $newCell = $newText === null ? '' : self::e($newText);
        }

        $oldClass = 'admin-difftable__side'
            . (($old['type'] ?? '') === 'del' ? ' is-del' : '')
            . ($oldText === null ? ' is-blank' : '');
        $newClass = 'admin-difftable__side'
            . (($new['type'] ?? '') === 'add' ? ' is-add' : '')
            . ($newText === null ? ' is-blank' : '');

        return '<tr>'
            . '<td class="admin-difftable__ln">' . ($old !== null ? (int)$old['old'] + 1 : '') . '</td>'
            . '<td class="' . $oldClass . '">' . ($oldCell === '' ? '&nbsp;' : $oldCell) . '</td>'
            . '<td class="admin-difftable__ln">' . ($new !== null ? (int)$new['new'] + 1 : '') . '</td>'
            . '<td class="' . $newClass . '">' . ($newCell === '' ? '&nbsp;' : $newCell) . '</td>'
            . '</tr>';
    }

    private static function segmentsHtml(array $segments, string $side): string
    {
        if (!$segments) {
            return '<span class="admin-diff__blank">（空）</span>';
        }

        $html = '';
        foreach ($segments as $segment) {
            if ($side === 'old' && $segment['type'] === 'add') {
                continue;
            }
            if ($side === 'new' && $segment['type'] === 'del') {
                continue;
            }

            $text = self::e($segment['text']);
            if ($segment['type'] === 'del') {
                $html .= '<del>' . $text . '</del>';
            } elseif ($segment['type'] === 'add') {
                $html .= '<ins>' . $text . '</ins>';
            } else {
                $html .= $text;
            }
        }

        return $html === '' ? '<span class="admin-diff__blank">（空）</span>' : $html;
    }

    private static function isLongText(string $old, string $new): bool
    {
        return str_contains($old, "\n") || str_contains($new, "\n")
            || mb_strlen($old) > 160 || mb_strlen($new) > 160;
    }

    private static function textStats(string $text): string
    {
        if ($text === '') {
            return '（空）';
        }

        $lines = count(preg_split('/\R/u', $text) ?: []);

        return $lines . ' 行 · ' . mb_strlen($text) . ' 字';
    }

    private static function plainString($value): string
    {
        if (is_array($value)) {
            return (string)json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string)($value ?? '');
    }

    private static function valueText($value): string
    {
        if ($value === null || $value === '') {
            return '（空）';
        }
        if (is_bool($value)) {
            return $value ? '是' : '否';
        }
        if (is_array($value)) {
            return (string)json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string)$value;
    }

    private static function changesText(?string $changes): string
    {
        if (!$changes) {
            return '';
        }

        $decoded = json_decode($changes, true);
        if (!is_array($decoded) || !$decoded) {
            return '';
        }

        $parts = [];
        foreach ($decoded as $field => $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $label = self::FIELD_LABELS[$field] ?? (string)$field;
            $parts[] = $label . ': ' . self::valueText($pair['old'] ?? null) . ' → ' . self::valueText($pair['new'] ?? null);
        }

        return implode("\n", $parts);
    }

    private static function csvCell($value): string
    {
        $value = (string)($value ?? '');

        // 防止表格软件把用户填的内容当公式执行
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    private static function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ========== 邮件 ==========

    private function sendOutcomeEmail(array $request, string $mode, ?string $token = null, ?string $expiresAt = null): void
    {
        $config = self::config();
        $support = (string)($config['support_email'] ?? '');
        $username = (string)$request['username'];
        // 收件人是申请人（不是管理员）：按账号语言出。管理后台界面本身保持中文
        $x = fn(string $k, array $p = []): string => I18n::tIn(I18n::emailLocale((int)($request['user_id'] ?? 0)), $k, $p);
        $disclaimer = $x('您之所以会收到此邮件，是因为有人在提交申诉时提供该地址作为联系电子邮件地址。如果您并未提交任何申诉，可以忽略此邮件。');

        if ($mode === 'reject') {
            $title = $x('Nomen 账号恢复申诉处理结果');
            $blocks = [
                ['type' => 'paragraph', 'text' => $x('感谢您就恢复对您 Nomen 的访问权限一事与我们联系。')],
                ['type' => 'paragraph', 'text' => $x('经审核，我们暂时无法通过本次申诉，因此未能为账户「{:name}」恢复访问权限。', ['name' => $username])],
                ['type' => 'paragraph', 'text' => $x('如果您认为这是误判，或可以提供更多能证明账户归属的材料，欢迎直接回复本邮件或联系 {:support}。', ['support' => $support])],
                ['type' => 'alert', 'tone' => 'info', 'text' => $disclaimer],
            ];
            $button = null;
        } else {
            $url = EmailTemplate::url('/recovery/ticket?token=' . (string)$token);
            $days = max(1, (int)ceil(((int)($config['recovery_token_ttl'] ?? 604800)) / 86400));
            $title = $x('Nomen 恢复用户申诉已通过');

            $blocks = [
                ['type' => 'paragraph', 'text' => $x('您的 Nomen 恢复用户申诉已通过。')],
                ['type' => 'paragraph', 'text' => $x('请在 {:days} 天内点击下方链接（仅使用 1 次）来直接登录账号。登录后系统会提示您，您必须先修改密码，或添加 / 更换并验证一个邮箱（这大概率是让您此次无法登录的主要原因）；并且如果账号仅剩 2 个登录方式，您必须根据提示添加第 3 个登录方式。', ['days' => $days])],
                ['type' => 'alert', 'tone' => 'warning', 'text' => $x('该链接仅能使用 1 次，请勿转发给任何人。若链接已失效，请回复本邮件由我们重新签发。')],
                ['type' => 'alert', 'tone' => 'info', 'text' => $disclaimer],
            ];
            $button = ['text' => $x('登录并修复账户'), 'url' => $url];
        }

        // 审核结果连同恢复链接只发给「申请时留下的联系邮箱」。
        // 不再抄送账号主邮箱：一是收到的人本来就是申请人，那句"如果不是您本人提交"纯属多余；
        // 二是这条一次性登录链接没必要再往账号邮箱多铺一份。
        $to = (string)($request['contact_email'] ?? '');
        if ($to !== '') {
            $locale = I18n::emailLocale((int)($request['user_id'] ?? 0));
            Email::send($to, 'Nomen - ' . $title, EmailTemplate::render([
                'preheader' => $title,
                'badge'     => ['text' => '账号恢复', 'tone' => $mode === 'reject' ? 'neutral' : 'success'],
                'title'     => $title,
                'blocks'    => $blocks,
                'button'    => $button,
            ], $locale));
        }
    }
}

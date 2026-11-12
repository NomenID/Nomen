<?php
namespace Nomen\Controllers;

use Nomen\Src\{ActivityLog, Csrf, Database, Email, EmailTemplate, FamilyHelper, Features, Hooks, I18n, Plugins, RecoveryHelper, RedisClient, Response, Session, TextDiff, UpdateCheck, Upgrade};
use PDO;

/**
 * 管理后台。每个方法首行都做一次 requireAdmin()：
 * Router 的 admin 中间件是第一道闸，这里是第二道，避免将来漏配中间件就裸奔。
 */
class AdminController
{
    private const PER_PAGE = 20;

    /** 日志页把内部 id 换成 UNID 时的请求内缓存 */
    private static array $unidCache = [];
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

        Response::json([
            'success'          => true,
            'stats'            => $stats,
            'cards'            => Hooks::filter('admin.dashboard.cards', self::statCards($stats), $stats),
            'redis'            => self::redisHealth(),
            'recentLogs'       => self::logRows(ActivityLog::listFor([], 1, 10)['items']),
            'upgradePending'   => Upgrade::mismatch($this->db),
            'plugins'          => self::pluginSummary(),
            'site'             => Hooks::filter('admin.dashboard.site', [
                'version' => (string)(self::config()['version'] ?? ''),
            ]),
        ]);
    }

    /** @return array<int, array{label: string, value: int, hint: string, alert?: bool}> */
    private static function statCards(array $stats): array
    {
        $cards = [
            ['label' => I18n::t('Registered users'), 'value' => (int)$stats['total_users'], 'hint' => I18n::t('{:count} today', ['count' => (int)$stats['new_users_today']])],
            ['label' => I18n::t('Verified email'), 'value' => (int)$stats['verified_users'], 'hint' => I18n::t('{:count} not verified', ['count' => (int)$stats['total_users'] - (int)$stats['verified_users']])],
            ['label' => I18n::t('Pending recovery requests'), 'value' => (int)$stats['pending_recovery'], 'hint' => $stats['pending_recovery'] > 0 ? I18n::t('Requests waiting for review') : I18n::t('Nothing pending'), 'alert' => $stats['pending_recovery'] > 0],
            ['label' => I18n::t('Approved, not completed'), 'value' => (int)$stats['approved_recovery'], 'hint' => I18n::t('Link sent, the user has not finished yet')],
            ['label' => I18n::t('Administrators'), 'value' => (int)$stats['admins'], 'hint' => I18n::t('Including yourself')],
            ['label' => I18n::t('Disabled accounts'), 'value' => (int)$stats['disabled_users'], 'hint' => I18n::t('Turned off')],
            ['label' => I18n::t('Active devices (30 days)'), 'value' => (int)$stats['active_devices'], 'hint' => I18n::t('Active in the last 30 days')],
            ['label' => I18n::t('Enabled apps'), 'value' => (int)$stats['active_apps'], 'hint' => I18n::t('{:count} authorizations so far', ['count' => (int)$stats['total_authorizations']])],
        ];

        if (Features::guardians()) {
            $cards[] = ['label' => I18n::t('Families pending'), 'value' => (int)$stats['unclaimed_families'], 'hint' => $stats['unclaimed_families'] > 0 ? I18n::t('Waiting for the guardian to join with an account') : I18n::t('All handled'), 'alert' => $stats['unclaimed_families'] > 0];
            $cards[] = ['label' => I18n::t('Children in families'), 'value' => (int)$stats['managed_children'], 'hint' => I18n::t('Accounts shared with a guardian')];
        }

        return $cards;
    }

    /** @return array{loaded: int, bundled: string[], missingExt: string[]} */
    private static function pluginSummary(): array
    {
        return [
            'loaded'     => count(Plugins::loaded()),
            'bundled'    => array_values(array_map(static fn(array $p): string => (string)$p['name'], Plugins::all())),
            'missingExt' => array_values(array_filter(
                ['gd', 'fileinfo', 'redis'],
                static fn(string $ext): bool => !extension_loaded($ext)
            )),
        ];
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
            return ['ok' => false, 'error' => I18n::t('Could not connect, or the phpredis extension is not installed (the log has the details)')];
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
                'version' => (string)($server['redis_version'] ?? I18n::t('Unknown')),
                'used'    => (string)($memory['used_memory_human'] ?? ''),
                'max'     => $max > 0 ? (string)($memory['maxmemory_human'] ?? '') : I18n::t('Unlimited'),
                'percent' => $percent,
                'policy'  => (string)($memory['maxmemory_policy'] ?? ''),
                'keys'    => (int)$client->dbSize(),
                'index'   => Session::indexReady(),
            ];
        } catch (\Throwable $e) {
            error_log('[redis] 后台状态读取失败: ' . $e->getMessage());
            return ['ok' => false, 'error' => I18n::t('Could not read the status: {:message}', ['message' => $e->getMessage()])];
        }
    }

    /**
     * GET /api/admin/version - 概览页「插件」卡异步查最新正式版。
     * 拉不到只回 latest=null，前端只显示当前版本，不报错。
     */
    public function version(): void
    {
        $this->requireAdmin();

        $current = (string)(self::config()['version'] ?? '');
        $latest = UpdateCheck::latest();

        Response::json([
            'success'         => true,
            'latest'          => $latest,
            'updateAvailable' => $latest !== null && UpdateCheck::isNewer($latest['version'], $current),
        ]);
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
            "SELECT id, unid, username, display_name, email, email_verified, role, status, country,
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

        Response::json([
            'success'    => true,
            'users'      => Hooks::filter('admin.users.rows', $users),
            'search'     => $search,
            'filter'     => $filter,
            'pagination' => ['page' => $page, 'pages' => max(1, (int)ceil($total / self::PER_PAGE)), 'total' => $total],
            'query'      => array_filter(['q' => $search, 'filter' => $filter]),
        ]);
    }

    public function userDetail(string $id): void
    {
        $admin = $this->requireAdmin();

        $stmt = $this->db->prepare(
            'SELECT id, unid, username, display_name, email, email_verified, role, status, country,
                    birth_date, gender, created_at, last_login_at, last_login_ip, two_factor_policy
             FROM users WHERE unid = :unid LIMIT 1'
        );
        $stmt->execute([':unid' => $id]);
        $user = $stmt->fetch();
        if (!$user) {
            Response::error(404, I18n::t('User not found'));
        }
        $userId = (int)$user['id'];

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

        Response::json(Hooks::filter('admin.user.detail', [
            'success'             => true,
            'user'                => $user,
            'methods'             => $groups['login'],
            'assistMethods'       => $groups['assist'],
            'authorizations'      => $authorizations,
            'recoveryHistory'     => $recoveryHistory,
            'membership'          => $membership,
            'familyGuardians'     => $membership ? FamilyHelper::guardiansOf((int)$membership['group_id']) : [],
            'ownedGroups'         => $familyChildren,
            'ownedGroupsChildren' => $familyChildrenByGroup,
            'guardianEmail'       => Features::guardians() ? FamilyHelper::guardianEmailFor($userId) : null,
            'recentLogs'          => self::logRows(ActivityLog::recentFor($userId, 20)),
            'isSelf'              => (int)$user['id'] === (int)$admin['id'],
        ], $userId, $user, $admin));
    }

    public function toggleStatus(): void
    {
        $admin = $this->requireAdmin();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => I18n::t('Invalid request')], 403);
        }

        $stmt = $this->db->prepare('SELECT id, username, role, status FROM users WHERE unid = :unid LIMIT 1');
        $stmt->execute([':unid' => (string)($_POST['user_unid'] ?? '')]);
        $target = $stmt->fetch();
        $userId = $target ? (int)$target['id'] : 0;

        if (!$target) {
            Response::json(['success' => false, 'message' => I18n::t('User not found')]);
        }
        if ((int)$target['id'] === (int)$admin['id']) {
            Response::json(['success' => false, 'message' => I18n::t('You cannot disable your own account')]);
        }
        if ($target['role'] === 'admin' && (int)$target['status'] === 1) {
            Response::json(['success' => false, 'message' => I18n::t('You cannot disable another administrator. Revoke their administrator role first.')]);
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
            'summary'  => I18n::t('{:action} user {:name}', ['action' => $newStatus === 1 ? I18n::t('Enabled') : I18n::t('Disabled'), 'name' => $target['username']]),
            'changes'  => ['status' => [
                'old' => (int)$target['status'] === 1 ? I18n::t('Enabled') : I18n::t('Disabled'),
                'new' => $newStatus === 1 ? I18n::t('Enabled') : I18n::t('Disabled'),
            ]],
        ]);

        Hooks::run('admin.user.status_changed', [(int)$userId, $newStatus, (int)$admin['id']]);

        Response::json([
            'success' => true,
            'message' => $newStatus === 1 ? I18n::t('Account enabled') : I18n::t('Account disabled'),
            'status'  => $newStatus,
        ]);
    }

    public function toggleAdmin(): void
    {
        $admin = $this->requireAdmin();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => I18n::t('Invalid request')], 403);
        }

        $stmt = $this->db->prepare('SELECT id, username, role FROM users WHERE unid = :unid LIMIT 1');
        $stmt->execute([':unid' => (string)($_POST['user_unid'] ?? '')]);
        $target = $stmt->fetch();
        $userId = $target ? (int)$target['id'] : 0;

        if (!$target) {
            Response::json(['success' => false, 'message' => I18n::t('User not found')]);
        }
        if ((int)$target['id'] === (int)$admin['id']) {
            Response::json(['success' => false, 'message' => I18n::t('You cannot change your own administrator role')]);
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
            'summary'  => I18n::t('{:action}: {:name}', ['action' => $newRole === 'admin' ? I18n::t('Granted administrator') : I18n::t('Revoked administrator'), 'name' => $target['username']]),
            'changes'  => ['role' => [
                'old' => $target['role'] === 'admin' ? I18n::t('Administrator') : I18n::t('Regular user'),
                'new' => $newRole === 'admin' ? I18n::t('Administrator') : I18n::t('Regular user'),
            ]],
        ]);

        Hooks::run('admin.user.role_changed', [(int)$userId, $newRole, (int)$admin['id']]);

        Response::json([
            'success' => true,
            'message' => $newRole === 'admin' ? I18n::t('Administrator role granted') : I18n::t('Administrator role revoked'),
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

        $rows = $stmt->fetchAll();
        $userUnids = self::unidsFor('users', array_column($rows, 'user_id'));
        foreach ($rows as &$row) {
            $row['user_unid'] = $userUnids[(int)$row['user_id']] ?? '';
        }
        unset($row);

        Response::json([
            'success'    => true,
            'requests'   => $rows,
            'status'     => $status,
            'search'     => $search,
            'pagination' => ['page' => $page, 'pages' => max(1, (int)ceil($total / self::PER_PAGE)), 'total' => $total],
            'query'      => array_filter(['status' => $status, 'q' => $search]),
        ]);
    }

    public function recoveryDetail(string $id): void
    {
        $admin = $this->requireAdmin();

        $stmt = $this->db->prepare('SELECT * FROM account_recovery_requests WHERE unid = :unid LIMIT 1');
        $stmt->execute([':unid' => $id]);
        $request = $stmt->fetch();
        if (!$request) {
            Response::error(404, I18n::t('Request not found'));
        }

        $stmt = $this->db->prepare(
            'SELECT id, unid, username, email, email_verified, status, birth_date, gender, created_at, last_login_at
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

        Response::json(Hooks::filter('admin.recovery.detail', [
            'success'     => true,
            'request'     => $request,
            'user'        => $user,
            'attachments' => RecoveryHelper::listAttachments((int)$request['id']),
            'recentLogs'  => self::logRows($recentLogs),
            'linkTtlDays' => (int)ceil(((int)(self::config()['recovery_token_ttl'] ?? 604800)) / 86400),
        ], $request, $user));
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
            $this->reviewFail($isAjax, I18n::t('Invalid request'));
        }

        $note = trim((string)($_POST['admin_note'] ?? ''));

        $stmt = $this->db->prepare('SELECT * FROM account_recovery_requests WHERE unid = :unid LIMIT 1');
        $stmt->execute([':unid' => (string)($_POST['request_unid'] ?? '')]);
        $request = $stmt->fetch();

        if (!$request) {
            $this->reviewFail($isAjax, I18n::t('Request not found'));
        }
        $requestId = (int)$request['id'];

        if ($mode === 'reject') {
            if ($note === '') {
                $this->reviewFail($isAjax, I18n::t('Give a reason when rejecting (it is stored on the request and not sent to the user)'));
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
                'summary'  => I18n::t('Rejected recovery request: {:note}', ['note' => mb_substr($note, 0, 120, 'UTF-8')]),
            ]);

            $this->sendOutcomeEmail($request, 'reject');

            Response::json([
                'success'    => true,
                'message'    => I18n::t('Request rejected and the requester notified'),
                'request_id' => $requestId,
                'status'     => 'rejected',
            ]);
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
            'summary'  => $mode === 'resend'
                ? I18n::t('Resent the recovery link to {:name}', ['name' => (string)$request['username']])
                : I18n::t('Approved recovery request: {:name}', ['name' => (string)$request['username']]),
        ]);

        $this->sendOutcomeEmail($request, 'approve', $token, $expiresAt);

        Response::json([
            'success'    => true,
            'message'    => $mode === 'resend'
                ? I18n::t('Recovery link sent again; the old link no longer works')
                : I18n::t('Request approved and the recovery link sent'),
            'request_id' => $requestId,
            'status'     => 'approved',
            'expires_at' => $expiresAt,
        ]);
    }

    private function reviewFail(bool $isAjax, string $message): void
    {
        Response::json(['success' => false, 'message' => $message], 422);
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

        Response::json([
            'success'    => true,
            'logs'       => Hooks::filter('admin.logs.rows', self::logRows($result['items'])),
            'filter'     => $filter,
            'pagination' => ['page' => $result['page'], 'pages' => $result['pages'], 'total' => $result['total']],
            'query'      => $query,
            'categories' => array_map(static fn($v) => I18n::t($v), ActivityLog::CATEGORIES),
            'actions'    => array_map(static fn($v) => I18n::t($v), ActivityLog::ACTIONS),
        ]);
    }

    public function logDetail(string $id): void
    {
        $admin = $this->requireAdmin();
        $log = ActivityLog::findByUnid($id);

        if ($log === null) {
            Response::error(404, I18n::t('That log entry does not exist'));
        }

        Response::json([
            'success' => true,
            'log'     => self::logRows([$log], true)[0],
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
        fputcsv($out, [
            I18n::t('ID'), I18n::t('Time'), I18n::t('Actor type'), I18n::t('Actor ID'), I18n::t('Actor'),
            I18n::t('Category'), I18n::t('Action'), I18n::t('Action code'),
            I18n::t('Target type'), I18n::t('Target ID'), I18n::t('Target name'),
            I18n::t('Summary'), I18n::t('Changes'), 'IP', I18n::t('Device'), 'User-Agent',
        ]);

        $userUnids = self::unidsFor('users', array_column($rows, 'actor_id'));
        $requestUnids = self::unidsFor(
            'account_recovery_requests',
            array_map(
                static fn(array $row): int => ($row['target_type'] ?? '') === 'recovery_request' ? (int)($row['target_id'] ?? 0) : 0,
                $rows
            )
        );

        foreach ($rows as $row) {
            $targetType = (string)($row['target_type'] ?? '');
            $targetUnid = $targetType === 'user'
                ? ($userUnids[(int)($row['target_id'] ?? 0)] ?? '')
                : ($targetType === 'recovery_request' ? ($requestUnids[(int)($row['target_id'] ?? 0)] ?? '') : (string)($row['target_id'] ?? ''));

            fputcsv($out, array_map(static fn($value): string => self::csvCell($value), [
                $row['unid'] ?? '',
                $row['created_at'],
                I18n::t(ActivityLog::actorLabel($row)),
                $userUnids[(int)($row['actor_id'] ?? 0)] ?? '',
                $row['actor_username'],
                I18n::t(ActivityLog::categoryLabel((string)$row['category'])),
                I18n::t(ActivityLog::actionLabel((string)$row['action'])),
                $row['action'],
                $row['target_type'] ? self::targetTypeLabel((string)$row['target_type']) : '',
                $targetUnid,
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

    /**
     * 日志行 → SPA 契约：补上展示用的标签和可点链接。
     * $full 为 true 时 changes 解码成字段对照，否则给一段纯文本摘要。
     *
     * @return array<int, array<string, mixed>>
     */
    private static function logRows(array $rows, bool $full = false): array
    {
        $userIds = [];
        $requestIds = [];
        foreach ($rows as $row) {
            $userIds[] = (int)($row['actor_id'] ?? 0);
            if (($row['target_type'] ?? '') === 'user') {
                $userIds[] = (int)($row['target_id'] ?? 0);
            } elseif (($row['target_type'] ?? '') === 'recovery_request') {
                $requestIds[] = (int)($row['target_id'] ?? 0);
            }
        }
        $userUnids = self::unidsFor('users', $userIds);
        $requestUnids = self::unidsFor('account_recovery_requests', $requestIds);

        return array_map(static function (array $row) use ($full, $userUnids, $requestUnids): array {
            $targetType = (string)($row['target_type'] ?? '');
            $actorUnid = $userUnids[(int)($row['actor_id'] ?? 0)] ?? '';
            $targetUnid = '';
            if ($targetType === 'user') {
                $targetUnid = $userUnids[(int)($row['target_id'] ?? 0)] ?? '';
            } elseif ($targetType === 'recovery_request') {
                $targetUnid = $requestUnids[(int)($row['target_id'] ?? 0)] ?? '';
            }

            return [
                'unid'             => (string)($row['unid'] ?? ''),
                'created_at'       => (string)$row['created_at'],
                'actor_type'       => (string)($row['actor_type'] ?? ''),
                'actor_unid'       => $actorUnid,
                'actor_username'   => (string)($row['actor_username'] ?? ''),
                'actor_label'      => I18n::t(ActivityLog::actorLabel($row)),
                'actor_url'        => $actorUnid !== '' ? '/admin/users/' . $actorUnid : null,
                'category'         => (string)$row['category'],
                'category_label'   => I18n::t(ActivityLog::categoryLabel((string)$row['category'])),
                'action'           => (string)$row['action'],
                'action_label'     => I18n::t(ActivityLog::actionLabel((string)$row['action'])),
                'target_type'      => $targetType,
                'target_type_label' => $targetType !== '' ? self::targetTypeLabel($targetType) : '',
                'target_unid'      => $targetUnid,
                'target_name'      => (string)($row['target_name'] ?? ''),
                'target_url'       => self::targetUrl($row, $targetUnid),
                'summary'          => (string)($row['summary'] ?? ''),
                'ip_address'       => (string)($row['ip_address'] ?? ''),
                'device_name'      => (string)($row['device_name'] ?? ''),
                'user_agent'       => (string)($row['user_agent'] ?? ''),
                'changes'          => $full
                    ? self::changesArray($row['changes'] ?? null)
                    : self::changesText($row['changes'] ?? null),
            ];
        }, $rows);
    }

    /**
     * 一批内部 id → UNID。日志只存了内部 id，展示前统一换掉，别让自增号露到界面上。
     *
     * @param  int[] $ids
     * @return array<int, string>
     */
    private static function unidsFor(string $table, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }

        $known = self::$unidCache[$table] ?? [];
        $missing = array_values(array_diff($ids, array_keys($known)));
        if (!$missing) {
            return $known;
        }

        $db = Database::getInstance();
        foreach (array_chunk($missing, 900) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $db->prepare("SELECT id, unid FROM {$table} WHERE id IN ({$in})");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $row) {
                $known[(int)$row['id']] = (string)$row['unid'];
            }
        }

        self::$unidCache[$table] = $known;

        return $known;
    }

    /** @return array<int, array{field: string, label: string, old: string, new: string}> */
    private static function changesArray(?string $changes): array
    {
        if (!$changes) {
            return [];
        }

        $decoded = json_decode($changes, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $field => $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $out[] = [
                'field' => (string)$field,
                'label' => I18n::t(self::FIELD_LABELS[$field] ?? (string)$field),
                'old'   => self::valueText($pair['old'] ?? null),
                'new'   => self::valueText($pair['new'] ?? null),
            ];
        }

        return $out;
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
            return '<span class="admin-badge admin-badge--muted">' . htmlspecialchars(I18n::t('No record on the account'), ENT_QUOTES, 'UTF-8') . '</span>';
        }
        return (int)$value === 1
            ? '<span class="admin-badge admin-badge--success">' . htmlspecialchars(I18n::t('Matches'), ENT_QUOTES, 'UTF-8') . '</span>'
            : '<span class="admin-badge admin-badge--danger">' . htmlspecialchars(I18n::t('Does not match'), ENT_QUOTES, 'UTF-8') . '</span>';
    }

    private const FIELD_LABELS = [
        'username'           => 'Username',
        'display_name'       => 'Display name',
        'bio'                => 'Bio',
        'email'              => 'Email',
        'email_verified'     => 'Email verified',
        'country'            => 'Country / Region',
        'birth_date'         => 'Date of birth',
        'gender'             => 'Gender',
        'avatar'             => 'Avatar',
        'two_factor_policy'  => 'Two-step verification policy',
        'notify_system_important' => 'Notifications: important system messages',
        'notify_system_general'   => 'Notifications: general system messages',
        'notify_system_interact'  => 'Notifications: system interactions',
        'name'               => 'Name',
        'icon'               => 'Icon',
        'description'        => 'Description',
        'homepage_url'       => 'Homepage',
        'redirect_uris'      => 'Redirect URIs',
        'scopes'             => 'Scopes',
        'client_type'        => 'Client type',
        'pkce_required'      => 'PKCE required',
        'status'             => 'Status',
        'role'               => 'Role',
        'slug'               => 'Path',
        'title'              => 'Title',
        'summary'            => 'Summary',
        'sort_order'         => 'Sort order',
        'body'               => 'Body',
    ];

    private const TARGET_LABELS = [
        'user'             => 'User',
        'oauth_client'     => 'App',
        'recovery_request' => 'Recovery request',
        'user_email'       => 'Email',
        'temp_key'         => 'Temp key',
        'family_group'     => 'Family',
        'developer'        => 'Developer',
        'recovery_code'    => 'Recovery code',
        'guardian_email'   => 'Guardian email',
        'device'           => 'Device',
        'biometric'        => 'Biometric',
        'plugin_mount'     => 'Mount point',
        'avatar'           => 'Avatar',
        'page'             => 'Page',
    ];

    public static function targetTypeLabel(string $type): string
    {
        return I18n::t(self::TARGET_LABELS[$type] ?? $type);
    }

    public static function targetUrl(array $log, string $targetUnid = ''): ?string
    {
        $type = (string)($log['target_type'] ?? '');
        $id   = (string)($log['target_id'] ?? '');
        $name = (string)($log['target_name'] ?? '');

        if ($type === 'user' && $targetUnid !== '') {
            return '/admin/users/' . $targetUnid;
        }
        if ($type === 'oauth_client' && $id !== '') {
            return '/admin/apps/' . rawurlencode($id);
        }
        if ($type === 'recovery_request' && $targetUnid !== '') {
            return '/admin/recovery/' . $targetUnid;
        }
        if ($type === 'page' && $name !== '') {
            $base = Plugins::mountUrl('pages');
            if ($base !== null) {
                return $base . '/' . rawurlencode($name);
            }
        }

        return null;
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
            return $head . self::imageDiffHtml(self::plainString($old), self::plainString($new), 'avatars', I18n::t('Old avatar'), I18n::t('New avatar')) . '</div>';
        }
        if ($field === 'icon') {
            return $head . self::imageDiffHtml(self::plainString($old), self::plainString($new), 'develops', I18n::t('Old icon'), I18n::t('New icon')) . '</div>';
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
                $body = '<span class="admin-avatar-diff__missing">' . self::e(I18n::t('Not set')) . '</span>';
            } else {
                $url = self::imageUrl($file, $dir);
                $body = $url !== null
                    ? '<img src="' . self::e($url) . '" alt="" loading="lazy">'
                    : '<span class="admin-avatar-diff__missing">' . self::e(I18n::t('The file has been deleted')) . '</span>';
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
            return '<p class="admin-diff__blank">' . self::e(I18n::t('Both sides are identical.')) . '</p>';
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
            $rows .= '<tr><td colspan="4" class="admin-difftable__more">' . self::e(I18n::t('{:count} more lines not shown', ['count' => $hidden])) . '</td></tr>';
        }

        return '<div class="admin-difftable-wrap"><table class="admin-difftable">'
            . '<thead><tr><th colspan="2">' . self::e(I18n::t('Old version')) . '</th><th colspan="2">' . self::e(I18n::t('New version')) . '</th></tr></thead>'
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
            return '<span class="admin-diff__blank">' . self::e(I18n::t('(empty)')) . '</span>';
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

        return $html === '' ? '<span class="admin-diff__blank">' . self::e(I18n::t('(empty)')) . '</span>' : $html;
    }

    private static function isLongText(string $old, string $new): bool
    {
        return str_contains($old, "\n") || str_contains($new, "\n")
            || mb_strlen($old) > 160 || mb_strlen($new) > 160;
    }

    private static function textStats(string $text): string
    {
        if ($text === '') {
            return I18n::t('(empty)');
        }

        $lines = count(preg_split('/\R/u', $text) ?: []);

        return I18n::t('{:lines} lines · {:chars} characters', ['lines' => $lines, 'chars' => mb_strlen($text)]);
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
            return I18n::t('(empty)');
        }
        if (is_bool($value)) {
            return $value ? I18n::t('Yes') : I18n::t('No');
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
            $label = I18n::t(self::FIELD_LABELS[$field] ?? (string)$field);
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
        // 收件人是申请人（不是管理员）：按账号语言出，和后台界面语言无关
        $x = fn(string $k, array $p = []): string => I18n::tIn(I18n::emailLocale((int)($request['user_id'] ?? 0)), $k, $p);
        $disclaimer = $x('You received this email because someone provided this address as the contact email when submitting an appeal. If you did not submit any appeal, you can ignore this email.');

        if ($mode === 'reject') {
            $title = $x('Your {:sitename} account recovery appeal — result');
            $blocks = [
                ['type' => 'paragraph', 'text' => $x('Thank you for reaching out about regaining access to your {:sitename}.')],
                ['type' => 'paragraph', 'text' => $x('After review, we could not approve this appeal for now, so access to the account “{:name}” has not been restored.', ['name' => $username])],
                ['type' => 'paragraph', 'text' => $x('If you believe this was a mistake, or you can provide more material proving the account is yours, reply to this email directly or contact {:support}.', ['support' => $support])],
                ['type' => 'alert', 'tone' => 'info', 'text' => $disclaimer],
            ];
            $button = null;
        } else {
            $url = EmailTemplate::url('/recovery/ticket?token=' . (string)$token);
            $days = max(1, (int)ceil(((int)($config['recovery_token_ttl'] ?? 604800)) / 86400));
            $title = $x('Your {:sitename} account recovery appeal was approved');

            $blocks = [
                ['type' => 'paragraph', 'text' => $x('Your {:sitename} account recovery appeal has been approved.')],
                ['type' => 'paragraph', 'text' => $x('Within {:days} days, click the link below (usable once) to sign in directly. After signing in you will be asked to change your password, or to add, replace or verify an email (this is most likely why you could not sign in); and if the account has only 2 sign-in methods left, you will be asked to add a third.', ['days' => $days])],
                ['type' => 'alert', 'tone' => 'warning', 'text' => $x('This link can be used once — do not forward it to anyone. If it has expired, reply to this email and we will issue a new one.')],
                ['type' => 'alert', 'tone' => 'info', 'text' => $disclaimer],
            ];
            $button = ['text' => $x('Sign in and secure the account'), 'url' => $url];
        }

        // 审核结果连同恢复链接只发给「申请时留下的联系邮箱」。
        // 不再抄送账号主邮箱：一是收到的人本来就是申请人，那句"如果不是您本人提交"纯属多余；
        // 二是这条一次性登录链接没必要再往账号邮箱多铺一份。
        $to = (string)($request['contact_email'] ?? '');
        if ($to !== '') {
            $locale = I18n::emailLocale((int)($request['user_id'] ?? 0));
            Email::send($to, EmailTemplate::subjectPrefix() . $title, EmailTemplate::render([
                'preheader' => $title,
                'badge'     => ['text' => $x('Account recovery'), 'tone' => $mode === 'reject' ? 'neutral' : 'success'],
                'title'     => $title,
                'blocks'    => $blocks,
                'button'    => $button,
            ], $locale));
        }
    }
}

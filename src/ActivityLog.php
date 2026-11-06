<?php
namespace Nomen\Src;

use Nomen\Controllers\DeviceController;
use PDO;

/**
 * 用户/管理员活动日志。
 *
 * 写入永远不抛异常：日志失败不能连带把正常请求打挂。
 * 敏感值（密码、各类哈希与密钥、验证码、令牌）一律不入库，见 SENSITIVE。
 */
class ActivityLog
{
    public const CAT_AUTH     = 'auth';
    public const CAT_PROFILE  = 'profile';
    public const CAT_SECURITY = 'security';
    public const CAT_DEVICE   = 'device';
    public const CAT_OAUTH    = 'oauth';
    public const CAT_DEV      = 'developer';
    public const CAT_ACCOUNT  = 'account';
    public const CAT_RECOVERY = 'recovery';
    public const CAT_FAMILY   = 'family';
    public const CAT_ADMIN    = 'admin';
    public const CAT_PAGES    = 'pages';

    public const CATEGORIES = [
        self::CAT_AUTH     => '登录与账户',
        self::CAT_PROFILE  => '个人资料',
        self::CAT_SECURITY => '安全设置',
        self::CAT_DEVICE   => '设备',
        self::CAT_OAUTH    => '应用授权',
        self::CAT_DEV      => '开放平台',
        self::CAT_ACCOUNT  => '账户注销',
        self::CAT_RECOVERY => '账号恢复',
        self::CAT_FAMILY   => '家庭',
        self::CAT_ADMIN    => '管理员操作',
        self::CAT_PAGES    => '页面',
    ];

    public const CATEGORY_ICONS = [
        self::CAT_AUTH     => 'login',
        self::CAT_PROFILE  => 'user',
        self::CAT_SECURITY => 'shield-check',
        self::CAT_DEVICE   => 'monitor',
        self::CAT_OAUTH    => 'link',
        self::CAT_DEV      => 'puzzle',
        self::CAT_ACCOUNT  => 'trash',
        self::CAT_RECOVERY => 'lifebuoy',
        self::CAT_FAMILY   => 'family',
        self::CAT_ADMIN    => 'shield',
        self::CAT_PAGES    => 'file',
    ];

    public const ACTIONS = [
        'auth.register'                   => '注册账户',
        'auth.login'                      => '登录成功',
        'auth.login_failed'               => '登录失败',
        'auth.logout'                     => '退出登录',
        'auth.2fa_passed'                 => '两步验证通过',
        'auth.2fa_failed'                 => '两步验证失败',

        'profile.update'                  => '修改个人资料',
        'profile.avatar'                  => '更换头像',

        'pages.create'                    => '创建页面',
        'pages.update'                    => '编辑页面',
        'pages.delete'                    => '删除页面',

        'security.password_change'        => '修改密码',
        'security.password_reset'         => '用验证码重置密码',
        'security.email_change_requested' => '申请更换邮箱',
        'security.email_changed'          => '邮箱更换生效',
        'security.email_added'            => '添加邮箱',
        'security.email_removed'          => '移除邮箱',
        'security.email_verified'         => '邮箱验证完成',
        'security.totp_enable'            => '绑定验证器 APP',
        'security.totp_disable'           => '解绑验证器 APP',
        'security.recovery_codes_generate' => '生成恢复码',
        'security.recovery_codes_delete'   => '删除恢复码',
        'security.recovery_code_used'     => '使用恢复码',
        'security.2fa_policy'             => '修改两步验证策略',
        'security.quick_login'            => '修改快速登录',
        'security.biometric_add'          => '绑定生物识别',
        'security.biometric_remove'       => '删除生物识别',
        'security.temp_key_create'        => '创建临时密钥',
        'security.temp_key_delete'        => '删除临时密钥',
        'security.temp_key_used'          => '使用临时密钥登录',
        'security.notification_settings'  => '修改通知偏好',

        'device.trust'                    => '信任新设备',
        'device.revoke'                   => '移除设备',
        'device.revoke_all'               => '移除其他全部设备',

        'oauth.authorize'                 => '授权应用',
        'oauth.authorize_silent'          => '快速登录授权应用',
        'oauth.revoke'                    => '撤销应用授权',
        'oauth.token_issued'              => '应用换取令牌',

        'developer.register'              => '入驻开放平台',
        'developer.profile_update'        => '更新开发者资料',
        'developer.app_create'            => '创建应用',
        'developer.app_update'            => '更新应用',
        'developer.app_reset_secret'      => '重置应用密钥',
        'developer.app_delete'            => '删除应用',

        'admin.app_create'                => '管理员创建应用',
        'admin.app_update'                => '管理员更新应用',
        'admin.app_reset_secret'          => '管理员重置应用密钥',
        'admin.app_toggle'                => '管理员启用/停用应用',
        'admin.app_delete'                => '管理员删除应用',
        'admin.mount_update'              => '修改插件挂载点',
        'admin.site_settings'             => '修改站点设置',
        'admin.developer_toggle'          => '管理员启用/停用开发者',

        'account.delete'                  => '注销账户',
        'account.reclaim'                 => '回收未验证邮箱',

        'recovery.submit'                 => '提交恢复申诉',
        'recovery.approved'               => '通过恢复申诉',
        'recovery.rejected'               => '拒绝恢复申诉',
        'recovery.link_resent'            => '重发恢复链接',
        'recovery.ticket_used'            => '使用恢复链接登录',
        'recovery.completed'              => '完成安全修复',

        'family.group_created'            => '创建家庭',
        'family.group_claimed'            => '接受家庭邀请',
        'family.guardian_added'           => '邀请家长',
        'family.guardian_invited'         => '孩子邀请家长',
        'family.child_added'              => '创建孩子账号',
        'family.child_left'               => '退出家庭',
        'family.child_locked'             => '锁定孩子账户',
        'family.child_unlocked'           => '解锁孩子账户',
        'family.child_password_reset'     => '重置孩子密码',
        'family.consent_given'            => '家长确认',
        'family.child_independent'        => '停止监护（本人申请）',
        'family.child_independent_auto'   => '停止监护（成年自动）',
        'family.invite_expired'           => '家庭邀请过期',

        'admin.user_disable'              => '禁用用户',
        'admin.user_enable'               => '启用用户',
        'admin.role_grant'                => '设为管理员',
        'admin.role_revoke'               => '撤销管理员',
    ];

    /**
     * 字段名命中即丢弃，避免明文密码/密钥/令牌落库。
     * 注意 AuthController 的 $old 回显数组里含明文 password，绝不能整体入日志。
     */
    private const SENSITIVE = [
        'password', 'password_confirm', 'current_password', 'new_password', 'confirm_password',
        'email_password', 'password_hash', 'key_hash', 'code_hash', 'secret', 'totp_secret',
        'totp_pending_secret', 'client_secret', 'token', 'access_token', 'refresh_token',
        'two_factor_code', 'login_code', 'password_reset_code', 'email_verify_token',
        'pending_email_token', 'recovery_token', 'code', 'captcha', '_csrf',
    ];

    private static function db(): PDO
    {
        return Database::getInstance();
    }

    /**
     * @param array{
     *   action: string, category: string, actor_type?: string, actor_id?: int,
     *   actor_username?: string, target_type?: string, target_id?: string|int,
     *   target_name?: string, summary?: string, changes?: array|null, skip_ip?: bool
     * } $opts actor_* 省略时取当前登录会话；skip_ip 用于服务端自己发起的请求（IP 是源站地址，记下来没有意义）
     */
    public static function record(array $opts): void
    {
        $action = (string)($opts['action'] ?? '');
        if ($action === '') {
            return;
        }

        $actorType = (string)($opts['actor_type'] ?? '');
        $actorId   = $opts['actor_id'] ?? null;
        $actorName = $opts['actor_username'] ?? null;

        if ($actorType === '') {
            $sessionUserId = Session::get('user_id');
            if ($sessionUserId) {
                $isAdmin = Session::get('role') === 'admin';
                $actorType = $isAdmin ? 'admin' : 'user';
                $actorId   = (int)$sessionUserId;
                $actorName = $actorName ?? (string)Session::get('username', '');
            } else {
                $actorType = 'system';
            }
        }

        $changes = $opts['changes'] ?? null;
        if (is_array($changes) && !$changes) {
            $changes = null;
        }

        try {
            self::db()->prepare(
                'INSERT INTO activity_logs
                 (actor_type, actor_id, actor_username, action, category, target_type, target_id,
                  target_name, summary, changes, ip_address, user_agent, device_name)
                 VALUES (:at, :ai, :an, :ac, :cg, :tt, :ti, :tn, :sm, :ch, :ip, :ua, :dn)'
            )->execute([
                ':at' => $actorType,
                ':ai' => $actorId !== null ? (int)$actorId : null,
                ':an' => $actorName !== null ? mb_substr((string)$actorName, 0, 64, 'UTF-8') : null,
                ':ac' => mb_substr($action, 0, 64, 'UTF-8'),
                ':cg' => mb_substr((string)($opts['category'] ?? self::CAT_AUTH), 0, 32, 'UTF-8'),
                ':tt' => $opts['target_type'] ?? null,
                ':ti' => isset($opts['target_id']) ? mb_substr((string)$opts['target_id'], 0, 64, 'UTF-8') : null,
                ':tn' => isset($opts['target_name']) ? mb_substr((string)$opts['target_name'], 0, 255, 'UTF-8') : null,
                ':sm' => isset($opts['summary']) ? mb_substr((string)$opts['summary'], 0, 255, 'UTF-8') : null,
                ':ch' => $changes !== null ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
                ':ip' => !empty($opts['skip_ip']) ? null : (mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45, 'UTF-8') ?: null),
                ':ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500, 'UTF-8') ?: null,
                ':dn' => DeviceController::parseDeviceName($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            error_log('[ActivityLog] 写入失败: ' . $e->getMessage());
        }
    }

    /**
     * 只保留真正发生变化的字段，输出 ['字段' => ['old' => .., 'new' => ..]]
     *
     * @param array $fields 允许比较的字段白名单；敏感字段名会被丢弃
     */
    public static function diff(array $old, array $new, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (in_array(strtolower($field), self::SENSITIVE, true)) {
                continue;
            }
            $before = $old[$field] ?? null;
            $after  = $new[$field] ?? null;
            if ((string)$before === (string)$after) {
                continue;
            }
            $out[$field] = ['old' => $before, 'new' => $after];
        }
        return $out;
    }

    /**
     * @param array{actor_type?:string, actor_id?:int, actor?:string, category?:string, action?:string,
     *              target?:string, keyword?:string, from?:string, to?:string} $filter
     */
    private static function buildWhere(array $filter): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filter['actor_type'])) {
            $where[] = 'actor_type = :actor_type';
            $params[':actor_type'] = $filter['actor_type'];
        }
        if (!empty($filter['actor_id'])) {
            $where[] = 'actor_id = :actor_id';
            $params[':actor_id'] = (int)$filter['actor_id'];
        }
        if (!empty($filter['actor'])) {
            // 执行者可以写用户名，也可以直接贴 IP
            $where[] = '(actor_username = :actor OR ip_address = :actor_ip)';
            $params[':actor'] = (string)$filter['actor'];
            $params[':actor_ip'] = (string)$filter['actor'];
        }
        if (!empty($filter['category'])) {
            $where[] = 'category = :category';
            $params[':category'] = $filter['category'];
        }
        if (!empty($filter['action'])) {
            $where[] = 'action = :action';
            $params[':action'] = $filter['action'];
        }
        if (!empty($filter['target'])) {
            $where[] = '(target_name LIKE :target_name OR target_id = :target_id)';
            $params[':target_name'] = '%' . $filter['target'] . '%';
            $params[':target_id'] = (string)$filter['target'];
        }
        if (!empty($filter['from'])) {
            $where[] = 'created_at >= :from';
            $params[':from'] = $filter['from'] . ' 00:00:00';
        }
        if (!empty($filter['to'])) {
            $where[] = 'created_at <= :to';
            $params[':to'] = $filter['to'] . ' 23:59:59';
        }
        if (!empty($filter['keyword'])) {
            $where[] = '(summary LIKE :kw OR target_name LIKE :kw2 OR actor_username LIKE :kw3)';
            $kw = '%' . $filter['keyword'] . '%';
            $params[':kw'] = $kw;
            $params[':kw2'] = $kw;
            $params[':kw3'] = $kw;
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @return array{items: array, total: int, page: int, pages: int}
     */
    public static function listFor(array $filter, int $page, int $perPage = 30): array
    {
        $page = max(1, $page);
        [$where, $params] = self::buildWhere($filter);

        $stmt = self::db()->prepare("SELECT COUNT(*) FROM activity_logs WHERE {$where}");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $order = ($filter['dir'] ?? '') === 'newer'
            ? 'created_at ASC, id ASC'
            : 'created_at DESC, id DESC';

        $stmt = self::db()->prepare(
            "SELECT * FROM activity_logs WHERE {$where}
             ORDER BY {$order} LIMIT :lim OFFSET :off"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page'  => $page,
            'pages' => max(1, (int)ceil($total / $perPage)),
        ];
    }

    /**
     * 导出用：按筛选条件一次取出，不分页。
     *
     * @return array<int, array>
     */
    public static function allFor(array $filter, int $limit = 50000): array
    {
        [$where, $params] = self::buildWhere($filter);

        $order = ($filter['dir'] ?? '') === 'newer'
            ? 'created_at ASC, id ASC'
            : 'created_at DESC, id DESC';

        $stmt = self::db()->prepare(
            "SELECT * FROM activity_logs WHERE {$where} ORDER BY {$order} LIMIT :lim"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM activity_logs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function recentFor(int $userId, int $limit = 10): array
    {
        $stmt = self::db()->prepare(
            'SELECT * FROM activity_logs WHERE actor_id = :u ORDER BY created_at DESC, id DESC LIMIT :lim'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * 某个时间点之后，该用户是否出现过指定动作之一（恢复流程用来判定「已重置凭据」）
     */
    public static function hasActionSince(int $userId, array $actions, int $sinceTimestamp): bool
    {
        if (!$actions) {
            return false;
        }
        $placeholders = [];
        $params = [':u' => $userId, ':since' => date('Y-m-d H:i:s', $sinceTimestamp)];
        foreach (array_values($actions) as $i => $action) {
            $key = ':a' . $i;
            $placeholders[] = $key;
            $params[$key] = $action;
        }

        $stmt = self::db()->prepare(
            'SELECT 1 FROM activity_logs
             WHERE actor_id = :u AND actor_type = \'user\' AND created_at >= :since
               AND action IN (' . implode(', ', $placeholders) . ')
             LIMIT 1'
        );
        $stmt->execute($params);
        return (bool)$stmt->fetch();
    }

    public static function actionLabel(string $action): string
    {
        return self::ACTIONS[$action] ?? $action;
    }

    public static function categoryLabel(string $category): string
    {
        return self::CATEGORIES[$category] ?? $category;
    }

    public static function iconFor(string $category): string
    {
        return self::CATEGORY_ICONS[$category] ?? 'info';
    }

    public static function actorLabel(array $row): string
    {
        return match ($row['actor_type'] ?? '') {
            'admin'  => '管理员',
            'system' => '系统',
            default  => '用户',
        };
    }
}

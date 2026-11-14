<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

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
        self::CAT_AUTH     => 'Sign-in & account',
        self::CAT_PROFILE  => 'Profile',
        self::CAT_SECURITY => 'Security',
        self::CAT_DEVICE   => 'Device',
        self::CAT_OAUTH    => 'App authorization',
        self::CAT_DEV      => 'Open Platform',
        self::CAT_ACCOUNT  => 'Account deletion',
        self::CAT_RECOVERY => 'Account recovery',
        self::CAT_FAMILY   => 'Family',
        self::CAT_ADMIN    => 'Admin action',
        self::CAT_PAGES    => 'Page',
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
        'auth.register'                   => 'Account created',
        'auth.login'                      => 'Signed in',
        'auth.login_failed'               => 'Sign-in failed',
        'auth.logout'                     => 'Sign out',
        'auth.2fa_passed'                 => 'Two-step verification passed',
        'auth.2fa_failed'                 => 'Two-step verification failed',

        'profile.update'                  => 'Edit profile',
        'profile.avatar'                  => 'Change avatar',

        'pages.create'                    => 'Create page',
        'pages.update'                    => 'Edit page',
        'pages.delete'                    => 'Delete page',

        'security.password_change'        => 'Change password',
        'security.password_reset'         => 'Reset password with code',
        'security.email_change_requested' => 'Request an email change',
        'security.email_changed'          => 'Email change applied',
        'security.email_added'            => 'Add email',
        'security.email_removed'          => 'Remove email',
        'security.email_verified'         => 'Email verification complete',
        'security.totp_enable'            => 'Bind authenticator app',
        'security.totp_disable'           => 'Unbind authenticator app',
        'security.recovery_codes_generate' => 'Generate recovery codes',
        'security.recovery_codes_delete'   => 'Delete recovery codes',
        'security.recovery_code_used'     => 'Use a recovery code',
        'security.2fa_policy'             => 'Change two-step verification policy',
        'security.biometric_add'          => 'Bind biometrics',
        'security.biometric_remove'       => 'Delete biometric',
        'security.temp_key_create'        => 'Create temp key',
        'security.temp_key_delete'        => 'Delete temp key',
        'security.temp_key_used'          => 'Sign in with a temp key',
        'security.notification_settings'  => 'Change notification preferences',

        'device.trust'                    => 'Trust new device',
        'device.revoke'                   => 'Remove device',
        'device.revoke_all'               => 'Revoke all other devices',

        'oauth.authorize'                 => 'Authorized apps',
        'oauth.authorize_silent'          => 'Quick sign-in for authorized apps',
        'oauth.revoke'                    => 'Revoke app authorization',
        'oauth.token_issued'              => 'Token exchange',

        'developer.register'              => 'Join the Open Platform',
        'developer.profile_update'        => 'Update developer profile',
        'developer.app_create'            => 'Create app',
        'developer.app_update'            => 'Update app',
        'developer.app_reset_secret'      => 'Reset app secret',
        'developer.app_delete'            => 'Delete app',

        'admin.app_create'                => 'Admin created app',
        'admin.app_update'                => 'Admin updated app',
        'admin.app_reset_secret'          => 'Admin reset app secret',
        'admin.app_toggle'                => 'Admin enabled or disabled app',
        'admin.app_delete'                => 'Admin deleted app',
        'admin.mount_update'              => 'Changed plugin mount',
        'admin.site_settings'             => 'Changed site settings',
        'admin.cache_clear'               => 'Cleared the cache',
        'admin.homepage_update'           => 'Homepage settings changed',
        'admin.identity_create'           => 'Add sign-in method',
        'admin.identity_update'           => 'Update sign-in method',
        'admin.identity_delete'           => 'Remove sign-in method',
        'admin.identity_toggle'           => 'Enable/disable sign-in method',
        'admin.login_method_toggle'       => 'Turn a sign-in method on or off',
        'admin.login_method_default'      => 'Set the default sign-in method',
        'admin.developer_toggle'          => 'Admin enabled or disabled developer',

        'identity.linked'                 => 'Link third-party account',
        'identity.unlinked'               => 'Unlink third-party account',
        'identity.registered'             => 'Account created via third-party sign-in',

        'account.delete'                  => 'Account deletion',
        'account.reclaim'                 => 'Reclaim unverified email',

        'recovery.submit'                 => 'Submit recovery request',
        'recovery.approved'               => 'Approve recovery request',
        'recovery.rejected'               => 'Reject recovery request',
        'recovery.link_resent'            => 'Resend recovery link',
        'recovery.ticket_used'            => 'Sign in with the recovery link',
        'recovery.completed'              => 'Finish security repair',

        'family.group_created'            => 'Create family',
        'family.group_claimed'            => 'Accept family invitation',
        'family.guardian_added'           => 'Invite parent',
        'family.guardian_invited'         => 'Child invites a parent',
        'family.child_added'              => 'Create child account',
        'family.child_left'               => 'Leave family',
        'family.child_locked'             => 'Lock child account',
        'family.child_unlocked'           => 'Unlock child account',
        'family.child_password_reset'     => 'Reset child\'s password',
        'family.consent_given'            => 'Parent confirmation',
        'family.child_independent'        => 'Supervision ended (own request)',
        'family.child_independent_auto'   => 'Supervision ended (automatic at age)',
        'family.invite_expired'           => 'Family invitation expired',

        'admin.user_disable'              => 'Disable user',
        'admin.user_enable'               => 'Enable user',
        'admin.role_grant'                => 'Make admin',
        'admin.role_revoke'               => 'Revoke admin',
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
                 (unid, actor_type, actor_id, actor_username, action, category, target_type, target_id,
                  target_name, summary, changes, ip_address, user_agent, device_name)
                 VALUES (:unid, :at, :ai, :an, :ac, :cg, :tt, :ti, :tn, :sm, :ch, :ip, :ua, :dn)'
            )->execute([
                ':unid' => Unid::generate(),
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

    public static function findByUnid(string $unid): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM activity_logs WHERE unid = :unid LIMIT 1');
        $stmt->execute([':unid' => $unid]);
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
            'admin'  => 'Administrator',
            'system' => 'System',
            default  => 'User',
        };
    }
}

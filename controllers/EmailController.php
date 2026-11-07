<?php
namespace Nomen\Controllers;

use Nomen\Src\{ActivityLog, Database, EmailSet, FamilyHelper, Features, NotificationHelper, Response, Session};
use PDO;

/**
 * 邮箱验证链接的落点。
 *
 * 单独一个控制器，**不带登录要求**：这封邮件可能在任何浏览器里被点开 ——
 * 家长在自己电脑上点孩子那封、用户换了设备点、或者干脆没登录。凭据只有 URL 里的 token。
 * （放在 DashboardController 里会被它的构造函数直接弹去 /login，那条路就走不通了。）
 */
class EmailController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * GET /settings/emails/verify?token=
     */
    public function verify(): void
    {
        $token = (string)($_GET['token'] ?? '');
        if ($token === '') {
            Response::error(400, t('Invalid verification link'));
        }

        $stmt = $this->db->prepare(
            'SELECT id, user_id, email, verified, verify_token_expires_at FROM user_emails WHERE verify_token = :t LIMIT 1'
        );
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::error(400, t('Invalid verification link'));
        }

        $userId = (int)$row['user_id'];
        $email  = (string)$row['email'];

        if (!empty($row['verified'])) {
            Session::flash('success', t('This address is already confirmed'));
            Response::redirect($this->backFromEmail($userId));
        }

        if (strtotime((string)$row['verify_token_expires_at']) < time()) {
            Response::error(400, t('This verification link has expired — resend it from settings'));
        }

        if (!EmailSet::markVerified($userId, (int)$row['id'])) {
            Response::error(400, t('That email was just claimed by another account (primary and backup emails are each unique), so it can\'t be bound to your account'));
        }

        $stmt = $this->db->prepare('SELECT username FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $username = (string)($stmt->fetchColumn() ?: '');

        ActivityLog::record([
            'action'         => 'security.email_verified',
            'category'       => ActivityLog::CAT_SECURITY,
            'actor_type'     => 'user',
            'actor_id'       => $userId,
            'actor_username' => $username,
            'target_type'    => 'user_email',
            'target_id'      => (int)$row['id'],
            'target_name'    => $email,
            'summary'        => '邮箱验证完成：' . $email,
        ]);

        // 孩子自己绑了邮箱：知会监护人一声
        if (Features::guardians() && FamilyHelper::childMembership($userId)) {
            foreach (FamilyHelper::guardianAccountsOfChild($userId) as $guardian) {
                NotificationHelper::notify(
                    (int)$guardian['user_id'],
                    NotificationHelper::TYPE_SYSTEM_IMPORTANT,
                    '孩子账号绑定了新邮箱',
                    '账号 ' . $username . ' 绑定了新邮箱 ' . $email . '。',
                    ['link' => '/family', 'dedupe_key' => 'family_child_email:' . $userId . ':' . md5($email)]
                );
            }
        }

        Session::flash('success', t('{:email} confirmed', ['email' => $email]));
        Response::redirect($this->backFromEmail($userId));
    }

    /** 本人已登录就回设置页，否则回登录页（点链接的人不一定是账号主，别把人塞进别人的会话） */
    private function backFromEmail(int $userId): string
    {
        return (int)Session::get('user_id') === $userId ? '/settings' : '/login';
    }
}

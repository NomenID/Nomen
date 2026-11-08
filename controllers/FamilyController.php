<?php
namespace Nomen\Controllers;

use Nomen\Src\{ActivityLog, AppData, Csrf, Database, Email, EmailTemplate, EmailPolicy, FamilyHelper, I18n, NotificationHelper, RateLimiter, RecoveryHelper, Response, Session, VerifyCode};
use PDO;

/**
 * 家庭：孩子账号 + 家长账号的关系。
 *
 * 家长能做的只有两样 —— 重置孩子密码、临时锁定账号（2026-10-10 定的口径，别扩成"完全托管"）。
 * 停止监护是孩子自己的动作，家长没有反向解除的入口。
 * 对外一律用「家庭 / 家长 / 接受邀请 / 邀请中 / 停止监护」，别把内部叫法（家庭组、认领、绑定）写进页面。
 */
class FamilyController
{
    private PDO $db;

    /** 家长一天最多新建几个孩子账号 */
    private const CHILD_CREATE_PER_DAY = 5;

    private const GENDERS = ['female', 'male', 'non-binary', 'prefer-not-to-say'];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        $user = $this->currentUser();

        // 真 SPA：壳只出挂载点 + props（AppData 与 /api/app 端点共用同一份构建）
        $appProps = AppData::shell((int)$user['id'], AppData::family((int)$user['id']));
        Response::view('app-shell', ['appProps' => $appProps, 'title' => t('Family')], 'app');
    }

    // ========== 家长侧：接受邀请 ==========

    /** 账号邮箱就是被邀请的那条邮箱 —— 一键加入，不用再证明一次 */
    public function accept(): void
    {
        $user = $this->currentUser();
        $this->checkCsrf();

        $email = FamilyHelper::normalizeEmail((string)$user['email']);
        if ($email === '') {
            $this->back(t('Your account has no email yet — bind one in settings first'));
        }
        if (!FamilyHelper::claimableGroupsForEmail($email)) {
            $this->back(t('No family invitation found for this address'));
        }

        $joined = FamilyHelper::claimByGuardianEmail($email, (int)$user['id']);
        if ($joined === 0) {
            $this->back(t('Could not join — please try again later'));
        }

        $this->logClaimed($user, $email, $joined);
        $this->notifyChildren($user, $email);
        $this->back('', t('You have joined {:joined} families', ['joined' => $joined]));
    }

    /** 邀请发给了另一条邮箱（账号邮箱不是它）：用验证码证明那条邮箱是你的 */
    public function claimSend(): void
    {
        $user = $this->currentUser();
        $this->checkCsrf();

        $email = FamilyHelper::normalizeEmail((string)($_POST['guardian_email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->back(t('Enter a valid email address'));
        }
        if (EmailPolicy::isBlocked($email)) {
            $this->back(t(EmailPolicy::REJECT_MESSAGE));
        }
        if ($email === FamilyHelper::normalizeEmail((string)$user['email'])) {
            $this->back(t('This address is already on your account — just use the Join button above'));
        }
        if (!FamilyHelper::claimableGroupsForEmail($email)) {
            $this->back(t('No family invitation found for this address'));
        }

        $rate = RateLimiter::check('family:claim:' . $user['id'], 5, 86400);
        if (!$rate['allowed']) {
            $this->back(t('Sending too often — please try again later'));
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        if (!VerifyCode::issue(VerifyCode::FAMILY_CLAIM, (int)$user['id'], $code)) {
            $this->back(t('Service temporarily unavailable, try again later'));
        }

        $locale = 'zh-hans'; // 收件人是被邀请的家长（未必有账号），保持中文
        $html = $this->codeEmail(
            I18n::tIn($locale, '家庭邀请验证码'),
            I18n::tIn($locale, '加入家庭'),
            $code,
            I18n::tIn($locale, '有人正在用这条邮箱接受一个 {:sitename} 家庭邀请。如果这不是你本人操作，请忽略本邮件。'),
            $locale
        );
        if (!Email::send($email, EmailTemplate::subjectPrefix() . I18n::tIn($locale, '家庭邀请验证码'), $html)) {
            $this->back(t('Email failed to send, try again later'));
        }

        Session::set('family_claim_email', $email);
        $this->back('', t('A code was sent to {:email} — valid for 10 minutes', ['email' => RecoveryHelper::maskEmail($email)]));
    }

    public function claimVerify(): void
    {
        $user = $this->currentUser();
        $this->checkCsrf();

        $email = (string)Session::get('family_claim_email', '');
        if ($email === '') {
            $this->back(t('Request a code first'));
        }

        $code = trim((string)($_POST['code'] ?? ''));
        switch (VerifyCode::check(VerifyCode::FAMILY_CLAIM, (int)$user['id'], $code)) {
            case 'unavailable':
                $this->back(t('Service temporarily unavailable, try again later'));
                return;
            case 'expired':
                $this->back(t('The code has expired — request a new one'));
                return;
            case 'mismatch':
                $this->back(t('Wrong code'));
                return;
        }
        VerifyCode::clear(VerifyCode::FAMILY_CLAIM, (int)$user['id']);

        if (!FamilyHelper::claimableGroupsForEmail($email)) {
            Session::remove('family_claim_email');
            $this->back(t('This invitation has already been accepted'));
        }

        $joined = FamilyHelper::claimByGuardianEmail($email, (int)$user['id']);
        if ($joined === 0) {
            Session::remove('family_claim_email');
            $this->back(t('Could not join — please try again later'));
        }

        Session::remove('family_claim_email');
        $this->logClaimed($user, $email, $joined);
        $this->notifyChildren($user, $email);
        $this->back('', t('You have joined {:joined} families', ['joined' => $joined]));
    }

    // ========== 家长侧：管理 ==========

    public function guardianAdd(): void
    {
        $user = $this->currentUser();
        $this->checkCsrf();

        $groupId = $this->guardianGroupOrFail((int)$user['id'], (int)($_POST['group_id'] ?? 0));

        $email = FamilyHelper::normalizeEmail((string)($_POST['guardian_email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->back(t('Enter a valid email address'));
        }
        if (EmailPolicy::isBlocked($email)) {
            $this->back(t(EmailPolicy::REJECT_MESSAGE));
        }
        if ($email === FamilyHelper::normalizeEmail((string)$user['email'])) {
            $this->back(t('This is already your own email'));
        }

        $relationship = (string)($_POST['relationship'] ?? 'other');
        $memberId = FamilyHelper::addPendingGuardian($groupId, $email, $relationship);

        ActivityLog::record([
            'action'   => 'family.guardian_added',
            'category' => ActivityLog::CAT_FAMILY,
            'actor_type' => 'user',
            'actor_id' => (int)$user['id'],
            'actor_username' => (string)$user['username'],
            'target_type' => 'family_group',
            'target_id' => $groupId,
            'target_name' => $email,
            'summary'  => '邀请家长 ' . $email,
            'changes'  => ['new_member_id' => ['old' => null, 'new' => $memberId]],
        ]);

        $this->back('', t('Invited {:email}. Ask them to sign up or sign in to {:sitename} with this address, then accept the invitation on the Family page.', ['email' => $email]));
    }

    public function childCreate(): void
    {
        $user = $this->currentUser();
        $this->checkCsrf();

        $groupId = $this->guardianGroupOrFail((int)$user['id'], (int)($_POST['group_id'] ?? 0));

        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
        $birthDate = trim((string)($_POST['birth_date'] ?? ''));
        $gender = (string)($_POST['gender'] ?? '');

        if (!preg_match('/^[a-z0-9_]{3,32}$/', $username)) {
            $this->back(t('Usernames may only contain lowercase letters, digits and underscores, 3–32 characters'));
        }
        if (strlen($password) < 8 || !preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $this->back(t('Passwords need at least 8 characters, including both letters and digits'));
        }
        if ($password !== $passwordConfirm) {
            $this->back(t('The passwords you entered don\'t match'));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
            $this->back(t('Enter a valid date of birth'));
        }
        $parts = explode('-', $birthDate);
        if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])
            || (int)$parts[0] < 1920 || (int)$parts[0] > (int)date('Y')) {
            $this->back(t('Enter a valid date of birth'));
        }
        if (!in_array($gender, self::GENDERS, true)) {
            $this->back(t('Pick a gender'));
        }

        // 家长建的是未成年账号：到了可以自己注册的年龄就该自己去注册
        $country = (string)($user['country'] ?? '');
        $consentAge = FamilyHelper::consentAgeFor($country);
        $age = FamilyHelper::ageOf($birthDate);
        if ($age === null || $age >= $consentAge) {
            $this->back(t('At this age they can register an account on their own — have them use the sign-up page'));
        }

        $rate = RateLimiter::check('family:child:' . $user['id'], self::CHILD_CREATE_PER_DAY, 86400);
        if (!$rate['allowed']) {
            $this->back(t('Too many child accounts were created today — please try again tomorrow'));
        }

        $stmt = $this->db->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        if ($stmt->fetch()) {
            $this->back(t('That username is taken — try another one'));
        }

        $config = require __DIR__ . '/../config/app.php';
        $childId = 0;
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO users (username, email, password_hash, display_name, email_verified, country, birth_date, gender, locale)
                 VALUES (:u, NULL, :p, :d, 1, :c, :bd, :g, :loc)'
            );
            $stmt->execute([
                ':u'  => $username,
                ':p'  => password_hash($password . ($config['pepper'] ?? ''), PASSWORD_ARGON2ID),
                ':d'  => $username,
                ':c'  => $country,
                ':bd' => $birthDate,
                ':g'  => $gender,
                ':loc' => \Nomen\Src\I18n::locale(),
            ]);
            $childId = (int)$this->db->lastInsertId();
        } catch (\PDOException $e) {
            $this->back(t('Creation failed — the username may have just been taken, try another one'));
        }

        FamilyHelper::addChildMember($groupId, $childId, FamilyHelper::MEMBER_ACTIVE, true);
        FamilyHelper::refreshGroupStatus($groupId);

        ActivityLog::record([
            'action'   => 'family.child_added',
            'category' => ActivityLog::CAT_FAMILY,
            'actor_type' => 'user',
            'actor_id' => (int)$user['id'],
            'actor_username' => (string)$user['username'],
            'target_type' => 'user',
            'target_id' => $childId,
            'target_name' => $username,
            'summary'  => '创建孩子账号 ' . $username,
            'changes'  => [
                'username'   => ['old' => null, 'new' => $username],
                'birth_date' => ['old' => null, 'new' => $birthDate],
                'gender'     => ['old' => null, 'new' => $gender],
                'country'    => ['old' => null, 'new' => $country],
            ],
        ]);

        NotificationHelper::notify(
            $childId,
            NotificationHelper::TYPE_SYSTEM_IMPORTANT,
            '家长为你创建了账号',
            '家长 ' . $user['username'] . ' 为你创建了账号 ' . $username . '。建议你登录后到设置里改掉初始密码。',
            ['link' => '/settings']
        );

        $this->back('', t('Child account {:username} created. Hand the username and initial password to them — they can change the password after signing in.', ['username' => $username]));
    }

    // ========== 家长侧：对孩子的两个操作 ==========

    public function childStatus(): void
    {
        $user = $this->currentUser();
        $this->checkCsrf();

        $childId = (int)($_POST['child_id'] ?? 0);
        $lock = ($_POST['op'] ?? '') === 'lock';
        $member = FamilyHelper::childOfGuardian((int)$user['id'], $childId);
        if (!$member) {
            $this->back(t('You do not have permission to manage this account'));
        }

        $this->db->prepare('UPDATE users SET status = :st WHERE id = :id')
            ->execute([':st' => $lock ? 0 : 1, ':id' => $childId]);

        if ($lock) {
            // 锁定要立刻生效，不能等这孩子下次登录
            Session::destroyOtherSessionsFor($childId);
        }

        ActivityLog::record([
            'action'   => $lock ? 'family.child_locked' : 'family.child_unlocked',
            'category' => ActivityLog::CAT_FAMILY,
            'actor_type' => 'user',
            'actor_id' => (int)$user['id'],
            'actor_username' => (string)$user['username'],
            'target_type' => 'user',
            'target_id' => $childId,
            'target_name' => (string)($member['username'] ?? ''),
            'summary'  => ($lock ? '锁定' : '解锁') . '孩子账号',
        ]);

        $this->back('', $lock ? t('The account is locked — they are signed out immediately and cannot sign in.') : t('The account has been unlocked.'));;
    }

    public function childResetSend(): void
    {
        $user = $this->currentUser();
        $this->checkCsrf();

        $childId = (int)($_POST['child_id'] ?? 0);
        $member = FamilyHelper::childOfGuardian((int)$user['id'], $childId);
        if (!$member) {
            $this->back(t('You do not have permission to manage this account'));
        }

        $contact = FamilyHelper::contactEmail($user);
        if ($contact === null) {
            $this->back(t('Your account has no usable contact address'));
        }

        $rate = RateLimiter::check('family:reset:' . $user['id'], 5, 86400);
        if (!$rate['allowed']) {
            $this->back(t('Sending too often — please try again later'));
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        if (!VerifyCode::issue(VerifyCode::FAMILY_RESET, $childId, $code)) {
            $this->back(t('Service temporarily unavailable, try again later'));
        }

        $locale = I18n::emailLocale((int)$user['id']);
        $html = $this->codeEmail(
            I18n::tIn($locale, '为孩子重置密码的验证码'),
            I18n::tIn($locale, 'Reset child\'s password'),
            $code,
            I18n::tIn($locale, '验证码发到了你（家长）的邮箱上，用它在「家庭」页面给孩子设置新密码。'),
            $locale
        );
        if (!Email::send($contact, EmailTemplate::subjectPrefix() . I18n::tIn($locale, 'Reset child\'s password'), $html)) {
            $this->back(t('Email failed to send, try again later'));
        }

        Session::set('family_reset_child', $childId);
        $this->back('', t('A code was sent to {:email} — valid for 10 minutes', ['email' => RecoveryHelper::maskEmail($contact)]));
    }

    public function childResetVerify(): void
    {
        $user = $this->currentUser();
        $this->checkCsrf();

        $childId = (int)Session::get('family_reset_child', 0);
        if ($childId === 0) {
            $this->back(t('Request a code first'));
        }

        $member = FamilyHelper::childOfGuardian((int)$user['id'], $childId);
        if (!$member) {
            Session::remove('family_reset_child');
            $this->back(t('You do not have permission to manage this account'));
        }

        $code = trim((string)($_POST['code'] ?? ''));
        $password = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (strlen($password) < 8 || !preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $this->back(t('The new password needs at least 8 characters, including both letters and digits'));
        }
        if ($password !== $confirm) {
            $this->back(t('The passwords you entered don\'t match'));
        }

        switch (VerifyCode::check(VerifyCode::FAMILY_RESET, $childId, $code)) {
            case 'unavailable':
                $this->back(t('Service temporarily unavailable, try again later'));
                return;
            case 'expired':
                $this->back(t('The code has expired — request a new one'));
                return;
            case 'mismatch':
                $this->back(t('Wrong code'));
                return;
        }
        VerifyCode::clear(VerifyCode::FAMILY_RESET, $childId);

        $config = require __DIR__ . '/../config/app.php';
        $this->db->prepare('UPDATE users SET password_hash = :p WHERE id = :id')
            ->execute([':p' => password_hash($password . ($config['pepper'] ?? ''), PASSWORD_ARGON2ID), ':id' => $childId]);

        // 密码变了就把孩子的其他会话踢掉，别让旧会话继续挂着
        Session::destroyOtherSessionsFor($childId);

        Session::remove('family_reset_child');

        ActivityLog::record([
            'action'   => 'family.child_password_reset',
            'category' => ActivityLog::CAT_FAMILY,
            'actor_type' => 'user',
            'actor_id' => (int)$user['id'],
            'actor_username' => (string)$user['username'],
            'target_type' => 'user',
            'target_id' => $childId,
            'target_name' => (string)($member['username'] ?? ''),
            'summary'  => '家长重置了孩子账号的密码',
        ]);

        NotificationHelper::notify(
            $childId,
            NotificationHelper::TYPE_SYSTEM_IMPORTANT,
            '密码已被家长重置',
            '家长 ' . $user['username'] . ' 重置了你的登录密码，其他设备上的登录已失效。',
            ['link' => '/family']
        );

        $this->back('', t('The password for {:username} has been reset — tell them the new password.', ['username' => (string)($member['username'] ?? '')]));
    }

    // ========== 孩子侧 ==========

    /** 孩子邀请一位家长（填家长邮箱）。填自己的邮箱注册的孩子靠这个把家长拉进来 */
    public function inviteParent(): void
    {
        $user = $this->currentUser();
        $this->checkCsrf();

        $email = FamilyHelper::normalizeEmail((string)($_POST['guardian_email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->back(t('Enter a valid email address'));
        }
        if (EmailPolicy::isBlocked($email)) {
            $this->back(t(EmailPolicy::REJECT_MESSAGE));
        }
        if ($email === FamilyHelper::normalizeEmail((string)$user['email'])) {
            $this->back(t('This is your own email — for a parent, enter theirs'));
        }

        $rate = RateLimiter::check('family:invite:' . $user['id'], 5, 86400);
        if (!$rate['allowed']) {
            $this->back(t('Sending too often — please try again later'));
        }

        $relationship = (string)($_POST['relationship'] ?? 'other');
        if (FamilyHelper::inviteGuardianForChild((int)$user['id'], $email, $relationship) === 0) {
            $this->back(t('This address has already been invited — just wait for them to accept'));
        }

        ActivityLog::record([
            'action'   => 'family.guardian_invited',
            'category' => ActivityLog::CAT_FAMILY,
            'actor_type' => 'user',
            'actor_id' => (int)$user['id'],
            'actor_username' => (string)$user['username'],
            'target_type' => 'guardian_email',
            'target_name' => $email,
            'summary'  => '邀请家长 ' . $email,
        ]);

        // 邀请邮件不给 token：家长要用这条邮箱注册/登录后在「家庭」页面接受，
        // 这样他必须真的控制那条邮箱，而我们也少一套一次性链接
        $locale = 'zh-hans'; // 收件人是被邀请的家长（未必有账号），保持中文
        $x = fn(string $k, array $p = []): string => I18n::tIn($locale, $k, $p);
        $html = EmailTemplate::render([
            'preheader' => $x('{:name} invited you to be their parent', ['name' => $user['username']]),
            'badge'     => ['text' => 'Parent invitation', 'tone' => 'general'],
            'title'     => $x('A child invited you to be their parent'),
            'subtitle'  => $x('{:name} invited you to be their parent', ['name' => $user['username']]),
            'blocks'    => [
                ['type' => 'info', 'items' => [['label' => 'Child account', 'value' => (string)$user['username']]]],
                ['type' => 'paragraph', 'text' => $x('Sign up or sign in to {:sitename} with this email, then accept the invitation on the Family page.')],
                ['type' => 'paragraph', 'muted' => true, 'text' => $x('After joining, you can reset their sign-in password and temporarily lock the account; you cannot see their password or read their messages.')],
                ['type' => 'paragraph', 'muted' => true, 'text' => $x('If this is not an account you know, just ignore this email.')],
            ],
            'button'    => ['text' => 'Open {:sitename}', 'url' => EmailTemplate::url('/family')],
        ], $locale);
        Email::send($email, EmailTemplate::subjectPrefix() . $x('Parent invitation'), $html);

        $this->back('', t('An invitation was sent to {:email}. Once they sign up or sign in with this address, they can join from the Family page.', ['email' => RecoveryHelper::maskEmail($email)]));
    }

    /** 停止监护：满了本地区的独立使用年龄就能自己停 */
    public function release(): void
    {
        $user = $this->currentUser();
        $this->checkCsrf();

        $check = FamilyHelper::canRelease($user);
        if (!$check['ok']) {
            $this->back(t(FamilyHelper::RELEASE_REASONS[$check['reason']] ?? '现在还不能停止监护'));
        }

        $rate = RateLimiter::check('family:release:' . $user['id'], FamilyHelper::RELEASE_REQUESTS_PER_DAY, 86400);
        if (!$rate['allowed']) {
            $this->back(t('Too many attempts — please try again tomorrow'));
        }

        $guardians = FamilyHelper::guardianAccountsOfChild((int)$user['id']);
        FamilyHelper::markReleaseRequested((int)$user['id']);
        FamilyHelper::markIndependent((int)$user['id'], 'self');

        ActivityLog::record([
            'action'   => 'family.child_independent',
            'category' => ActivityLog::CAT_FAMILY,
            'actor_type' => 'user',
            'actor_id' => (int)$user['id'],
            'actor_username' => (string)$user['username'],
            'target_type' => 'user',
            'target_id' => (int)$user['id'],
            'summary'  => '满了本地区的同意年龄，主动停止监护',
        ]);

        foreach ($guardians as $guardian) {
            NotificationHelper::notify(
                (int)$guardian['user_id'],
                NotificationHelper::TYPE_SYSTEM_IMPORTANT,
                '监护已停止',
                '账号 ' . $user['username'] . ' 已达到本地区可以独立使用账号的年龄，现在起你不能再重置密码或锁定它。TA 仍然留在你的家庭里。',
                ['link' => '/family']
            );
        }

        $this->back('', t('Supervision has ended. Parents can no longer reset your password or lock your account; you can leave the family on this page.'));
    }

    public function leave(): void
    {
        $user = $this->currentUser();
        $this->checkCsrf();

        $membership = FamilyHelper::childMembership((int)$user['id']);
        if (!$membership) {
            $this->back(t('You have not joined any family yet'));
        }
        if ($membership['status'] !== FamilyHelper::MEMBER_INDEPENDENT) {
            $this->back(t('End supervision first — only then can you leave the family'));
        }

        $guardians = FamilyHelper::guardianAccountsOfChild((int)$user['id']);
        FamilyHelper::removeChildMember((int)$user['id']);

        ActivityLog::record([
            'action'   => 'family.child_left',
            'category' => ActivityLog::CAT_FAMILY,
            'actor_type' => 'user',
            'actor_id' => (int)$user['id'],
            'actor_username' => (string)$user['username'],
            'target_type' => 'family_group',
            'target_id' => (int)$membership['group_id'],
            'summary'  => '退出家庭',
        ]);

        foreach ($guardians as $guardian) {
            NotificationHelper::notify(
                (int)$guardian['user_id'],
                NotificationHelper::TYPE_SYSTEM_GENERAL,
                '孩子退出了家庭',
                '账号 ' . $user['username'] . ' 已退出你的家庭。',
                ['link' => '/family']
            );
        }

        $this->back('', t('You have left the family.'));
    }

    // ========== 私有 ==========

    /** AppData（真 SPA 数据源）的公开包装 */
    public static function userDataFor(int $userId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, username, email, email_verified, birth_date, country, status, role, created_at
               FROM users WHERE id = :id'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) {
            Session::destroy();
            Response::redirect('/login');
        }
        return $user;
    }

    private function currentUser(): array
    {
        $userId = (int)Session::get('user_id');
        $stmt = $this->db->prepare(
            'SELECT id, username, email, email_verified, birth_date, country, status, role, created_at
               FROM users WHERE id = :id'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) {
            Session::destroy();
            Response::redirect('/login');
        }
        return $user;
    }

    private function checkCsrf(): void
    {
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $this->back(t('Invalid request, try again'));
        }
    }

    /** 校验这个账号确实是该家庭的家长，返回家庭 id */
    private function guardianGroupOrFail(int $userId, int $groupId): int
    {
        foreach (FamilyHelper::groupsOfGuardian($userId) as $group) {
            if ((int)$group['id'] === $groupId) {
                return $groupId;
            }
        }
        $this->back(t('You do not have permission to manage this family'));
        return 0;
    }

    private function back(string $error = '', string $success = ''): void
    {
        // 真 SPA：AJAX 提交直接回 JSON + 最新页面数据，前端原地更新不再整页跳
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            $userId = (int)Session::get('user_id');
            Response::json([
                'success' => $success !== '',
                'message' => $success !== '' ? $success : ($error !== '' ? $error : t('Done')),
                'data' => AppData::family($userId)['data'],
            ]);
        }
        if ($error !== '') {
            Session::flash('error', $error);
        }
        if ($success !== '') {
            Session::flash('success', $success);
        }
        Response::redirect('/family');
    }

    private function logClaimed(array $user, string $email, int $joined): void
    {
        ActivityLog::record([
            'action'   => 'family.group_claimed',
            'category' => ActivityLog::CAT_FAMILY,
            'actor_type' => 'user',
            'actor_id' => (int)$user['id'],
            'actor_username' => (string)$user['username'],
            'target_type' => 'guardian_email',
            'target_name' => $email,
            'summary'  => '接受家庭邀请，加入 ' . $joined . ' 个家庭',
        ]);
    }

    /** 孩子收到「家长加入」的通知 */
    private function notifyChildren(array $user, string $email): void
    {
        $stmt = $this->db->prepare(
            'SELECT child.user_id
               FROM family_members g
               JOIN family_members child ON child.group_id = g.group_id AND child.kind = :ck
              WHERE g.kind = :gk AND g.email = :e AND g.user_id = :uid
                AND child.status <> :removed AND child.user_id IS NOT NULL'
        );
        $stmt->execute([
            ':gk'      => FamilyHelper::KIND_GUARDIAN,
            ':ck'      => FamilyHelper::KIND_CHILD,
            ':e'       => FamilyHelper::normalizeEmail($email),
            ':uid'     => (int)$user['id'],
            ':removed' => FamilyHelper::MEMBER_REMOVED,
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $childId) {
            NotificationHelper::notify(
                (int)$childId,
                NotificationHelper::TYPE_SYSTEM_IMPORTANT,
                '家长已加入你的家庭',
                '家长 ' . $user['username'] . ' 已加入你的家庭，之后可以帮你重置密码、临时锁定账号。',
                ['link' => '/family']
            );
        }
    }

    private function codeEmail(string $title, string $label, string $code, string $footnote, string $locale = 'zh-hans'): string
    {
        $x = fn(string $k): string => I18n::tIn($locale, $k);
        return EmailTemplate::render([
            'preheader' => $title . '：' . $code,
            'badge'     => ['text' => 'Verification code', 'tone' => 'general'],
            'title'     => $title,
            'subtitle'  => $label,
            'blocks'    => [
                ['type' => 'info', 'items' => [
                    ['label' => $x('Code'), 'value' => $code],
                    ['label' => $x('Valid for'), 'value' => $x('10 minutes')],
                ]],
                ['type' => 'paragraph', 'muted' => true, 'text' => $footnote],
            ],
        ], $locale);
    }
}

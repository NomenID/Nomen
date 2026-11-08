<?php
namespace Nomen\Controllers;

use Nomen\Src\{ActivityLog, Database, Session, Csrf, Response, Email, EmailTemplate, I18n, RateLimiter, FamilyHelper, EmailSet, Features};
use PDO;

class ActivationController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 激活页面
     */
    public function activate(): void
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            Response::redirect('/login');
        }

        // 检查用户是否已验证
        $stmt = $this->db->prepare('SELECT id, email, email_verified, birth_date, country FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            Session::flash('error', t('User not found'));
            Response::redirect('/login');
        }

        if ($user['email_verified']
            || (Features::guardians() && FamilyHelper::guardianConfirmed((int)$userId))) {
            Response::redirect('/dashboard');
        }

        $error = Session::getFlash('error');
        $success = Session::getFlash('success');

        // 未成年账号没有自己的邮箱，这里显示的是家长邮箱
        $email = (string)(FamilyHelper::contactEmail($user) ?? '');
        $is_minor = Features::guardians() && FamilyHelper::isMinorRow($user);
        $uses_guardian_address = Features::guardians() && empty($user['email']);

        Response::view('activate', compact('error', 'success', 'email', 'is_minor', 'uses_guardian_address'));
    }

    /**
     * 发送验证邮件
     */
    public function sendVerification(): void
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            Response::redirect('/login');
        }

        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            if ($isAjax) Response::json(['success' => false, 'message' => t('Invalid request, try again')]);
            Session::flash('error', t('Invalid request, try again'));
            Response::redirect('/activate');
        }

        // 检查是否已验证
        $stmt = $this->db->prepare('SELECT id, username, email, email_verified, birth_date, country, gender FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            if ($isAjax) {
                Response::json(['success' => false, 'message' => t('User not found')]);
            }
            Session::flash('error', t('User not found'));
            Response::redirect('/login');
        }

        if ($user['email_verified']
            || (Features::guardians() && FamilyHelper::guardianConfirmed((int)$userId))) {
            if ($isAjax) {
                Response::json(['success' => true, 'message' => t('Email verified')]);
            }
            Response::redirect('/dashboard');
        }

        // 频率限制检查
        $rateCheck = RateLimiter::checkEmailRate($userId);
        if (!$rateCheck['allowed']) {
            if ($isAjax) {
                Response::json(['success' => false, 'message' => $rateCheck['message'], 'retry_after' => $rateCheck['retry_after']]);
            }
            Session::flash('error', $rateCheck['message']);
            Response::redirect('/activate');
        }

        // 生成验证 token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        // 存储 token
        $stmt = $this->db->prepare(
            'UPDATE users SET email_verify_token = :token, email_verify_token_expires_at = :expires, email_verify_last_sent_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            ':token'   => $token,
            ':expires' => $expiresAt,
            ':id'      => $userId,
        ]);

        // 构建验证链接
        $config = require __DIR__ . '/../config/app.php';
        $baseUrl = rtrim($config['base_url'], '/');
        $verifyUrl = "{$baseUrl}/activate/verify?token={$token}";

        // 判断是否未成年
        $is_minor = Features::guardians() && FamilyHelper::isMinorRow($user);
        $contact  = FamilyHelper::contactEmail($user);
        // 账号没有自己的邮箱 → 这个地址是家长的，邮件要按"请确认"的口吻写给家长
        $usesGuardianAddress = Features::guardians() && empty($user['email']);

        // 理论上到不了：没有自有邮箱的账号，家庭里一定有一条家长邮箱。真遇到说明成员行被删了
        if ($contact === null) {
            if ($isAjax) {
                Response::json(['success' => false, 'message' => t('The account has no usable mailbox — contact support')]);
            }
            Session::flash('error', t('The account has no usable mailbox — contact support (id@caellab.com)'));
            Response::redirect('/activate');
        }

        // 发送邮件
        if ($usesGuardianAddress) {
            $locale = 'zh-hans'; // 收件人是家长（还没有账号），保持中文
            $subject = EmailTemplate::subjectPrefix() . I18n::tIn($locale, 'Parent confirmation');
            $htmlBody = $this->getGuardianConfirmEmailHtml(
                (string)$contact,
                $verifyUrl,
                (string)$user['username'],
                (string)($user['country'] ?? ''),
                (string)($user['gender'] ?? ''),
                $locale
            );
        } else {
            $locale = I18n::locale();
            $subject = EmailTemplate::subjectPrefix() . I18n::tIn($locale, 'Email verification');
            $htmlBody = $this->getVerificationEmailHtml((string)$user['email'], $verifyUrl, $locale);
        }

        if (Email::send($contact, $subject, $htmlBody)) {
            if ($isAjax) {
                Response::json(['success' => true, 'message' => t('Verification email sent — check your inbox (valid for 24 hours)')]);
            }
            Session::flash('success', t('Verification email sent — check your inbox (valid for 24 hours)'));
        } else {
            if ($isAjax) {
                Response::json(['success' => false, 'message' => t('Email failed to send, try again later')]);
            }
            Session::flash('error', t('Email failed to send, try again later'));
        }

        Response::redirect('/activate');
    }

    /**
     * 验证邮箱
     */
    public function verify(): void
    {
        $token = $_GET['token'] ?? '';
        if (!$token) {
            Response::error(400, t('Invalid verification link'));
        }

        // 查找用户
        $stmt = $this->db->prepare(
            'SELECT id, email, email_verified, email_verify_token_expires_at FROM users WHERE email_verify_token = :token'
        );
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error(400, t('Invalid verification link'));
        }

        if ($user['email_verified']) {
            // 收信地址是家长的：点的人不是这个账号的主人，别把他塞进孩子的会话
            if (empty($user['email'])) {
                Session::flash('success', t('This address is already confirmed.'));
                Response::redirect('/login');
            }

            // 已经验证过了，直接登录
            Session::regenerate();
            Session::set('user_id', $user['id']);
            Response::redirect('/dashboard');
        }

        // 检查是否过期
        if (strtotime($user['email_verify_token_expires_at']) < time()) {
            Response::error(400, t('This verification link has expired — send a new one'));
        }

        // 验证成功，更新状态
        $stmt = $this->db->prepare(
            'UPDATE users SET email_verified = 1, email_verify_token = NULL, email_verify_token_expires_at = NULL WHERE id = :id'
        );
        $stmt->execute([':id' => $user['id']]);

        // 激活只改了 users，首选邮箱那一行要跟着标记成已验证
        EmailSet::syncPrimaryFromUsers((int)$user['id']);

        ActivityLog::record([
            'action'   => 'security.email_verified',
            'category' => ActivityLog::CAT_SECURITY,
            'actor_type' => 'user',
            'actor_id' => (int)$user['id'],
            'summary'  => '邮箱验证完成',
        ]);

        // 账号没有自己的邮箱：这一点是家长点的确认，记到家庭上；
        // 如果这个邮箱已经有账号，顺手把 TA 接进家庭（点确认本身已证明控制该邮箱）
        $membership = Features::guardians() ? FamilyHelper::childMembership((int)$user['id']) : null;
        if ($membership) {
            FamilyHelper::recordConsent((int)$user['id']);
            ActivityLog::record([
                'action'   => 'family.consent_given',
                'category' => ActivityLog::CAT_FAMILY,
                'actor_type' => 'user',
                'actor_id' => (int)$user['id'],
                'summary'  => '家长确认了邮箱',
            ]);

            if (FamilyHelper::linkExistingGuardianAccounts((int)$user['id']) > 0) {
                ActivityLog::record([
                    'action'   => 'family.group_claimed',
                    'category' => ActivityLog::CAT_FAMILY,
                    'actor_type' => 'system',
                    'actor_id' => (int)$user['id'],
                    'summary'  => '家长确认时自动接进家庭',
                ]);
            }
        }

        // 收信地址是家长的：点链接的是家长，不是这个账号的主人 —— 不自动登录，
        // 告诉他用这条邮箱登录后到「家庭」页面就能看到孩子
        if (empty($user['email'])) {
            Session::flash('success', t('Email confirmed. If this address already has a {:sitename} account, they appear on your Family page after signing in.'));
            Response::redirect('/login');
        }

        // 自动登录
        Session::regenerate();
        Session::set('user_id', $user['id']);

        Session::flash('success', t('Email verified!'));
        Response::redirect('/dashboard');
    }

    /**
     * 验证邮件 HTML 模板
     */
    private function getVerificationEmailHtml(string $email, string $verifyUrl, string $locale = 'zh-hans'): string
    {
        return EmailTemplate::render([
            'preheader' => 'Confirm your address to activate your {:sitename} account',
            'badge'     => ['text' => 'Account activation', 'tone' => 'general'],
            'title'     => 'Verify your email address',
            'subtitle'  => 'Once confirmed, every feature of {:sitename} works',
            'blocks'    => [
                ['type' => 'info', 'items' => [['label' => 'Verify email', 'value' => $email]]],
                ['type' => 'link', 'label' => 'Verification link', 'url' => $verifyUrl],
                ['type' => 'alert', 'tone' => 'info', 'text' => 'The link is valid for 24 hours. If this wasn\'t you, ignore this email.'],
            ],
            'button'    => ['text' => 'Activate account', 'url' => $verifyUrl],
        ], $locale);
    }

    /**
     * 收信地址是家长邮箱时发的那封：请家长确认「这是我的孩子」。
     *
     * 孩子没有自己的邮箱，这个地址就是他唯一的收信渠道 —— 所以家长点确认之前，
     * 账号停在 /activate 不能用。这封邮件同时让有账号的家长顺手加入家庭
     * （加入后才多出重置密码 / 临时锁定两件事）。
     */
    private function getGuardianConfirmEmailHtml(string $email, string $verifyUrl, string $username, string $country, string $gender, string $locale = 'zh-hans'): string
    {
        $x = fn(string $k, array $p = []): string => I18n::tIn($locale, $k, $p);
        $pronoun     = FamilyHelper::genderPronoun($gender, $locale);
        $genderLabel = FamilyHelper::genderLabel($gender, $locale);
        $countryName = FamilyHelper::countryName($country);

        $infoItems = [];
        if ($username !== '') {
            $infoItems[] = ['label' => $x('Child account'), 'value' => $username];
        }
        $infoItems[] = ['label' => 'Country / Region', 'value' => $countryName];
        $infoItems[] = ['label' => 'Gender', 'value' => $genderLabel];

        $blocks = [['type' => 'info', 'items' => $infoItems]];
        $blocks = array_merge($blocks, [
            ['type' => 'paragraph', 'text' => $username !== ''
                ? $x('Hello, {:name} entered your email address as their contact address when signing up for {:sitename}, so this confirmation email came to you. When signing up they selected {:country} as their country/region and {:gender} as their gender.', ['name' => $username, 'pronoun' => $pronoun, 'country' => $countryName, 'gender' => $genderLabel])
                : $x('Hello, someone entered your email address as their contact address when signing up for {:sitename}, so this confirmation email came to you.')],
        ], FamilyHelper::consentAgeEmailBlocks(FamilyHelper::consentAgeInfo($country), $locale), [
            ['type' => 'paragraph', 'text' => $x('After confirming with the button below: you join their family (if you already have a {:sitename} account you join directly; if not, register with this email and join), and can then reset their sign-in password and temporarily lock the account.')],
            ['type' => 'paragraph', 'muted' => true, 'text' => $x('What parents cannot do: see their password, read their messages or emails, or sign in as them.')],
            ['type' => 'paragraph', 'muted' => true, 'text' => $username !== ''
                ? $x('Until the button is pressed, {:name}\'s account cannot be used yet. After confirmation they can use the account normally and receive notification emails.', ['name' => $username])
                : $x('Until the button is pressed, this account cannot be used yet. After confirmation it works normally and can receive notification emails.')],
            ['type' => 'divider'],
            ['type' => 'paragraph', 'muted' => true, 'text' => $x('If you are not their parent, just ignore this email and the account stays inactive. This link is valid for 24 hours.')],
        ]);

        if (EmailTemplate::privacyUrl() !== '') {
            $blocks[] = ['type' => 'paragraph', 'muted' => true, 'text' => $x('You can read our full privacy policy: {:url}', ['url' => EmailTemplate::privacyUrl()])];
        }

        return EmailTemplate::render([
            'preheader' => 'Please confirm this is your child\'s email',
            'badge'     => ['text' => 'Parent confirmation', 'tone' => 'general'],
            'title'     => 'Confirm this is your child',
            'subtitle'  => $username !== '' ? $x('{:name} entered your email when signing up', ['name' => $username]) : $x('Someone entered your email when signing up'),
            'blocks'    => $blocks,
            'button'    => ['text' => 'Confirm and join the family', 'url' => $verifyUrl],
        ], $locale);
    }

    /**
     * 注销账号（仅限未验证邮箱的用户）
     */
    public function deleteAccount(): void
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            Response::redirect('/login');
            return;
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Session::flash('error', t('Invalid request, try again'));
            Response::redirect('/activate');
            return;
        }

        // 检查用户状态
        $stmt = $this->db->prepare('SELECT email_verified FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            Session::destroy();
            Response::redirect('/login');
            return;
        }

        // 已经激活的账号（邮箱验证过 / 家长那条确认过）不能走这条「未验证账号自清」的路
        if ($user['email_verified']
            || (Features::guardians() && FamilyHelper::guardianConfirmed((int)$userId))) {
            Session::flash('error', t('The account is already active — delete it from settings'));
            Response::redirect('/dashboard');
            return;
        }

        // 删号前先留快照，删完日志里只剩一个不存在的 userId 就没法追溯了
        $stmt = $this->db->prepare('SELECT username, email, country, birth_date, gender FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $snapshot = $stmt->fetch() ?: [];

        ActivityLog::record([
            'action'   => 'account.delete',
            'category' => ActivityLog::CAT_ACCOUNT,
            'actor_type' => 'user',
            'actor_id' => (int)$userId,
            'actor_username' => (string)($snapshot['username'] ?? ''),
            'summary'  => '注销账户（邮箱未验证）',
            'changes'  => [
                'username'   => ['old' => $snapshot['username'] ?? null, 'new' => null],
                'email'      => ['old' => $snapshot['email'] ?? null, 'new' => null],
                'country'    => ['old' => $snapshot['country'] ?? null, 'new' => null],
                'birth_date' => ['old' => $snapshot['birth_date'] ?? null, 'new' => null],
                'gender'     => ['old' => $snapshot['gender'] ?? null, 'new' => null],
            ],
        ]);

        // 家庭组成员行没有指向 users 的外键，删主表不会连带清掉
        if (Features::guardians()) {
            FamilyHelper::removeChildMember((int)$userId);
        }

        // 这几张表同样没有外键，不清就会留下够不着的孤儿行。
        // user_emails 的孤儿最要命：它的唯一索引还在，会把那个地址一直占着
        // （别人想当备选用不了，而它自己已经没有主表行可归属了）。
        foreach (AuthController::ORPHAN_TABLES as $table) {
            $this->db->prepare("DELETE FROM {$table} WHERE user_id = :id")->execute([':id' => $userId]);
        }

        // 删除用户
        $stmt = $this->db->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);

        // 销毁 session
        Session::destroy();

        // 跳转到登录页，带提示
        Response::redirect('/login?deleted=1');
    }
}

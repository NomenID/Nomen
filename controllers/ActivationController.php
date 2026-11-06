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
            Session::flash('error', t('用户不存在'));
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
            if ($isAjax) Response::json(['success' => false, 'message' => t('请求无效，请重试')]);
            Session::flash('error', t('请求无效，请重试'));
            Response::redirect('/activate');
        }

        // 检查是否已验证
        $stmt = $this->db->prepare('SELECT id, username, email, email_verified, birth_date, country, gender FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            if ($isAjax) {
                Response::json(['success' => false, 'message' => t('用户不存在')]);
            }
            Session::flash('error', t('用户不存在'));
            Response::redirect('/login');
        }

        if ($user['email_verified']
            || (Features::guardians() && FamilyHelper::guardianConfirmed((int)$userId))) {
            if ($isAjax) {
                Response::json(['success' => true, 'message' => t('邮箱已验证')]);
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
                Response::json(['success' => false, 'message' => t('账号没有可用的收信地址，请联系支持团队')]);
            }
            Session::flash('error', t('账号没有可用的收信地址，请联系支持团队（id@caellab.com）'));
            Response::redirect('/activate');
        }

        // 发送邮件
        if ($usesGuardianAddress) {
            $locale = 'zh-hans'; // 收件人是家长（还没有账号），保持中文
            $subject = 'Nomen - ' . I18n::tIn($locale, '家长确认');
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
            $subject = 'Nomen - ' . I18n::tIn($locale, '邮箱验证');
            $htmlBody = $this->getVerificationEmailHtml((string)$user['email'], $verifyUrl, $locale);
        }

        if (Email::send($contact, $subject, $htmlBody)) {
            if ($isAjax) {
                Response::json(['success' => true, 'message' => t('验证邮件已发送，请查收（24小时内有效）')]);
            }
            Session::flash('success', t('验证邮件已发送，请查收（24小时内有效）'));
        } else {
            if ($isAjax) {
                Response::json(['success' => false, 'message' => t('邮件发送失败，请稍后重试')]);
            }
            Session::flash('error', t('邮件发送失败，请稍后重试'));
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
            Response::error(400, t('无效的验证链接'));
        }

        // 查找用户
        $stmt = $this->db->prepare(
            'SELECT id, email, email_verified, email_verify_token_expires_at FROM users WHERE email_verify_token = :token'
        );
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error(400, t('无效的验证链接'));
        }

        if ($user['email_verified']) {
            // 收信地址是家长的：点的人不是这个账号的主人，别把他塞进孩子的会话
            if (empty($user['email'])) {
                Session::flash('success', t('这个邮箱已经确认过了。'));
                Response::redirect('/login');
            }

            // 已经验证过了，直接登录
            Session::regenerate();
            Session::set('user_id', $user['id']);
            Response::redirect('/dashboard');
        }

        // 检查是否过期
        if (strtotime($user['email_verify_token_expires_at']) < time()) {
            Response::error(400, t('验证链接已过期，请重新发送'));
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
            Session::flash('success', t('邮箱已确认。用这条邮箱注册过 Nomen 的话，登录后到「家庭」页面就能看到 TA。'));
            Response::redirect('/login');
        }

        // 自动登录
        Session::regenerate();
        Session::set('user_id', $user['id']);

        Session::flash('success', t('邮箱验证成功！'));
        Response::redirect('/dashboard');
    }

    /**
     * 验证邮件 HTML 模板
     */
    private function getVerificationEmailHtml(string $email, string $verifyUrl, string $locale = 'zh-hans'): string
    {
        return EmailTemplate::render([
            'preheader' => '确认你的邮箱地址以激活 Nomen 账户',
            'badge'     => ['text' => '账户激活', 'tone' => 'general'],
            'title'     => '验证你的邮箱地址',
            'subtitle'  => '确认后即可正常使用 Nomen 的全部功能',
            'blocks'    => [
                ['type' => 'info', 'items' => [['label' => '验证邮箱', 'value' => $email]]],
                ['type' => 'link', 'label' => '验证链接', 'url' => $verifyUrl],
                ['type' => 'alert', 'tone' => 'info', 'text' => '此链接 24 小时内有效。如果不是你本人注册，请忽略此邮件。'],
            ],
            'button'    => ['text' => '激活账户', 'url' => $verifyUrl],
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
            $infoItems[] = ['label' => '孩子账号', 'value' => $username];
        }
        $infoItems[] = ['label' => '国家 / 地区', 'value' => $countryName];
        $infoItems[] = ['label' => '性别', 'value' => $genderLabel];

        $blocks = [['type' => 'info', 'items' => $infoItems]];
        $blocks = array_merge($blocks, [
            ['type' => 'paragraph', 'text' => $username !== ''
                ? $x('你好，{:name} 注册 Nomen 时填了你的邮箱作为收信地址，所以这封确认邮件发到了你这里。{:pronoun}在注册时填写的国家 / 地区是{:country}，性别是{:gender}。', ['name' => $username, 'pronoun' => $pronoun, 'country' => $countryName, 'gender' => $genderLabel])
                : $x('你好，有人注册 Nomen 时填了你的邮箱作为收信地址，所以这封确认邮件发到了你这里。')],
        ], FamilyHelper::consentAgeEmailBlocks(FamilyHelper::consentAgeInfo($country), $locale), [
            ['type' => 'paragraph', 'text' => $x('点下面的按钮确认后：你会加入 TA 的家庭（已经有 Nomen 账号的话直接加入，没有的话用这条邮箱注册一个就能加入），之后可以帮 TA 重置登录密码、临时锁定账号。')],
            ['type' => 'paragraph', 'muted' => true, 'text' => $x('家长做不到的事：看不到 TA 的密码，读不到 TA 的消息和邮件，也不能以 TA 的身份登录。')],
            ['type' => 'paragraph', 'muted' => true, 'text' => $username !== ''
                ? $x('在按钮被点击之前，{:name} 的账号还不能开始使用。确认后 TA 就能正常使用账号，也能收到通知邮件。', ['name' => $username])
                : $x('在按钮被点击之前，这个账号还不能开始使用。确认后它就能正常使用，也能收到通知邮件。')],
            ['type' => 'divider'],
            ['type' => 'paragraph', 'muted' => true, 'text' => $x('如果你不是 TA 的家长，忽略这封邮件即可，账号会保持未激活状态。此链接 24 小时内有效。')],
        ]);

        if (EmailTemplate::privacyUrl() !== '') {
            $blocks[] = ['type' => 'paragraph', 'muted' => true, 'text' => $x('你可以阅读我们的完整隐私政策：{:url}', ['url' => EmailTemplate::privacyUrl()])];
        }

        return EmailTemplate::render([
            'preheader' => '请确认这个邮箱是不是你的孩子的',
            'badge'     => ['text' => '家长确认', 'tone' => 'general'],
            'title'     => '确认这是你的孩子',
            'subtitle'  => $username !== '' ? $x('{:name} 注册时填了你的邮箱', ['name' => $username]) : $x('有人注册时填了你的邮箱'),
            'blocks'    => $blocks,
            'button'    => ['text' => '确认并加入家庭', 'url' => $verifyUrl],
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
            Session::flash('error', t('请求无效，请重试'));
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
            Session::flash('error', t('账号已经激活，请在设置中注销'));
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

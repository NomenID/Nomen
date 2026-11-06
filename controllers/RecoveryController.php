<?php
namespace Nomen\Controllers;

use Nomen\Src\{ActivityLog, Csrf, Database, Email, EmailTemplate, EmailPolicy, EmailSet, I18n, RateLimiter, RecoveryHelper, Response, Session, FamilyHelper, Features};
use PDO;

/**
 * 账号恢复：申诉提交 → 管理员审核 → 一次性恢复登录链接 → 强制安全修复。
 */
class RecoveryController
{
    private const GENDERS = [
        'female'           => '女性',
        'male'             => '男性',
        'non-binary'       => '非二元性别',
        'prefer-not-to-say' => '我宁愿不说',
    ];

    private const DESCRIPTION_MAX = 2000;

    /**
     * 描述里要写「账号归属证明」，太短基本等于没写。
     * 这里只是拦住一句话敷衍，真正判断归属的还是人工审核。
     */
    public const DESCRIPTION_MIN = 20;

    private const MAX_ATTACHMENTS = 5;

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    private static function config(): array
    {
        return require __DIR__ . '/../config/app.php';
    }

    // ========== 申诉表单 ==========

    /**
     * GET /recovery
     */
    public function form(): void
    {
        if (Session::get('recovery_mode')) {
            Response::redirect('/recovery/secure');
        }

        $ctx = $this->resolveContext();
        if (!$ctx) {
            Session::flash('error', t('验证会话已过期，请重新登录后再申请恢复'));
            Response::redirect('/login');
        }

        if ($this->deflectMinor($ctx)) {
            return;
        }

        $config = self::config();

        Response::view('recovery', [
            'title'  => '账号恢复申诉',
            'ctx'    => $ctx,
            'genders' => self::GENDERS,
            'error'  => Session::getFlash('error'),
            'old'    => Session::getFlash('old') ?: [],
            'attachMax' => (int)($config['recovery_attach_max_count'] ?? self::MAX_ATTACHMENTS),
            'attachMaxLabel' => RecoveryHelper::formatBytes((int)($config['recovery_attach_max_bytes'] ?? 1048576)),
        ]);
    }

    /**
     * 取待申诉的账号上下文：优先 2FA 待验证会话，其次本会话内已有的快照。
     *
     * 必须立刻快照：2FA 待验证会话只有 10 分钟，而用户写申诉会用掉更久。
     */
    private function resolveContext(): ?array
    {
        $config = self::config();
        $ttl = (int)($config['recovery_ctx_ttl'] ?? 7200);

        $pending = Session::get('2fa_pending');
        if (is_array($pending) && !empty($pending['user_id']) && ($pending['action'] ?? '') === 'login') {
            $pendingTtl = (int)($config['two_factor_pending_ttl'] ?? 600);
            if (time() - (int)($pending['created_at'] ?? 0) <= $pendingTtl) {
                $stmt = $this->db->prepare('SELECT id, username, email, email_verified FROM users WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => (int)$pending['user_id']]);
                $user = $stmt->fetch();
                if ($user) {
                    $ctx = [
                        'user_id'        => (int)$user['id'],
                        'username'       => $user['username'],
                        'email'          => $user['email'],
                        'email_verified' => (int)$user['email_verified'],
                        'created_at'     => time(),
                    ];
                    Session::set('recovery_ctx', $ctx);
                    return $ctx;
                }
            }
        }

        $ctx = Session::get('recovery_ctx');
        if (is_array($ctx) && !empty($ctx['user_id']) && time() - (int)($ctx['created_at'] ?? 0) <= $ttl) {
            return $ctx;
        }

        Session::remove('recovery_ctx');
        return null;
    }

    /**
     * 已经在家庭里、且家长真的加入了的孩子，不走这套自助申诉：家长能直接帮 TA 重置密码，
     * 而这里要求凑够 3 种登录方式，这种账号通常只有密码一种，放进来就卡死在修复页。
     * 只是"邀请中"（家长还没加入）的不拦 —— 那时孩子就是普通账号，照样可以申诉。
     *
     * @return bool true 表示已经渲染了替代页面，调用方应立即 return
     */
    private function deflectMinor(array $ctx): bool
    {
        if (!Features::guardians()) {
            return false;
        }

        $userId = (int)($ctx['user_id'] ?? 0);
        $membership = FamilyHelper::childMembership($userId);
        if (!$membership || $membership['status'] !== FamilyHelper::MEMBER_ACTIVE) {
            return false;
        }
        if (!FamilyHelper::isClaimed((int)$membership['group_id'])) {
            return false;
        }

        Response::view('recovery-family', [
            'username'       => (string)($ctx['username'] ?? ''),
            'maskedGuardian' => RecoveryHelper::maskEmail((string)(FamilyHelper::guardianEmailFor($userId) ?? '')),
            'error'          => Session::getFlash('error'),
        ]);
        return true;
    }

    /**
     * POST /recovery
     */
    public function submit(): void
    {
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Session::flash('error', t('请求无效，请重试'));
            Response::redirect('/recovery');
        }

        $ctx = $this->resolveContext();
        if (!$ctx) {
            Session::flash('error', t('验证会话已过期，请重新登录后再申请恢复'));
            Response::redirect('/login');
        }

        if ($this->deflectMinor($ctx)) {
            return;
        }

        $config = self::config();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rate = RateLimiter::check(
            'recovery:submit:' . $ip,
            (int)($config['recovery_submit_limit'] ?? 5),
            (int)($config['recovery_submit_window'] ?? 3600)
        );
        if (!$rate['allowed']) {
            Session::flash('error', t('提交过于频繁，请 {:n} 秒后再试', ['n' => (int)$rate['retry_after']]));
            Response::redirect('/recovery');
        }

        $old = [
            'birth_date'  => trim((string)($_POST['birth_date'] ?? '')),
            'gender'      => trim((string)($_POST['gender'] ?? '')),
            'description' => trim((string)($_POST['description'] ?? '')),
            'contact'     => (string)($_POST['contact'] ?? ''),
            'temp_email'  => trim((string)($_POST['temp_email'] ?? '')),
        ];

        $fail = function (string $message) use ($old) {
            Session::flash('error', $message);
            Session::flash('old', $old);
            Response::redirect('/recovery');
        };

        // 格式层面的校验（与身份是否正确无关，不泄露任何比对结果）
        $birthParts = explode('-', $old['birth_date']);
        if (count($birthParts) !== 3
            || !checkdate((int)$birthParts[1], (int)$birthParts[2], (int)$birthParts[0])
            || (int)$birthParts[0] < 1920
            || (int)$birthParts[0] > (int)date('Y')) {
            $fail('请选择有效的出生日期');
        }

        if (!isset(self::GENDERS[$old['gender']])) {
            $fail('请选择性别');
        }

        if ($old['description'] === '') {
            $fail('请填写您请求支持的描述');
        }

        if (mb_strlen($old['description'], 'UTF-8') < self::DESCRIPTION_MIN) {
            $fail('请至少填写 ' . self::DESCRIPTION_MIN
                . ' 个字，并尽量包含注册邮箱、常用登录地点、授权过的应用等信息');
        }

        if (mb_strlen($old['description'], 'UTF-8') > self::DESCRIPTION_MAX) {
            $fail('支持描述不能超过 ' . self::DESCRIPTION_MAX . ' 字');
        }

        $contactEmail = '';
        if ($old['contact'] === 'account') {
            $contactEmail = (string)$ctx['email'];
        } elseif ($old['contact'] === 'temp') {
            if (!filter_var($old['temp_email'], FILTER_VALIDATE_EMAIL)) {
                $fail('请输入有效的临时邮箱地址');
            }
            if (EmailPolicy::isBlocked($old['temp_email'])) {
                $fail(t(EmailPolicy::REJECT_MESSAGE));
            }
            $contactEmail = $old['temp_email'];
        } else {
            $fail('请选择联络使用的邮箱');
        }

        $userId = (int)$ctx['user_id'];
        $stmt = $this->db->prepare('SELECT birth_date, gender FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $account = $stmt->fetch() ?: [];

        // 静默比对：结果只入库，页面上不做任何提示
        $birthMatch = $this->compare($old['birth_date'], $account['birth_date'] ?? null);
        $genderMatch = $this->compare($old['gender'], $account['gender'] ?? null);

        $this->db->prepare(
            'INSERT INTO account_recovery_requests
             (user_id, username, account_email, birth_date_input, gender_input, birth_date_match,
              gender_match, description, contact_email, ip_address, user_agent)
             VALUES (:uid, :un, :ae, :bd, :gd, :bm, :gm, :ds, :ce, :ip, :ua)'
        )->execute([
            ':uid' => $userId,
            ':un'  => (string)$ctx['username'],
            ':ae'  => $ctx['email'] ?: null,
            ':bd'  => $old['birth_date'],
            ':gd'  => $old['gender'],
            ':bm'  => $birthMatch,
            ':gm'  => $genderMatch,
            ':ds'  => $old['description'],
            ':ce'  => $contactEmail,
            ':ip'  => mb_substr($ip, 0, 45),
            ':ua'  => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
        $requestId = (int)$this->db->lastInsertId();

        $tokens = $_POST['upload_tokens'] ?? [];
        $attachments = $this->persistAttachments(
            $requestId,
            is_array($tokens) ? array_slice($tokens, 0, self::MAX_ATTACHMENTS) : []
        );

        ActivityLog::record([
            'action'   => 'recovery.submit',
            'category' => ActivityLog::CAT_RECOVERY,
            'actor_type' => 'user',
            'actor_id' => $userId,
            'actor_username' => (string)$ctx['username'],
            'target_type' => 'recovery_request',
            'target_id' => $requestId,
            'summary' => '提交账号恢复申诉' . ($attachments ? '（附 ' . count($attachments) . ' 张证明材料）' : ''),
        ]);

        $this->sendSubmittedEmails($ctx, $contactEmail);

        Session::remove('recovery_ctx');
        Session::remove('2fa_pending');
        Session::flash('recovery_contact', $contactEmail);
        Response::redirect('/recovery/sent');
    }

    /**
     * 比对用户填写值与账号记录；账号无该字段时返回 null（表示无从比对）
     */
    private function compare(?string $input, ?string $actual): ?int
    {
        if ($actual === null || $actual === '') {
            return null;
        }
        return $input === $actual ? 1 : 0;
    }

    // ========== 证明材料上传（暂存） ==========

    /**
     * POST /recovery/upload
     *
     * 一次一张，先落在暂存区并返回 token；真正入库要等用户点「提交申诉」，
     * 所以没提交的图会由 sweepTemp() 定期清掉。
     */
    public function upload(): void
    {
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => t('请求无效，请刷新页面后重试')]);
        }

        if (!$this->resolveContext()) {
            Response::json(['success' => false, 'message' => t('验证会话已过期，请重新登录后再试')]);
        }

        $config = self::config();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rate = RateLimiter::check(
            'recovery:upload:' . $ip,
            (int)($config['recovery_upload_limit'] ?? 40),
            (int)($config['recovery_upload_window'] ?? 3600)
        );
        if (!$rate['allowed']) {
            Response::json(['success' => false, 'message' => t('上传过于频繁，请稍后再试')]);
        }

        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            Response::json(['success' => false, 'message' => t('没有收到文件')]);
        }

        $file = $_FILES['file'];
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE   => t('图片超过了服务器允许的大小'),
                UPLOAD_ERR_FORM_SIZE  => t('图片过大，请压缩后重试'),
                UPLOAD_ERR_PARTIAL    => t('图片只上传了一部分，请重试'),
                UPLOAD_ERR_NO_FILE    => t('没有选择图片'),
                UPLOAD_ERR_NO_TMP_DIR => t('服务器缺少临时目录'),
                UPLOAD_ERR_CANT_WRITE => t('服务器写入失败'),
                UPLOAD_ERR_EXTENSION  => t('上传被服务器扩展中断'),
            ];
            Response::json(['success' => false, 'message' => t($messages[$error] ?? '上传失败，请重试')]);
        }

        $maxBytes = (int)($config['recovery_attach_max_bytes'] ?? 1048576);
        if ((int)$file['size'] > $maxBytes) {
            Response::json([
                'success' => false,
                'too_large' => true,
                'message' => t('单张图片不能超过 {:size}，请压缩后重试', ['size' => RecoveryHelper::formatBytes($maxBytes)]),
            ]);
        }

        $maxCount = (int)($config['recovery_attach_max_count'] ?? 5);
        $staged = $this->stagedUploads();
        if (count($staged) >= $maxCount) {
            Response::json(['success' => false, 'message' => t('最多只能上传 {:n} 张图片', ['n' => $maxCount])]);
        }

        // 真 MIME 校验：不信任扩展名，也不信任前端声明的 type
        $mime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? (finfo_file($finfo, $file['tmp_name']) ?: null) : null;
            if ($finfo) {
                finfo_close($finfo);
            }
        }

        $info = @getimagesize($file['tmp_name']);
        $imageMime = is_array($info) ? ($info['mime'] ?? null) : null;
        $ext = RecoveryHelper::ATTACH_MIME_EXT[$mime ?? ''] ?? null;

        if ($ext === null || !is_array($info) || $imageMime !== $mime) {
            Response::json(['success' => false, 'message' => t('只接受真正的 PNG / JPG / WebP 图片')]);
        }

        if ($info[0] < 16 || $info[1] < 16 || $info[0] > 12000 || $info[1] > 12000) {
            Response::json(['success' => false, 'message' => t('图片尺寸异常，请换一张')]);
        }

        $token = bin2hex(random_bytes(16));
        $bucket = RecoveryHelper::tmpRoot() . '/' . substr(hash('sha256', session_id()), 0, 16);
        if (!is_dir($bucket) && !mkdir($bucket, 0755, true) && !is_dir($bucket)) {
            Response::json(['success' => false, 'message' => t('服务器无法保存文件，请稍后重试')]);
        }

        $dest = $bucket . '/' . $token . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            Response::json(['success' => false, 'message' => t('保存失败，请重试')]);
        }
        @chmod($dest, 0644);

        $staged[$token] = [
            'path'       => $dest,
            'mime'       => (string)$mime,
            'ext'        => $ext,
            'size'       => (int)$file['size'],
            'created_at' => time(),
        ];
        Session::set('recovery_uploads', $staged);

        RecoveryHelper::sweepTemp((int)($config['recovery_attach_tmp_ttl'] ?? 7200));

        Response::json([
            'success' => true,
            'token'   => $token,
            'count'   => count($staged),
            'max'     => $maxCount,
        ]);
    }

    /**
     * 当前会话暂存的图，顺手丢掉过期项
     *
     * @return array<string, array{path:string,mime:string,ext:string,size:int,created_at:int}>
     */
    private function stagedUploads(): array
    {
        $ttl = (int)(self::config()['recovery_attach_tmp_ttl'] ?? 7200);
        $staged = Session::get('recovery_uploads');
        if (!is_array($staged)) {
            return [];
        }

        $alive = [];
        foreach ($staged as $token => $item) {
            if (!is_array($item) || !isset($item['path']) || !is_file($item['path'])) {
                continue;
            }
            if (time() - (int)($item['created_at'] ?? 0) > $ttl) {
                @unlink($item['path']);
                continue;
            }
            $alive[$token] = $item;
        }

        return $alive;
    }

    /**
     * 把已确认的暂存图搬到工单目录，命名成 1.ext / 2.ext …
     *
     * @param array<int, string> $tokens
     * @return array<int, array{name:string, path:string, size:int, mime:string}>
     */
    private function persistAttachments(int $requestId, array $tokens): array
    {
        $staged = $this->stagedUploads();
        Session::remove('recovery_uploads');

        if (!$tokens || !$staged) {
            foreach ($staged as $item) {
                @unlink($item['path']);
            }
            return [];
        }

        $dir = RecoveryHelper::attachmentDir($requestId);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('[Recovery] 无法创建附件目录: ' . $dir);
            foreach ($staged as $item) {
                @unlink($item['path']);
            }
            return [];
        }

        $saved = [];
        $seq = 0;
        foreach ($tokens as $token) {
            $token = is_string($token) ? $token : '';
            if ($token === '' || !isset($staged[$token])) {
                continue;
            }

            $item = $staged[$token];
            unset($staged[$token]);

            $seq++;
            $name = $seq . '-' . bin2hex(random_bytes(24)) . '.' . $item['ext'];
            $dest = $dir . '/' . $name;

            if (!@rename($item['path'], $dest)) {
                error_log('[Recovery] 附件搬运失败: ' . $item['path'] . ' -> ' . $dest);
                $seq--;
                continue;
            }
            @chmod($dest, 0644);

            $saved[] = [
                'seq'  => $seq,
                'name' => $name,
                'path' => $dest,
                'url'  => RecoveryHelper::attachmentUrl($requestId, $name),
                'size' => (int)$item['size'],
                'mime' => (string)$item['mime'],
            ];
        }

        // 用户没勾上的、或搬运失败的，都清掉，别留垃圾
        foreach ($staged as $item) {
            @unlink($item['path']);
        }

        return $saved;
    }

    /**
     * GET /recovery/sent
     */
    public function sent(): void
    {
        Response::view('recovery-sent', [
            'title'   => '申诉已提交',
            'contact' => Session::getFlash('recovery_contact'),
        ]);
    }

    // ========== 一次性恢复登录链接 ==========

    /**
     * GET /recovery/ticket?token=...
     */
    public function ticket(): void
    {
        $token = (string)($_GET['token'] ?? '');
        if ($token === '' || strlen($token) > 200) {
            $this->ticketFailed(t('恢复链接无效'));
        }

        $hash = hash('sha512', $token);
        $stmt = $this->db->prepare(
            "SELECT * FROM account_recovery_requests
             WHERE recovery_token_hash = :h AND status = 'approved'
               AND recovery_token_used_at IS NULL AND recovery_token_expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([':h' => $hash]);
        $request = $stmt->fetch();

        if (!$request) {
            $this->ticketFailed(t('恢复链接无效、已过期或已被使用'));
        }

        $userId = (int)$request['user_id'];
        $stmt = $this->db->prepare('SELECT id, username, role, status FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user || (int)$user['status'] !== 1) {
            $this->ticketFailed(t('该账号当前不可用，请联系虚舟实验室支持团队'));
        }

        $this->db->prepare(
            'UPDATE account_recovery_requests SET recovery_token_used_at = NOW() WHERE id = :id'
        )->execute([':id' => (int)$request['id']]);

        // 凭据已被他人掌握的可能性高于零：先清干净再放人进来
        Session::destroyOtherSessionsFor($userId);
        DashboardController::revokeAllUserTokens($userId);
        DashboardController::revokeAllUserAuthorizations($userId, (string)$user['username']);

        Session::regenerate();
        Session::set('user_id', $userId);
        Session::set('username', $user['username']);
        Session::set('role', $user['role'] ?? 'user');
        RecoveryHelper::begin($userId, (string)$user['username'], (string)($user['role'] ?? 'user'));

        ActivityLog::record([
            'action'   => 'recovery.ticket_used',
            'category' => ActivityLog::CAT_RECOVERY,
            'actor_type' => 'user',
            'actor_id' => $userId,
            'actor_username' => (string)$user['username'],
            'target_type' => 'recovery_request',
            'target_id' => (int)$request['id'],
            'summary' => '通过恢复链接登录，进入安全修复',
        ]);

        Response::redirect('/recovery/secure');
    }

    private function ticketFailed(string $message): void
    {
        Session::flash('error', $message . t('；如仍需帮助，请联系 {:email}', ['email' => (self::config()['support_email'] ?? '')]));
        Response::redirect('/login');
    }

    // ========== 强制安全修复 ==========

    /**
     * GET /recovery/secure
     */
    public function secure(): void
    {
        $userId = (int)Session::get('user_id');
        if (!$userId || !RecoveryHelper::isActiveFor($userId)) {
            Response::redirect('/login');
        }

        [$methods, $assistMethods, $count, $resetDone] = $this->progress($userId);

        Response::view('recovery-secure', [
            'title'          => '恢复账户访问权限',
            'username'       => (string)Session::get('recovery_username', ''),
            'methods'        => $methods,
            'assistMethods'  => $assistMethods,
            'methodCount'    => $count,
            'requiredCount'  => RecoveryHelper::MIN_LOGIN_METHODS,
            'resetDone'      => $resetDone,
            'canFinish'      => $resetDone && $count >= RecoveryHelper::MIN_LOGIN_METHODS,
            'supportEmail'   => self::config()['support_email'] ?? '',
            'emails'         => EmailSet::all($userId),
            'maxEmails'      => EmailSet::MAX,
            'error'          => Session::getFlash('error'),
            'success'        => Session::getFlash('success'),
        ]);
    }

    /**
     * @return array{0: array, 1: array, 2: int, 3: bool} 登录方式 / 辅助验证方式 / 计数 / 是否已重置凭据
     */
    private function progress(int $userId): array
    {
        $groups = RecoveryHelper::groupedMethods($userId);

        return [
            $groups['login'],
            $groups['assist'],
            RecoveryHelper::countLoginMethods($userId),
            RecoveryHelper::hasResetCredential($userId),
        ];
    }

    /**
     * POST /recovery/secure/finish
     */
    public function finish(): void
    {
        $userId = (int)Session::get('user_id');
        if (!$userId || !RecoveryHelper::isActiveFor($userId)) {
            Response::redirect('/login');
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Session::flash('error', t('请求无效，请重试'));
            Response::redirect('/recovery/secure');
        }

        [, , $count, $resetDone] = $this->progress($userId);
        if (!$resetDone) {
            Session::flash('error', t('请先修改密码，或添加 / 更换并验证一个邮箱'));
            Response::redirect('/recovery/secure');
        }
        if ($count < RecoveryHelper::MIN_LOGIN_METHODS) {
            Session::flash('error', t('登录方式不足 {:n} 种，请再添加一种（验证器 APP 或恢复码）', ['n' => RecoveryHelper::MIN_LOGIN_METHODS]));
            Response::redirect('/recovery/secure');
        }

        $stmt = $this->db->prepare(
            "SELECT id FROM account_recovery_requests
             WHERE user_id = :u AND status = 'approved' AND completed_at IS NULL
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':u' => $userId]);
        $requestId = (int)$stmt->fetchColumn();

        if ($requestId) {
            $this->db->prepare(
                "UPDATE account_recovery_requests SET status = 'completed', completed_at = NOW() WHERE id = :id"
            )->execute([':id' => $requestId]);
        }

        Session::destroyOtherSessionsFor($userId);
        DashboardController::revokeAllUserTokens($userId);
        // 完成邮件承诺「已撤销全部第三方应用授权」，这里必须真删（ticket 阶段已清过一次，此处幂等）
        DashboardController::revokeAllUserAuthorizations($userId, (string)Session::get('username', ''));

        ActivityLog::record([
            'action'   => 'recovery.completed',
            'category' => ActivityLog::CAT_RECOVERY,
            'actor_type' => 'user',
            'actor_id' => $userId,
            'actor_username' => (string)Session::get('recovery_username', ''),
            'target_type' => 'recovery_request',
            'target_id' => $requestId ?: null,
            'summary' => '完成安全修复，恢复账户访问权限',
        ]);

        // 只发一封：sendCompletedEmail 里已经写清了「已清除其他设备登录态 + 撤销第三方授权」
        $this->sendCompletedEmail($userId);

        RecoveryHelper::end();
        Response::redirect('/dashboard');
    }

    // ========== 邮件 ==========

    private function sendSubmittedEmails(array $ctx, string $contactEmail): void
    {
        $support = (string)(self::config()['support_email'] ?? '');
        $locale = I18n::locale();
        $disclaimer = I18n::tIn($locale, '您之所以会收到此邮件，是因为有人在提交申诉时提供该地址作为联系电子邮件地址。如果您并未提交任何申诉，可以忽略此邮件。');

        Email::send($contactEmail, 'Nomen - ' . I18n::tIn($locale, '已收到您的账号恢复申诉'), EmailTemplate::render([
            'preheader' => '我们已收到您的 Nomen 恢复申诉',
            'badge'     => ['text' => '账号恢复', 'tone' => 'general'],
            'title'     => '已收到您的账号恢复申诉',
            'blocks'    => [
                ['type' => 'paragraph', 'text' => '感谢您就恢复对您 Nomen 的访问权限一事与我们联系。'],
                ['type' => 'paragraph', 'text' => '虚舟实验室(CaelLab) 会尽快审核您的申诉。大部分请求的审核用时为 2 个自然日，但某些请求可能需要更长的时间。'],
                ['type' => 'paragraph', 'text' => '感谢您的耐心等待。'],
                ['type' => 'alert', 'tone' => 'info', 'text' => $disclaimer],
            ],
            'footnote'  => I18n::tIn($locale, '如对本次申诉有疑问，请联系 {:email}。', ['email' => htmlspecialchars($support, ENT_QUOTES, 'UTF-8')]),
        ], $locale));

        // 账号邮箱：只在已验证时发（未验证邮箱一律不发信），且与联系邮箱重复时去重
        $accountEmail = (string)($ctx['email'] ?? '');
        if (!empty($ctx['email_verified']) && $accountEmail !== '' && strcasecmp($accountEmail, $contactEmail) !== 0) {
            $this->notifyAccountEmail((int)$ctx['user_id'], 'Nomen 账号恢复申诉提醒',
                I18n::tIn($locale, '有人为你的 Nomen 账户（{:name}）提交了账号恢复申诉，我们已将受理信息发送至其填写的联系邮箱。\n\n如果这不是你本人操作，请尽快登录并修改密码，同时联系 {:support}。', ['name' => $ctx['username'], 'support' => $support]));
        }

        // 管理员提醒发给 admin_notify_email（管理员自己的信箱），不是对外的 support_email；
        // 按节流窗口汇总，见 admin_notify_window —— 不是每单都发
        if ($this->adminNotifyEmail() !== '' && $this->shouldNotifyAdminNow()) {
            $this->sendAdminDigest();
        }
    }

    private function adminNotifyEmail(): string
    {
        return (string)(self::config()['admin_notify_email'] ?? '');
    }

    /**
     * 现在该不该给管理员发提醒？
     *
     * 全站共用一个窗口，判定分两步：
     *   ① 距「上一次发出提醒」不足 window 秒 → 压着不发；
     *   ② 窗口已开，但把「还没通知过的待处理工单」标记掉之后 rowCount 为 0
     *      （说明没有新东西，只是旧的积压）→ 也不发。
     *
     * 标记用 UPDATE ... WHERE admin_email_notify_at IS NULL，只动没通知过的行，
     * 所以这个 UPDATE 自带幂等 —— 反复执行不会把窗口一直往后推。
     * rowCount 同时充当并发仲裁：两个工单同时提交时，后到的那个会阻塞在行锁上，
     * 等前一个提交后它匹配到 0 行，于是不会重复发信。
     *
     * 时间比较一律交给 MySQL 用 UNIX_TIMESTAMP 算成 epoch 秒 —— 别拿库里 NOW() 写的
     * 时间戳去和 PHP 的 time() 相减，两者时区一旦不一致窗口就会整体偏移。
     */
    private function shouldNotifyAdminNow(): bool
    {
        $window = (int)(self::config()['admin_notify_window'] ?? 3600);

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare(
                'SELECT ' . Database::dialectFor($this->db)->epochDiff('MAX(admin_email_notify_at)') . '
                 FROM account_recovery_requests
                 WHERE admin_email_notify_at IS NOT NULL'
            );
            $stmt->execute();
            $elapsed = $stmt->fetchColumn();

            // $elapsed 为 null 表示从没发过提醒，视为窗口已开
            if ($elapsed !== null && $elapsed !== false && (int)$elapsed < $window) {
                $this->db->commit();
                return false;
            }

            $stmt = $this->db->prepare(
                "UPDATE account_recovery_requests
                 SET admin_email_notify_at = NOW()
                 WHERE status = 'pending' AND admin_email_notify_at IS NULL"
            );
            $stmt->execute();
            $stamped = $stmt->rowCount();

            $this->db->commit();
            return $stamped > 0;
        } catch (\Throwable $e) {
            // 节流判定出问题就宁可发一封，也别把提醒整没了
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[Recovery] 管理员提醒节流判定失败，改为直接发送: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * 汇总未处理（pending）的工单，发一封提醒给管理员（admin_notify_email）
     *
     * 窗口内没发出去的工单会在下一个窗口开启时被一起带过来。
     */
    private function sendAdminDigest(): void
    {
        $to = $this->adminNotifyEmail();
        if ($to === '') {
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT *, ' . Database::dialectFor($this->db)->epochDiff('created_at') . ' AS age_seconds
             FROM account_recovery_requests WHERE status = \'pending\' ORDER BY id ASC'
        );
        $stmt->execute();
        $pending = $stmt->fetchAll();
        if (!$pending) {
            return;
        }

        $window = (int)(self::config()['admin_notify_window'] ?? 3600);
        $total = count($pending);
        $oldestAt = $pending[0]['created_at'] ?? date('Y-m-d H:i:s');
        // 年龄也由 MySQL 算，避免 PHP 与库的时区差把"积压"判错
        $backlog = 0;
        foreach ($pending as $row) {
            if ((int)($row['age_seconds'] ?? 0) > $window) {
                $backlog++;
            }
        }

        $title = $total > 1 ? '账号恢复申诉待处理（' . $total . ' 单）' : '有新的账号恢复申诉待处理';
        $preheader = $total > 1
            ? '目前有 ' . $total . ' 个恢复申诉等待审核'
            : '工单 #' . (int)$pending[0]['id'] . ' 等待审核';

        $blocks = [[
            'type' => 'info',
            'items' => [
                ['label' => '待处理', 'value' => $total . ' 单'],
                ['label' => '最早提交', 'value' => (string)$oldestAt],
                ['label' => '本封发出', 'value' => date('Y-m-d H:i:s')],
            ],
        ]];

        if ($backlog > 0) {
            $blocks[] = [
                'type' => 'alert',
                'tone' => 'warning',
                'text' => '其中 ' . $backlog . ' 单是在上一个提醒窗口之前就提交的，属于积压，建议优先看。',
            ];
        }

        // 每单一张小表 + 缩略图，全部压过，避免附件太大被 SMTP 拒收
        $embedded = [];
        foreach ($pending as $index => $row) {
            $requestId = (int)$row['id'];
            $blocks[] = ['type' => 'divider'];
            $blocks[] = ['type' => 'heading', 'text' => '#' . $requestId . ' · ' . (string)$row['username']];

            $blocks[] = [
                'type' => 'info',
                'items' => [
                    ['label' => '联系邮箱', 'value' => (string)$row['contact_email']],
                    ['label' => '提交时间', 'value' => (string)$row['created_at']],
                    ['label' => '生日比对', 'value' => self::matchLabel($row['birth_date_match'] ?? null)],
                    ['label' => '性别比对', 'value' => self::matchLabel($row['gender_match'] ?? null)],
                ],
            ];

            $thumbsHtml = '';
            foreach (RecoveryHelper::listAttachments($requestId) as $attIndex => $attachment) {
                $cid = 'r' . $requestId . 'a' . ($attIndex + 1);
                $thumb = RecoveryHelper::makeThumbnail($attachment['path'], $attachment['mime'], $cid, 480);
                if ($thumb === null) {
                    continue;
                }
                $embedded[] = $thumb;
                $thumbsHtml .= '<img src="cid:' . $cid . '" alt="证明材料" '
                    . 'style="display:block;max-width:100%;height:auto;border:1px solid #d1d9e0;'
                    . 'border-radius:6px;margin:0 0 8px;">';
            }
            if ($thumbsHtml !== '') {
                $blocks[] = ['type' => 'raw', 'html' => $thumbsHtml];
            }

            $blocks[] = [
                'type' => 'paragraph',
                'text' => '申诉原文与审核要点请在管理后台查看。',
                'muted' => true,
            ];
        }

        $blocks[] = ['type' => 'divider'];
        $blocks[] = [
            'type' => 'paragraph',
            'text' => '提醒已按节流窗口合并：窗口内新提交的工单不会单独发信，会在下一个窗口开启时一起发过来。',
            'muted' => true,
        ];

        Email::send($to, 'Nomen - ' . $title, EmailTemplate::render([
            'preheader' => $preheader,
            'badge'     => ['text' => '管理提醒', 'tone' => 'warning'],
            'title'     => $title,
            'blocks'    => $blocks,
            'button'    => ['text' => '打开恢复工单', 'url' => EmailTemplate::url('/admin/recovery')],
        ]), $embedded);
    }

    private static function matchLabel($value): string
    {
        if ($value === null || $value === '') {
            return '账号无记录';
        }
        return (int)$value === 1 ? '匹配' : '不匹配';
    }

    private function sendCompletedEmail(int $userId): void
    {
        $stmt = $this->db->prepare('SELECT email, email_verified FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user || empty($user['email_verified']) || !$user['email']) {
            return;
        }

        $locale = I18n::locale();
        Email::send($user['email'], 'Nomen - ' . I18n::tIn($locale, '账号恢复完成'), EmailTemplate::render([
            'preheader' => '你的 Nomen 账户已完成安全修复',
            'badge'     => ['text' => '账号恢复', 'tone' => 'success'],
            'title'     => '账号恢复完成',
            'blocks'    => [
                ['type' => 'paragraph', 'text' => '你的 Nomen 账户已完成安全修复，现在可以正常使用了。'],
                ['type' => 'paragraph', 'text' => '为安全起见，我们已清除该账户在所有其他设备上的登录状态，并撤销了全部第三方应用授权。'],
                ['type' => 'alert', 'tone' => 'warning', 'text' => I18n::tIn($locale, '如果这不是你本人操作，请立即修改密码并联系 {:support}。', ['support' => (self::config()['support_email'] ?? '')])],
            ],
            'button'    => ['text' => '前往 Nomen', 'url' => EmailTemplate::url('/dashboard')],
        ], $locale));
    }

    private function notifyAccountEmail(int $userId, string $title, string $body): void
    {
        $stmt = $this->db->prepare('SELECT email, email_verified FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user || empty($user['email_verified']) || !$user['email']) {
            return;
        }

        $locale = I18n::emailLocale((int)$user['id']);
        Email::send($user['email'], 'Nomen - ' . I18n::tIn($locale, $title), EmailTemplate::render([
            'preheader' => $title,
            'badge'     => ['text' => '安全提醒', 'tone' => 'important'],
            'title'     => $title,
            'blocks'    => [
                ['type' => 'paragraph', 'text' => $body],
            ],
            'button'    => ['text' => '前往 Nomen', 'url' => EmailTemplate::url('/settings')],
        ], $locale));
    }
}

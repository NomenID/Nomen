<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

use PDO;

/**
 * 账号恢复的共用判定。
 *
 * 「登录方式」= METHODS 里的 4 项（密码 / 邮箱 / 恢复码 / 验证器 APP），恢复完成后要求 ≥ MIN_LOGIN_METHODS。
 * 生物识别归 ASSIST_METHODS：它绑在具体设备上，界面上叫「快速辅助验证方式」，不编号也不计入数量
 * （2026-10-09 用户定的口径，别把它算回登录方式）。临时密钥同样不算 —— 它是临时凭据。
 */
class RecoveryHelper
{
    public const MIN_LOGIN_METHODS = 3;

    /**
     * 满足「已重置凭据」的动作，任意一条即可。
     * 加邮箱那条认的是「新地址点过验证链接」—— 那一步才证明控制了一个新邮箱；
     * 只是提交添加、没点链接不算（那时还没人证明那个地址是他的）。
     */
    public const RESET_ACTIONS = ['security.password_change', 'security.email_changed', 'security.email_verified'];

    /**
     * 顺序即界面上给用户看的推荐次序。
     * 恢复码排在验证器 APP 前头：普通用户大多不会配 TOTP，恢复码更容易接受
     * （2026-10-09 用户定的，别凭"哪个更安全"自己调回去）。
     */
    public const METHODS = [
        'password'      => ['label' => 'Password'],
        'email'         => ['label' => 'Email'],
        'recovery_code' => ['label' => 'Recovery codes'],
        'totp'          => ['label' => 'Authenticator app'],
    ];

    /** 不计入数量的辅助手段，展示时单独一组 */
    public const ASSIST_LABEL = 'Quick assist verification methods';

    public const ASSIST_METHODS = [
        'biometric' => ['label' => 'Biometric'],
    ];

    private static function db(): PDO
    {
        return Database::getInstance();
    }

    /**
     * 该用户当前拥有的各类凭据，返回 'key' => bool（含不计入数量的辅助方式）
     */
    public static function methods(int $userId): array
    {
        $db = self::db();

        $stmt = $db->prepare('SELECT password_hash, email, email_verified FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch() ?: [];

        $stmt = $db->prepare('SELECT COUNT(*) FROM user_totp_secrets WHERE user_id = :u AND verified = 1');
        $stmt->execute([':u' => $userId]);
        $hasTotp = (int)$stmt->fetchColumn() > 0;

        $stmt = $db->prepare('SELECT COUNT(*) FROM user_biometrics WHERE user_id = :u AND revoked = 0');
        $stmt->execute([':u' => $userId]);
        $hasBiometric = (int)$stmt->fetchColumn() > 0;

        $stmt = $db->prepare('SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = :u AND used_at IS NULL');
        $stmt->execute([':u' => $userId]);
        $hasRecoveryCodes = (int)$stmt->fetchColumn() > 0;

        return [
            'password'      => !empty($user['password_hash']),
            // 未成年账号没有自己的邮箱（监护人同意也会置 email_verified），
            // 那种情况不算「邮箱」这一种登录方式，否则门槛 MIN_LOGIN_METHODS 会算虚。
            // 反过来：只有备选邮箱、没有首选的账号（合法的「一主一备」状态）也要算上，
            // 不然它的邮箱明明能收验证码登录，却被算成没有。
            'email'         => (!empty($user['email_verified']) && !empty($user['email']))
                || EmailSet::hasVerified($userId),
            'recovery_code' => $hasRecoveryCodes,
            'totp'          => $hasTotp,
            'biometric'     => $hasBiometric,
        ];
    }

    public static function countLoginMethods(int $userId): int
    {
        return count(array_intersect_key(array_filter(self::methods($userId)), self::METHODS));
    }

    /**
     * 按界面的两组返回：'login' 为计入数量的登录方式，'assist' 为辅助验证方式。
     * 每项是元数据 + present。
     *
     * @return array{login: array<string, array>, assist: array<string, array>}
     */
    public static function groupedMethods(int $userId): array
    {
        $present = self::methods($userId);
        $build = static function (array $defs) use ($present): array {
            $out = [];
            foreach ($defs as $key => $meta) {
                $out[$key] = $meta + ['present' => !empty($present[$key])];
            }
            return $out;
        };

        return ['login' => $build(self::METHODS), 'assist' => $build(self::ASSIST_METHODS)];
    }

    /**
     * 恢复模式是否对当前会话生效（同时要求 session 标记与目标用户一致）
     */
    public static function isActiveFor(int $userId): bool
    {
        return Session::get('recovery_mode') === true
            && (int)Session::get('recovery_user_id') === $userId;
    }

    public static function startedAt(): int
    {
        return (int)Session::get('recovery_started_at', 0);
    }

    public static function begin(int $userId, string $username, string $role): void
    {
        Session::set('recovery_mode', true);
        Session::set('recovery_user_id', $userId);
        Session::set('recovery_started_at', time());
        Session::set('recovery_username', $username);
        Session::set('recovery_role', $role);
    }

    public static function end(): void
    {
        foreach (['recovery_mode', 'recovery_user_id', 'recovery_started_at', 'recovery_username', 'recovery_role'] as $key) {
            Session::remove($key);
        }
    }

    /**
     * 本次恢复会话是否已经重置过凭据
     */
    public static function hasResetCredential(int $userId): bool
    {
        $since = self::startedAt();
        if ($since <= 0) {
            return false;
        }
        return ActivityLog::hasActionSince($userId, self::RESET_ACTIONS, $since);
    }

    /**
     * 邮箱打码：`1*********@q*.com`。实现搬到了 EmailPolicy::mask()（域名也要打掉），
     * 这里保留旧名字是因为全站（申诉页、后台工单页、家庭页、几处 flash 文案）都在调它。
     */
    public static function maskEmail(?string $email): string
    {
        return EmailPolicy::mask($email);
    }

    // ========== 证明材料（附件） ==========

    /** 只认这三种；键是真实 MIME（finfo 判定），值是落盘扩展名 */
    public const ATTACH_MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    public const ATTACH_EXT_MIME = [
        'jpg'  => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];

    /**
     * 附件落在 web 目录下，靠文件名当凭证：48 位十六进制随机名（192 bit），
     * 猜不到、也不做目录索引 —— 所以不需要再套一层鉴权代理读图的端点。
     */
    public static function attachRoot(): string
    {
        return dirname(__DIR__) . '/public/assets/recovery';
    }

    public static function tmpRoot(): string
    {
        return self::attachRoot() . '/_tmp';
    }

    public static function attachmentDir(int $requestId): string
    {
        return self::attachRoot() . '/' . $requestId;
    }

    public static function attachmentUrl(int $requestId, string $name): string
    {
        return '/assets/recovery/' . $requestId . '/' . rawurlencode($name);
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }
        return $bytes . ' B';
    }

    /**
     * 某工单的证明材料，按提交顺序（文件名前缀的序号）排序
     *
     * @return array<int, array{seq:int, name:string, path:string, url:string, size:int, mime:string}>
     */
    public static function listAttachments(int $requestId): array
    {
        $dir = self::attachmentDir($requestId);
        if ($requestId <= 0 || !is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (!preg_match('/^([1-9][0-9]*)-([a-f0-9]{16,})\.(png|webp|jpg)$/', $name, $m)) {
                continue;
            }
            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $out[] = [
                'seq'  => (int)$m[1],
                'name' => $name,
                'path' => $path,
                'url'  => self::attachmentUrl($requestId, $name),
                'size' => (int)filesize($path),
                'mime' => self::ATTACH_EXT_MIME[$m[3]],
            ];
        }

        usort($out, static fn(array $a, array $b): int => $a['seq'] <=> $b['seq']);
        return $out;
    }

    /**
     * 生成内嵌邮件用的缩略图。邮件里塞原图（5×1MB）容易被 SMTP 拒收，
     * 所以统一压成 JPEG 缩略图，正文里用 cid 引用。
     *
     * @return array{cid:string, name:string, mime:string, data:string}|null
     */
    public static function makeThumbnail(string $path, string $mime, string $cid, int $maxWidth = 640): ?array
    {
        if (!function_exists('imagecreatetruecolor') || !is_file($path)) {
            return null;
        }

        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default      => false,
        };
        if (!$src) {
            return null;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW < 1 || $srcH < 1) {
            imagedestroy($src);
            return null;
        }

        $scale = min(1.0, $maxWidth / $srcW);
        $dstW = max(1, (int)round($srcW * $scale));
        $dstH = max(1, (int)round($srcH * $scale));

        $dst = imagecreatetruecolor($dstW, $dstH);
        // JPEG 不支持透明，先铺白底，否则 PNG 的透明区会变成黑块
        imagefilledrectangle($dst, 0, 0, $dstW, $dstH, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        ob_start();
        $ok = imagejpeg($dst, null, 72);
        $data = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        if (!$ok || !is_string($data) || $data === '') {
            return null;
        }

        return [
            'cid'  => $cid,
            'name' => $cid . '.jpg',
            'mime' => 'image/jpeg',
            'data' => $data,
        ];
    }

    /**
     * 清掉暂存区里超过 TTL 还没提交的图（用户传完不提交、关掉页面）
     */
    public static function sweepTemp(int $ttl): void
    {
        $root = self::tmpRoot();
        if (!is_dir($root)) {
            return;
        }

        $deadline = time() - $ttl;
        foreach (scandir($root) ?: [] as $bucket) {
            if ($bucket === '.' || $bucket === '..') {
                continue;
            }
            $dir = $root . '/' . $bucket;
            if (!is_dir($dir)) {
                continue;
            }

            $entries = scandir($dir) ?: [];
            $alive = 0;
            foreach ($entries as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $path = $dir . '/' . $file;
                if (is_file($path) && filemtime($path) < $deadline) {
                    @unlink($path);
                } elseif (is_file($path)) {
                    $alive++;
                }
            }

            if ($alive === 0) {
                @rmdir($dir);
            }
        }
    }
}

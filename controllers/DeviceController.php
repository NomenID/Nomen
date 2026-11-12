<?php
namespace Nomen\Controllers;

use Nomen\Src\{ActivityLog, Database, Email, EmailTemplate, GeoIP, FamilyHelper, I18n};

/**
 * 设备信任工具类
 *
 * 新设备验证已合并进统一的二验流程（TwoFactorController），
 * 这里只保留设备指纹与信任状态的读写能力。
 */
class DeviceController
{
    /**
     * 检查当前设备是否受信任
     */
    public static function isTrustedDevice(int $userId): bool
    {
        $deviceId = $_COOKIE['caellab_device_id'] ?? '';
        if (!$deviceId) return false;

        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT 1 FROM user_devices 
             WHERE user_id = :u AND device_id = :d 
               AND trusted_at IS NOT NULL 
               AND trusted_at >= ' . Database::dialectFor($db)->nowMinusDays(90) . '
             LIMIT 1'
        );
        $stmt->execute([':u' => $userId, ':d' => $deviceId]);
        return (bool)$stmt->fetch();
    }

    /**
     * 获取或创建设备 ID（设置 cookie）
     */
    public static function ensureDeviceId(): string
    {
        $deviceId = $_COOKIE['caellab_device_id'] ?? '';
        if (!$deviceId) {
            $deviceId = bin2hex(random_bytes(16));
            setcookie('caellab_device_id', $deviceId, time() + 365 * 86400, '/', '', true, true);
            $_COOKIE['caellab_device_id'] = $deviceId;
        }
        return $deviceId;
    }

    /**
     * 解析 User-Agent 成可读的设备名
     */
    public static function parseDeviceName(?string $ua): string
    {
        if (!$ua) return '未知设备';

        $browser = '未知浏览器';
        $os = '未知系统';

        if (preg_match('/Windows NT ([\d.]+)/', $ua, $m)) {
            $versions = ['10.0' => '10', '6.3' => '8.1', '6.2' => '8', '6.1' => '7'];
            $ver = $versions[$m[1]] ?? $m[1];
            $os = "Windows {$ver}";
        } elseif (preg_match('/Mac OS X ([\d_]+)/', $ua, $m)) {
            $os = 'macOS ' . str_replace('_', '.', $m[1]);
        } elseif (preg_match('/Android ([\d.]+)/', $ua, $m)) {
            $os = "Android {$m[1]}";
        } elseif (preg_match('/(iPhone|iPad).*OS ([\d_]+)/', $ua, $m)) {
            $os = "iOS {$m[2]}";
            $device = $m[1];
        } elseif (preg_match('/Linux/', $ua)) {
            $os = 'Linux';
        }

        if (preg_match('/(Edg|Chrome|Firefox|Safari|OPR)\/([\d.]+)/', $ua, $m)) {
            $map = ['Edg' => 'Edge', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari', 'OPR' => 'Opera'];
            $browser = $map[$m[1]] ?? $m[1];
        }

        return trim("{$os} · {$browser}", ' ·');
    }

    /**
     * 信任设备（顺延 90 天信任期）
     *
     * $wasTrusted 由调用方传入（它在决定要不要二验时已经算过）：
     * 本来就受信任的设备只是顺延信任期，不该再记一条「信任新设备」
     *
     * 注意必须写入当前时间：早先用 COALESCE(trusted_at, NOW()) 会让
     * 信任期过期的设备永远无法重新被信任。
     */
    public static function trustDevice(int $userId, string $deviceId, bool $wasTrusted = false): void
    {
        $db = Database::getInstance();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $deviceName = self::parseDeviceName($ua);

        $stmt = $db->prepare(
            'INSERT INTO user_devices (user_id, device_id, device_name, ip_address, user_agent, last_seen_at, trusted_at)
             VALUES (:u, :d, :n, :ip, :ua, NOW(), NOW()) ' .
            Database::dialectFor($db)->upsert(
                ['user_id', 'device_id'],
                'device_name = :n2, ip_address = :ip2, user_agent = :ua2, last_seen_at = NOW(), trusted_at = NOW()'
            )
        );
        $stmt->execute([
            ':u'   => $userId,
            ':d'   => $deviceId,
            ':n'   => $deviceName,
            ':ip'  => $ip,
            ':ua'  => $ua,
            ':n2'  => $deviceName,
            ':ip2' => $ip,
            ':ua2' => $ua,
        ]);

        if ($wasTrusted) {
            return;
        }

        ActivityLog::record([
            'action'   => 'device.trust',
            'category' => ActivityLog::CAT_DEVICE,
            'actor_type' => 'user',
            'actor_id' => $userId,
            'target_type' => 'device',
            'target_name' => $deviceName,
            'summary'  => '信任新设备「' . $deviceName . '」',
        ]);
    }

    /**
     * 发送新设备登录通知邮件
     */
    public static function notifyNewDevice(int $userId): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id, email, username FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) return;

        // 未成年账号没有自己的邮箱，安全提醒发到监护人邮箱
        $contact = FamilyHelper::contactEmail($user);
        if ($contact === null) return;

        $deviceName = self::parseDeviceName($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '未知';
        $location = GeoIP::getLocation($ip);
        $time = date('Y-m-d H:i:s');

        $locale = I18n::emailLocale((int)$user['id']);
        $subject = EmailTemplate::subjectPrefix() . I18n::tIn($locale, 'New device sign-in');
        $htmlBody = self::getDeviceNotificationEmailHtml($user['username'], $deviceName, $ip, $location, $time, $locale);

        Email::send($contact, $subject, $htmlBody);
    }

    private static function getDeviceNotificationEmailHtml(string $username, string $device, string $ip, string $location, string $time, string $locale = 'zh-hans'): string
    {
        return EmailTemplate::render([
            'preheader' => I18n::tIn($locale, '你的 {:sitename} 账户刚刚在{:device}上登录', ['device' => $device]),
            'badge'     => ['text' => 'Security alert', 'tone' => 'important'],
            'title'     => 'New device sign-in',
            'subtitle'  => 'A new device signed in to your {:sitename} account',
            'blocks'    => [
                ['type' => 'paragraph', 'text' => I18n::tIn($locale, '你好 {:name}，以下设备刚刚成功登录了你的账户：', ['name' => $username])],
                ['type' => 'info', 'items' => [
                    ['label' => 'Device name', 'value' => $device],
                    ['label' => 'IP address', 'value' => $ip],
                    ...($location !== '' ? [['label' => 'Location', 'value' => $location]] : []),
                    ['label' => 'Time', 'value' => $time],
                ]],
                ['type' => 'alert', 'tone' => 'danger', 'text' => 'If this wasn\'t you, change your password immediately and revoke that device\'s trust in settings.'],
            ],
            'button'    => ['text' => 'View sign-in devices', 'url' => EmailTemplate::url('/settings')],
        ], $locale);
    }
}
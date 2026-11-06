<?php
namespace Nomen\Src;

class Email
{
    private static array $config;

    public static function init(): void
    {
        $configFile = __DIR__ . '/../config/email.php';
        if (file_exists($configFile)) {
            self::$config = require $configFile;
        } else {
            self::$config = [
                'host'       => getenv('SMTP_HOST') ?: '',
                'port'       => getenv('SMTP_PORT') ?: 587,
                'username'   => getenv('SMTP_USERNAME') ?: '',
                'password'   => getenv('SMTP_PASSWORD') ?: '',
                'from_email' => getenv('SMTP_FROM_EMAIL') ?: '',
                'from_name'  => getenv('SMTP_FROM_NAME') ?: 'Nomen',
                'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls',
            ];
        }
    }

    /** 配了 SMTP 主机才算能寄信；没配的站点不该把邮箱验证码摆出来 */
    public static function isConfigured(): bool
    {
        self::init();

        return trim((string)(self::$config['host'] ?? '')) !== '';
    }

    public static function send(string $to, string $subject, string $htmlBody, array $embedded = []): bool
    {
        return self::sendDetailed($to, $subject, $htmlBody, $embedded)['ok'];
    }

    /**
     * 与 send() 相同的投递，但把 SMTP 侧结果带回来（补发脚本要据此判断是限流还是硬拒收）
     *
     * @param array $embedded 正文里用 cid 引用的内嵌图，元素形如
     *                        ['cid' => 'att1', 'name' => 'a.jpg', 'mime' => 'image/jpeg', 'data' => $binary]
     * @return array{ok: bool, code: int, error: string}
     */
    public static function sendDetailed(string $to, string $subject, string $htmlBody, array $embedded = []): array
    {
        self::init();

        $payload = Hooks::filter('email.sending', [
            'to'      => $to,
            'subject' => $subject,
            'body'    => $htmlBody,
        ], $embedded);

        if (is_array($payload)) {
            $to = (string)($payload['to'] ?? $to);
            $subject = (string)($payload['subject'] ?? $subject);
            $htmlBody = (string)($payload['body'] ?? $htmlBody);
        }

        if (empty(self::$config['host'])) {
            error_log('[Email] SMTP 未配置，邮件未发送');
            return ['ok' => false, 'code' => 0, 'error' => 'SMTP 未配置'];
        }

        // 使用 Composer autoload
        $autoloadPath = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($autoloadPath)) {
            error_log('[Email] vendor/autoload.php 不存在，请运行 composer install');
            return ['ok' => false, 'code' => 0, 'error' => 'vendor 缺失'];
        }

        require_once $autoloadPath;

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        try {
            // SMTP 配置
            $mail->isSMTP();
            $mail->Host       = self::$config['host'];
            $mail->SMTPAuth   = (string)self::$config['username'] !== '';
            $mail->Username   = self::$config['username'];
            $mail->Password   = self::$config['password'];
            $mail->SMTPSecure = self::$config['encryption'];
            $mail->Port       = self::$config['port'];
            $mail->CharSet    = 'UTF-8';

            // 发件人
            $mail->setFrom(self::$config['from_email'], self::$config['from_name']);

            // 收件人
            $mail->addAddress($to);

            // 内容
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);

            foreach ($embedded as $item) {
                if (empty($item['cid']) || empty($item['data'])) {
                    continue;
                }
                $mail->addStringEmbeddedImage(
                    $item['data'],
                    $item['cid'],
                    $item['name'] ?? $item['cid'],
                    'base64',
                    $item['mime'] ?? 'application/octet-stream'
                );
            }

            $mail->send();
            return ['ok' => true, 'code' => 250, 'error' => ''];
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            $info = (string)$mail->ErrorInfo;
            error_log('[Email] 发送失败: ' . $info);
            return ['ok' => false, 'code' => self::extractSmtpCode($info), 'error' => $info ?: $e->getMessage()];
        }
    }

    /**
     * 从 PHPMailer 的错误串里取 SMTP 状态码（形如 "SMTP Error: ... 550 ..."），取不到返回 0
     */
    private static function extractSmtpCode(string $info): int
    {
        if (preg_match('/\b([45]\d{2})\b/', $info, $m)) {
            return (int)$m[1];
        }
        return 0;
    }
}

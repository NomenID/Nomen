<?php
/**
 * 把 Nomen\Src\Email 换成一个「只记录、不真发」的桩。
 *
 * 必须在注册自动加载器之前 require：类已声明，自动加载器就不会再去加载真的 Email.php。
 * 于是所有 Email::send() 都会落进 Email::$sent，测试就能逐条数收件人与内容。
 */
namespace Nomen\Src;

class Email
{
    /** @var array<int, array{to:string, subject:string, html:string, embedded:int}> */
    public static array $sent = [];

    public static function send(string $to, string $subject, string $htmlBody, array $embedded = []): bool
    {
        self::$sent[] = [
            'to'       => $to,
            'subject'  => $subject,
            'html'     => $htmlBody,
            'embedded' => count($embedded),
        ];
        return true;
    }

    /** 只按收件人取出上一步发出去的那些信 */
    public static function to(string $address): array
    {
        return array_values(array_filter(self::$sent, static fn(array $m): bool => $m['to'] === $address));
    }

    public static function reset(): void
    {
        self::$sent = [];
    }

    public static function addresses(): array
    {
        return array_values(array_unique(array_column(self::$sent, 'to')));
    }
}

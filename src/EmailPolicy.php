<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

/**
 * 邮箱地址的格式与域名策略。名单在 config/email-policy.php，改名单不用动代码。
 */
class EmailPolicy
{
    /** 被名单拦下时对外的唯一说法，不区分具体是哪个域名 */
    public const REJECT_MESSAGE = '{:sitename} does not accept email addresses on this domain.';

    private static ?array $config = null;

    private static function config(): array
    {
        if (self::$config === null) {
            self::$config = require __DIR__ . '/../config/email-policy.php';
        }
        return self::$config;
    }

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    /** 取 @ 之后的部分，小写；取不到返回空串 */
    public static function domainOf(string $email): string
    {
        $email = self::normalize($email);
        $at = strrpos($email, '@');
        if ($at === false) {
            return '';
        }
        return trim(substr($email, $at + 1), '.');
    }

    /** 最后一个 label，也就是顶级域（`a.b.co.uk` → `uk`） */
    private static function tldOf(string $domain): string
    {
        $parts = explode('.', $domain);
        return (string)end($parts);
    }

    /**
     * 名单命中：域名本身、它的子域、或者整段后缀。
     * 子域也算 —— `example.com` 是保留域，`mail.example.com` 自然也是。
     */
    public static function isBlocked(string $email): bool
    {
        $domain = self::domainOf($email);
        if ($domain === '') {
            return false;
        }

        $config = self::config();
        $domains = array_merge(
            (array)($config['reserved_domains'] ?? []),
            (array)($config['blocked_domains'] ?? [])
        );

        foreach ($domains as $blocked) {
            $blocked = self::normalize((string)$blocked);
            if ($blocked === '') {
                continue;
            }
            if ($domain === $blocked || str_ends_with($domain, '.' . $blocked)) {
                return true;
            }
        }

        $tld = self::tldOf($domain);
        foreach ((array)($config['reserved_tlds'] ?? []) as $blockedTld) {
            if ($tld === self::normalize((string)$blockedTld)) {
                return true;
            }
        }

        return false;
    }

    /** 命中名单时返回对外的文案，否则 null */
    public static function rejectReason(string $email): ?string
    {
        return self::isBlocked($email) ? self::REJECT_MESSAGE : null;
    }

    /**
     * 打码成 `1*********@q*.com`：本地部分留首字、域名留首字 + 顶级域。
     * **星号数量 = 位数**（也就是说会漏长度，2026-10-15 用户明确说不管这个）。
     *
     * 域名也打掉是有意的 —— 只打本地部分的话，域名会把账号暴露得差不多。
     * `RecoveryHelper::maskEmail()` 直接转调这里，所以全站（申诉页、后台工单页、
     * 家庭页的提示）显示联系邮箱都是这一套。
     */
    public static function mask(?string $email): string
    {
        $email = trim((string)$email);
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return $email;   // 不成形的地址原样返回，别在这里造字符串
        }

        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        if ($domain === '') {
            return $email;
        }

        $maskedLocal = mb_substr($local, 0, 1, 'UTF-8')
            . str_repeat('*', max(0, mb_strlen($local, 'UTF-8') - 1));

        // mail.example.co.uk → m*.co.uk；单段域名（localhost 这种）→ l*
        $labels = explode('.', $domain);
        $maskedDomain = count($labels) > 1
            ? mb_substr($domain, 0, 1, 'UTF-8') . '*.' . (string)end($labels)
            : mb_substr($domain, 0, 1, 'UTF-8') . '*';

        return $maskedLocal . '@' . $maskedDomain;
    }
}

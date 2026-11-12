<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

/**
 * 算术验证码：给自部署的小站挡批量脚本，不依赖 GD，也不往第三方服务送数据。
 * 题目和答案都放在会话里，答一次就作废。
 */
final class Captcha
{
    private const SESSION_ANSWER = 'captcha_answer';
    private const SESSION_QUESTION = 'captcha_question';

    /** 保留这个判断给调用方：算术题不需要任何扩展。 */
    public static function available(): bool
    {
        return true;
    }

    /** 生成一道题并记到会话里，返回题面。 */
    public static function question(): string
    {
        $a = random_int(2, 9);
        $b = random_int(1, 9);

        if (random_int(0, 1) === 0) {
            $text = $a . ' + ' . $b . ' = ?';
            $answer = $a + $b;
        } else {
            if ($b > $a) {
                [$a, $b] = [$b, $a];
            }
            $text = $a . ' − ' . $b . ' = ?';
            $answer = $a - $b;
        }

        Session::set(self::SESSION_QUESTION, $text);
        Session::set(self::SESSION_ANSWER, (string)$answer);

        return $text;
    }

    /** GET /captcha：前端拿题面用。 */
    public static function generate(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        echo json_encode(['question' => self::question()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** 校验答案，顺便把这道题作废。 */
    public static function verify(?string $input): bool
    {
        $expected = (string)Session::get(self::SESSION_ANSWER, '');
        Session::remove(self::SESSION_ANSWER);
        Session::remove(self::SESSION_QUESTION);

        if ($expected === '' || $input === null) {
            return false;
        }

        $input = trim($input);
        if (!preg_match('/^-?\d{1,3}$/', $input)) {
            return false;
        }

        return hash_equals($expected, (string)(int)$input);
    }
}
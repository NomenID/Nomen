<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

use Nomen\Src\Captcha;

/** 从题面算答案：'7 + 3 = ?' / '7 − 3 = ?' */
$answerOf = static function (string $question): string {
    if (preg_match('/^(\d+) ([-+−]) (\d+) = \?$/u', $question, $m) !== 1) {
        throw new RuntimeException('题面形状不认识：' . $question);
    }

    $a = (int)$m[1];
    $b = (int)$m[3];

    return (string)($m[2] === '+' ? $a + $b : $a - $b);
};

test('算术验证码题面是纯文本算式', static function (): void {
    $_SESSION = [];

    for ($i = 0; $i < 50; $i++) {
        $question = Captcha::question();
        assertMatches('/^\d+ [-+−] \d+ = \?$/u', $question);
        assertTrue((int)$question[0] <= 9, '题面不该出现两位数：' . $question);
    }
});

test('算术验证码答对通过、答错或非数字拒绝', static function () use ($answerOf): void {
    $_SESSION = [];
    $question = Captcha::question();
    assertTrue(Captcha::verify($answerOf($question)));

    $_SESSION = [];
    $question = Captcha::question();
    assertTrue(!Captcha::verify('abc'));

    $_SESSION = [];
    $question = Captcha::question();
    $wrong = (string)((int)$answerOf($question) + 1);
    assertTrue(!Captcha::verify($wrong));

    $_SESSION = [];
    assertTrue(!Captcha::verify('1'), '没出题时应拒绝');
});

test('算术验证码答一次就作废', static function () use ($answerOf): void {
    $_SESSION = [];
    $question = Captcha::question();
    $answer = $answerOf($question);

    assertTrue(Captcha::verify($answer));
    assertTrue(!Captcha::verify($answer), '同一答案不能用第二次');
});

test('算术验证码接受前后空格', static function () use ($answerOf): void {
    $_SESSION = [];
    $question = Captcha::question();

    assertTrue(Captcha::verify(' ' . $answerOf($question) . ' '));
});

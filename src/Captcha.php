<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

class Captcha
{
    /**
     * GD 没装就没有验证码：登录退化成只靠限流，注册不再拦验证码。
     */
    public static function available(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagepng');
    }

    /**
     * 生成验证码图片并输出到浏览器
     */
    public static function generate(): void
    {
        if (!self::available()) {
            http_response_code(404);
            exit;
        }

        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }

        // 存 session（小写，不区分大小写）
        Session::set('captcha_code', strtolower($code));

        $width = 120;
        $height = 40;
        $img = imagecreatetruecolor($width, $height);

        $bg = imagecolorallocate($img, 245, 245, 245);
        imagefilledrectangle($img, 0, 0, $width, $height, $bg);

        // 干扰线
        for ($i = 0; $i < 5; $i++) {
            $lineColor = imagecolorallocate($img, random_int(100, 200), random_int(100, 200), random_int(100, 200));
            imageline($img, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $lineColor);
        }

        // 干扰点
        for ($i = 0; $i < 50; $i++) {
            $dotColor = imagecolorallocate($img, random_int(150, 220), random_int(150, 220), random_int(150, 220));
            imagesetpixel($img, random_int(0, $width), random_int(0, $height), $dotColor);
        }

        // 文字
        for ($i = 0; $i < 4; $i++) {
            $textColor = imagecolorallocate($img, random_int(30, 100), random_int(30, 100), random_int(30, 100));
            $fontSize = 5;
            $x = 15 + $i * 24;
            $y = random_int(5, 12);
            imagechar($img, $fontSize, $x, $y, $code[$i], $textColor);
        }

        header('Content-Type: image/png');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        imagepng($img);
        imagedestroy($img);
        exit;
    }

    /**
     * 校验验证码（不区分大小写，用后即焚）
     */
    public static function verify(?string $input): bool
    {
        $expected = Session::get('captcha_code');
        Session::remove('captcha_code');

        if (!$expected || !$input) {
            return false;
        }

        return hash_equals($expected, strtolower(trim($input)));
    }
}

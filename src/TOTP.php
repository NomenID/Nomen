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
 * 纯PHP TOTP实现（RFC 6238）
 * 无外部依赖，自己生成和验证
 */
class TOTP
{
    private const TOTP_EPOCH = 0;
    private const SECRET_LENGTH = 20; // 160位密钥

    /**
     * 生成随机Base32密钥
     */
    public static function generateSecret(): string
    {
        $bytes = random_bytes(self::SECRET_LENGTH);
        return self::base32Encode($bytes);
    }

    /**
     * 获取当前TOTP值
     */
    public static function getCode(string $secret, int $timeSlice = null): string
    {
        if ($timeSlice === null) {
            $timeSlice = self::getTimeSlice();
        }

        // 将时间步长转为8字节大端序
        $msg = pack('J', $timeSlice);

        // Base32解码密钥
        $key = self::base32Decode($secret);

        // HMAC-SHA1
        $hash = hash_hmac('sha1', $msg, $key, true);

        // 动态截断（RFC 4226 Section 5.3）
        $offset = ord($hash[19]) & 0x0f;
        $binary = (
            (ord($hash[$offset]) & 0x7f) << 24 |
            (ord($hash[$offset + 1]) & 0xff) << 16 |
            (ord($hash[$offset + 2]) & 0xff) << 8 |
            (ord($hash[$offset + 3]) & 0xff)
        );

        // 取模6位数字
        $otp = $binary % 1000000;
        return str_pad((string)$otp, 6, '0', STR_PAD_LEFT);
    }

    /**
     * 验证TOTP码（支持前后一个时间步长容差）
     */
    public static function verify(string $secret, string $code, int $tolerance = 1): bool
    {
        $timeSlice = self::getTimeSlice();

        for ($i = -$tolerance; $i <= $tolerance; $i++) {
            $generated = self::getCode($secret, $timeSlice + $i);
            if (hash_equals($generated, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 生成 otpauth:// URI（用于QR码）
     */
    public static function getProvisioningUri(string $secret, string $label, ?string $issuer = null): string
    {
        $issuer = $issuer ?: (string)Brand::get('name', 'Nomen');
        $label = rawurlencode($label);
        $issuer = rawurlencode($issuer);
        $secret = rawurlencode($secret);
        return "otpauth://totp/{$issuer}:{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * 获取当前时间步长
     */
    private static function getTimeSlice(): int
    {
        return (int)floor((time() - self::TOTP_EPOCH) / 30);
    }

    /**
     * Base32编码（RFC 4648，无填充）
     */
    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $result = '';
        $len = strlen($data);
        $bits = 0;
        $buffer = 0;

        for ($i = 0; $i < $len; $i++) {
            $buffer = ($buffer << 8) | ord($data[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $result .= $alphabet[($buffer >> $bits) & 0x1f];
            }
        }

        if ($bits > 0) {
            $result .= $alphabet[($buffer << (5 - $bits)) & 0x1f];
        }

        return $result;
    }

    /**
     * Base32解码（RFC 4648）
     */
    private static function base32Decode(string $data): string
    {
        $data = strtoupper($data);
        $data = str_replace('=', '', $data);
        $result = '';
        $bits = 0;
        $buffer = 0;
        $len = strlen($data);

        for ($i = 0; $i < $len; $i++) {
            $char = $data[$i];
            if ($char >= 'A' && $char <= 'Z') {
                $value = ord($char) - 65;
            } elseif ($char >= '2' && $char <= '7') {
                $value = ord($char) - 24;
            } else {
                continue; // 跳过无效字符
            }

            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $result .= chr(($buffer >> $bits) & 0xff);
            }
        }

        return $result;
    }
}

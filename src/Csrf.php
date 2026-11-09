<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

class Csrf
{
    public static function generateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        Session::set('csrf_token', $token);
        return $token;
    }

    public static function getToken(): string
    {
        $token = Session::get('csrf_token');
        if (!$token) {
            $token = self::generateToken();
        }
        return $token;
    }

    public static function field(): string
    {
        $token = self::getToken();
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($token) . '">';
    }

    public static function validate(?string $token): bool
    {
        if (!$token) {
            return false;
        }
        $expected = Session::get('csrf_token');
        return hash_equals($expected ?? '', $token);
    }
}

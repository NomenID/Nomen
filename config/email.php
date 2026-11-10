<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

$brand = require __DIR__ . '/brand.php';
$installed = is_file(__DIR__ . '/installed.php') ? require __DIR__ . '/installed.php' : [];
$smtp = $installed['smtp'] ?? [];

return [
    'host'       => getenv('SMTP_HOST') ?: ($smtp['host'] ?? ''),
    'port'       => (int)(getenv('SMTP_PORT') ?: ($smtp['port'] ?? 587)),
    'username'   => getenv('SMTP_USERNAME') ?: ($smtp['username'] ?? ''),
    'password'   => getenv('SMTP_PASSWORD') ?: ($smtp['password'] ?? ''),
    'from_email' => getenv('SMTP_FROM_EMAIL') ?: ($smtp['from_email'] ?? ''),
    'from_name'  => getenv('SMTP_FROM_NAME') ?: ($smtp['from_name'] ?? $brand['name']),
    'encryption' => getenv('SMTP_ENCRYPTION') ?: ($smtp['encryption'] ?? 'tls'),
];

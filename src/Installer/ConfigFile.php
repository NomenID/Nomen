<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src\Installer;

class ConfigFile
{
    /**
     * 生成 config/installed.php 的内容。
     */
    public static function render(array $data): string
    {
        return "<?php\n"
            . "// 安装器生成，包含数据库密码和 pepper，别提交、别外传。\n"
            . "return " . var_export($data, true) . ";\n";
    }
}

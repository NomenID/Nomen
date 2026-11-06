<?php
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

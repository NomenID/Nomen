<?php
namespace Nomen\Src;

class Response
{
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function redirect(string $url, int $status = 302): void
    {
        http_response_code($status);
        header("Location: $url");
        exit;
    }

    public static function view(string $template, array $data = [], string $layout = 'default'): void
    {
        if (!function_exists('t')) {
            require_once __DIR__ . '/I18n.php';
        }
        extract($data);
        $templatePath = Skins::template($template);

        if ($templatePath === null) {
            self::error(500, "模板 {$template} 不存在");
            return;
        }

        ob_start();
        require $templatePath;
        $content = ob_get_clean();

        $layoutFile = Skins::layoutFile($layout);
        if ($layoutFile === null) {
            self::error(500, "皮肤里没有 layout: {$layout}");
            return;
        }

        // 布局在 skins/<slug>/layouts/ 下，公共片段仍从 templates/ 取。
        // 布局渲染也进缓冲：中途抛异常（比如数据库挂了）时半截页面留在缓冲里，
        // 由全局处理器丢掉并换成干净的 500，而不是把 200 + 半截 HTML 发给访客
        ob_start();
        require $layoutFile;
        ob_end_flush();
    }

    public static function viewPartial(string $template, array $data = []): string
    {
        if (!function_exists('t')) {
            require_once __DIR__ . '/I18n.php';
        }
        extract($data);
        $templatePath = Skins::template($template);

        if ($templatePath === null) {
            return '';
        }

        ob_start();
        require $templatePath;
        return ob_get_clean();
    }

    public static function error(int $code, string $message = ''): void
    {
        // 独立渲染器：不碰语言包 / 皮肤 / 数据库，报错这步自己不能再二次炸掉。
        // show() 内部会 exit —— 调用方靠这个守卫，后面不会继续执行
        ErrorPage::show($code, $message);
    }
}

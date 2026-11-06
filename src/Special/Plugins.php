<?php
namespace Nomen\Src\Special;

use Nomen\Src\Captcha;
use Nomen\Src\Plugins as PluginRegistry;

class Plugins
{
    public static function render(): string
    {
        $all = PluginRegistry::all();
        $loaded = PluginRegistry::loaded();
        $enabled = PluginRegistry::enabled();

        $html = '<table class="table"><tr><th>插件</th><th>版本</th><th>状态</th></tr>';
        foreach ($all as $slug => $manifest) {
            if (isset($loaded[$slug])) {
                $status = '运行中';
            } elseif (in_array($slug, $enabled, true)) {
                $status = '启用失败（依赖不满足，见 error_log）';
            } else {
                $status = '未启用';
            }
            $html .= '<tr><td>' . htmlspecialchars((string)$manifest['name'])
                . '<br><span class="form-hint">' . htmlspecialchars(t((string)($manifest['description'] ?? ''))) . '</span></td>'
                . '<td>' . htmlspecialchars((string)($manifest['version'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars($status) . '</td></tr>';
        }
        $html .= '</table>';

        $html .= '<p class="form-hint">启用插件：把目录名加进 <code>config/plugins.php</code> 的 <code>enabled</code>。'
            . 'GeoIP 还要先 <code>composer require geoip2/geoip2</code>，并下载数据文件。</p>';

        $caps = [
            ['gd', '登录验证码图片、申诉截图的缩略图', Captcha::available()],
            ['fileinfo', '头像和应用图标的上传', function_exists('finfo_open')],
            ['redis', '限流、多设备踢出（没有会降级）', extension_loaded('redis')],
        ];

        $html .= '<h2>PHP 扩展</h2><table class="table"><tr><th>扩展</th><th>管什么</th><th>状态</th></tr>';
        foreach ($caps as [$ext, $what, $ok]) {
            $html .= '<tr><td>' . htmlspecialchars($ext) . '</td><td>' . htmlspecialchars($what) . '</td>'
                . '<td>' . ($ok ? '有' : '没装') . '</td></tr>';
        }
        $html .= '</table>';

        return $html;
    }
}

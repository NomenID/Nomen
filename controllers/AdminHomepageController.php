<?php
namespace Nomen\Controllers;

use Nomen\Src\{ActivityLog, Admin, Csrf, Homepage, Response, Settings};
use PDOException;

class AdminHomepageController
{
    public function show(): void
    {
        Admin::requireAdmin();

        $hidden = Homepage::hiddenLinks();
        $defaultLinks = [];
        foreach (Homepage::defaultLinks() as $link) {
            $link['hidden'] = in_array($link['id'], $hidden, true);
            $link['source'] = match ($link['id']) {
                'docs'          => 'Pages 插件挂载',
                'open_platform' => '开放平台插件挂载',
                default         => '核心提供',
            };
            $defaultLinks[] = $link;
        }

        // 插件经 homepage.links 钩子加的条目 = 渲染结果里除默认与自定义之外的部分
        $rendered = Homepage::links();
        $own = [];
        foreach ($defaultLinks as $link) {
            if (!$link['hidden']) {
                $own[] = $link['label'] . "\x1f" . $link['href'];
            }
        }
        foreach (Homepage::customLinks() as $link) {
            $own[] = $link['label'] . "\x1f" . $link['href'];
        }
        $pluginLinks = [];
        foreach ($rendered as $link) {
            $key = (string)($link['label'] ?? '') . "\x1f" . (string)($link['href'] ?? '');
            if (!in_array($key, $own, true)) {
                $pluginLinks[] = ['label' => (string)($link['label'] ?? ''), 'href' => (string)($link['href'] ?? ''), 'by' => (string)($link['by'] ?? '')];
            }
        }

        Response::json([
            'success'       => true,
            'settings'      => Homepage::settings(),
            'modes'         => [
                ['value' => 'default', 'label' => '默认首页', 'hint' => '标题、副标题和按钮，版式由皮肤提供'],
                ['value' => 'page', 'label' => '指定页面', 'hint' => '用 Pages 里的一篇文档当首页，适合公告或介绍'],
                ['value' => 'template', 'label' => '自定义模板', 'hint' => '在 config 下放 home-xxx.php，自己写 HTML'],
            ],
            'pages'         => Homepage::availablePages(),
            'templates'     => Homepage::templateOptions(),
            'default_links' => $defaultLinks,
            'custom_links'  => Homepage::customLinks(),
            'plugin_links'  => $pluginLinks,
        ]);
    }

    public function update(): void
    {
        $admin = Admin::requireAdmin();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => '请求无效，请重试'], 422);
        }

        $mode = (string)($_POST['mode'] ?? 'default');
        if (!in_array($mode, Homepage::MODES, true)) {
            Response::json(['success' => false, 'message' => '首页模式不在列表里'], 422);
        }

        $pageSlug = (string)($_POST['page_slug'] ?? '');
        if ($mode === 'page') {
            $slugs = array_column(Homepage::availablePages(), 'slug');
            if ($pageSlug !== '' && !in_array($pageSlug, $slugs, true)) {
                Response::json(['success' => false, 'message' => '选择的页面不存在或未发布'], 422);
            }
        }

        $template = (string)($_POST['template'] ?? '');
        if ($mode === 'template') {
            if ($template !== '' && Homepage::templateFile($template) === null) {
                Response::json(['success' => false, 'message' => '自定义模板不存在，确认文件在 config/ 下'], 422);
            }
        }

        $url = static function (string $value): ?string {
            $value = trim($value);
            if ($value === '') {
                return '';
            }
            if (str_starts_with($value, '/')) {
                return $value;
            }
            return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
        };

        $primaryUrl = $url((string)($_POST['primary_url'] ?? ''));
        $secondaryUrl = $url((string)($_POST['secondary_url'] ?? ''));
        if ($primaryUrl === null || $secondaryUrl === null) {
            Response::json(['success' => false, 'message' => '按钮地址要填站内路径（/ 开头）或完整 http(s) 地址'], 422);
        }

        $text = static fn(string $value, int $max): string => mb_substr(trim($value), 0, $max);

        // 默认链接的隐藏名单：只认这三个 id，其余忽略
        $hiddenInput = json_decode((string)($_POST['hidden_links'] ?? '[]'), true);
        $hidden = [];
        if (is_array($hiddenInput)) {
            foreach ($hiddenInput as $id) {
                if (in_array((string)$id, ['docs', 'open_platform', 'version'], true)) {
                    $hidden[] = (string)$id;
                }
            }
        }

        // 自定义链接：整行为空跳过，半空或地址不合法整单打回
        $linksInput = json_decode((string)($_POST['links_json'] ?? '[]'), true);
        $custom = [];
        if (is_array($linksInput)) {
            foreach ($linksInput as $row) {
                if (!is_array($row) || count($custom) >= 20) {
                    continue;
                }
                $label = trim((string)($row['label'] ?? ''));
                $hrefValue = trim((string)($row['href'] ?? ''));
                if ($label === '' && $hrefValue === '') {
                    continue;
                }
                $href = $url($hrefValue);
                if ($label === '' || $href === null || $href === '') {
                    Response::json(['success' => false, 'message' => '自定义链接每条都要有名称，地址要填站内路径（/ 开头）或完整 http(s) 地址'], 422);
                }
                $custom[] = ['label' => $text($label, 40), 'href' => $href];
            }
        }

        $values = [
            'homepage.hidden_links' => json_encode($hidden, JSON_UNESCAPED_UNICODE),
            'homepage.links_json'   => json_encode($custom, JSON_UNESCAPED_UNICODE),
            'homepage.mode'            => $mode,
            'homepage.page_slug'       => $mode === 'page' ? $pageSlug : '',
            'homepage.template'        => $mode === 'template' ? basename($template) : '',
            'homepage.title'           => $text((string)($_POST['title'] ?? ''), 120),
            'homepage.subtitle'        => $text((string)($_POST['subtitle'] ?? ''), 200),
            'homepage.primary_label'   => $text((string)($_POST['primary_label'] ?? ''), 40),
            'homepage.primary_url'     => (string)$primaryUrl,
            'homepage.secondary_label' => $text((string)($_POST['secondary_label'] ?? ''), 40),
            'homepage.secondary_url'   => (string)$secondaryUrl,
        ];

        try {
            Settings::setMany($values);
        } catch (PDOException $e) {
            error_log('[homepage] 保存失败: ' . $e->getMessage());
            Response::json(['success' => false, 'message' => '数据库还没升级（缺 site_settings 表），先跑 php bin/nomen upgrade'], 500);
        }

        ActivityLog::record([
            'action'         => 'admin.homepage_update',
            'category'       => ActivityLog::CAT_ADMIN,
            'actor_type'     => 'admin',
            'actor_id'       => (int)$admin['id'],
            'actor_username' => (string)$admin['username'],
            'summary'        => '修改首页设置',
        ]);

        Response::json(['success' => true, 'message' => '首页设置已保存']);
    }
}

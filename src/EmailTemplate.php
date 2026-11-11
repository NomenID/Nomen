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
 * 全站统一邮件外壳。浅色为主，支持 prefers-color-scheme 深色自适应。
 * table 布局 + 内联样式兜底，保证 Gmail / QQ 邮箱 / Outlook / 手机端渲染一致。
 *
 * 结构（与站内消息中心逐项对应）：品牌头 → 类别徽章 → 标题/副标题 → 内容块 → 主按钮 → 页脚
 */
class EmailTemplate
{
    public static function site(): string
    {
        return rtrim((string)Brand::get('base_url'), '/');
    }

    public static function logo(): string
    {
        $logo = (string)Brand::get('logo');
        if (str_starts_with($logo, 'http://') || str_starts_with($logo, 'https://')) {
            return $logo;
        }

        return self::site() . '/' . ltrim($logo, '/');
    }

    public static function url(string $path = ''): string
    {
        return self::site() . Url::to('/' . ltrim($path, '/'));
    }

    public static function privacyUrl(): string
    {
        return (string)Brand::get('privacy_url');
    }

    /** 邮件主题前缀：站点名。调用点别写死品牌 */
    public static function subjectPrefix(): string
    {
        return (string)Brand::get('name', 'Nomen') . ' - ';
    }

    // 与站内消息页类别徽章同源（浅色 / 深色两套）
    private const TONES = [
        'important' => ['bg' => '#ffebe9', 'fg' => '#cf222e', 'bd' => '#ffcecb', 'dkBg' => '#3c1618', 'dkFg' => '#ff7b72'],
        'general'   => ['bg' => '#ddf4ff', 'fg' => '#0969da', 'bd' => '#b6e3ff', 'dkBg' => '#0c2d6b', 'dkFg' => '#79c0ff'],
        'interact'  => ['bg' => '#dafbe1', 'fg' => '#1a7f37', 'bd' => '#aceebb', 'dkBg' => '#0f2f1a', 'dkFg' => '#7ee787'],
        'warning'   => ['bg' => '#fff8c5', 'fg' => '#9a6700', 'bd' => '#f5e08a', 'dkBg' => '#3a2d04', 'dkFg' => '#e3b341'],
        'neutral'   => ['bg' => '#f6f8fa', 'fg' => '#59636e', 'bd' => '#d1d9e0', 'dkBg' => '#21262d', 'dkFg' => '#8b949e'],
        'danger'    => ['bg' => '#ffebe9', 'fg' => '#cf222e', 'bd' => '#ffcecb', 'dkBg' => '#3c1618', 'dkFg' => '#ff7b72'],
        'success'   => ['bg' => '#dafbe1', 'fg' => '#1a7f37', 'bd' => '#aceebb', 'dkBg' => '#0f2f1a', 'dkFg' => '#7ee787'],
    ];

    public static function render(array $opts, ?string $locale = null): string
    {
        $locale = $locale ?? I18n::locale();
        // 静态文案字段按收件语言翻译（tIn 对缺翻译/非中文的值原样返回，幂等）；
        // 数据字段（info 的 value、code 的 value、各种 url）不翻
        $x = static fn(?string $k): string => $k === null ? '' : I18n::tIn($locale, $k);

        $title     = $x((string)($opts['title'] ?? 'Nomen'));
        $subtitle  = $x((string)($opts['subtitle'] ?? ''));
        $preheader = $x((string)($opts['preheader'] ?? ''));
        $badge     = $opts['badge'] ?? null;
        $blocks    = $opts['blocks'] ?? [];
        $button    = $opts['button'] ?? null;
        $footnote  = $x((string)($opts['footnote'] ?? ''));
        $prefsUrl  = (string)($opts['prefs_url'] ?? self::url('/settings#sec-notifications'));

        foreach ($blocks as $i => $b) {
            switch ($b['type'] ?? 'paragraph') {
                case 'paragraph':
                case 'heading':
                case 'alert':
                    if (!empty($b['text'])) {
                        $blocks[$i]['text'] = $x($b['text']);
                    }
                    break;
                case 'code':
                case 'link':
                    if (!empty($b['label'])) {
                        $blocks[$i]['label'] = $x($b['label']);
                    }
                    break;
                case 'info':
                    foreach (($b['items'] ?? []) as $j => $it) {
                        if (!empty($it['label'])) {
                            $blocks[$i]['items'][$j]['label'] = $x($it['label']);
                        }
                    }
                    break;
                case 'list':
                    foreach (($b['items'] ?? []) as $j => $it) {
                        if (is_string($it) && $it !== '') {
                            $blocks[$i]['items'][$j] = $x($it);
                        }
                    }
                    break;
            }
        }

        if ($badge && !empty($badge['text'])) {
            $badge['text'] = $x($badge['text']);
        }
        if ($button && !empty($button['text'])) {
            $button['text'] = $x($button['text']);
        }

        $e = static fn(?string $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $t = static fn(string $key): string => I18n::tIn($locale, $key);

        $badgeHtml = '';
        if ($badge && !empty($badge['text'])) {
            $tone = self::TONES[$badge['tone'] ?? 'neutral'] ?? self::TONES['neutral'];
            $badgeHtml = '<span class="nomen-badge nomen-badge--' . $e($badge['tone'] ?? 'neutral') . '" '
                . 'style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;'
                . 'line-height:18px;background:' . $tone['bg'] . ';color:' . $tone['fg'] . ';border:1px solid ' . $tone['bd'] . ';">'
                . $e($badge['text']) . '</span>';
        }

        $bodyHtml = '';
        foreach ($blocks as $block) {
            $bodyHtml .= self::renderBlock($block, $e);
        }

        $buttonHtml = '';
        if ($button && !empty($button['url'])) {
            $buttonHtml = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:28px 0 8px;">'
                . '<tr><td class="nomen-btn" align="center" bgcolor="#0969da" '
                . 'style="border-radius:8px;background:#0969da;">'
                . '<a href="' . $e($button['url']) . '" target="_blank" class="nomen-btn__link" '
                . 'style="display:inline-block;padding:13px 32px;font-size:15px;font-weight:600;color:#ffffff;'
                . 'text-decoration:none;border-radius:8px;">' . $e($button['text'] ?? $t('Take care of it')) . '</a>'
                . '</td></tr></table>';
        }

        $subtitleHtml = $subtitle !== ''
            ? '<p class="nomen-subtitle" style="margin:8px 0 0;font-size:14px;line-height:1.6;color:#59636e;">' . $e($subtitle) . '</p>'
            : '';

        $footnoteHtml = $footnote !== ''
            ? '<p class="nomen-footnote" style="margin:20px 0 0;padding-top:16px;border-top:1px solid #d1d9e0;'
              . 'font-size:12px;line-height:1.7;color:#59636e;">' . $footnote . '</p>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="{$e(I18n::code($locale))}" dir="{$e(I18n::dir($locale))}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<meta name="x-apple-disable-message-reformatting">
<title>{$e($title)}</title>
<style>
    :root { color-scheme: light dark; supported-color-schemes: light dark; }
    body { margin:0; padding:0; width:100% !important; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
    table { border-collapse:collapse; }
    img { border:0; outline:none; text-decoration:none; -ms-interpolation-mode:bicubic; }
    a { color:#0969da; }
    .nomen-body p { margin:0 0 14px; }
    .nomen-body p:last-child { margin-bottom:0; }
    @media (max-width:600px) {
        .nomen-wrap { padding:12px 8px !important; }
        .nomen-pad { padding-left:22px !important; padding-right:22px !important; }
        .nomen-title { font-size:19px !important; }
        .nomen-code { font-size:26px !important; letter-spacing:4px !important; }
        .nomen-btn__link { display:block !important; }
    }
    @media (prefers-color-scheme: dark) {
        body { background-color:#0d1117 !important; }
        .nomen-wrap { background:#0d1117 !important; }
        .nomen-card { background:#161b22 !important; border-color:#30363d !important; }
        .nomen-head { background:#1c2129 !important; border-color:#30363d !important; }
        .nomen-title { color:#e6edf3 !important; }
        .nomen-subtitle, .nomen-footnote, .nomen-muted, .nomen-label { color:#8b949e !important; }
        .nomen-text, .nomen-value { color:#e6edf3 !important; }
        .nomen-box { background:#0d1117 !important; border-color:#30363d !important; color:#e6edf3 !important; }
        .nomen-code { color:#79c0ff !important; }
        .nomen-divider { border-color:#30363d !important; }
        .nomen-footnote { border-color:#30363d !important; }
        .nomen-footer { background:#1c2129 !important; border-color:#30363d !important; }
        .nomen-alert--warning { background:#3a2d04 !important; border-color:#9a6700 !important; color:#e3b341 !important; }
        .nomen-alert--info    { background:#0c2d6b !important; border-color:#0969da !important; color:#79c0ff !important; }
        .nomen-alert--danger  { background:#3c1618 !important; border-color:#cf222e !important; color:#ff7b72 !important; }
        .nomen-alert--success { background:#0f2f1a !important; border-color:#1a7f37 !important; color:#7ee787 !important; }
        .nomen-badge--important, .nomen-badge--danger { background:#3c1618 !important; color:#ff7b72 !important; border-color:#6e2a2e !important; }
        .nomen-badge--general { background:#0c2d6b !important; color:#79c0ff !important; border-color:#1f4b99 !important; }
        .nomen-badge--interact, .nomen-badge--success { background:#0f2f1a !important; color:#7ee787 !important; border-color:#1f6f37 !important; }
        .nomen-badge--warning { background:#3a2d04 !important; color:#e3b341 !important; border-color:#7d5900 !important; }
        .nomen-badge--neutral { background:#21262d !important; color:#8b949e !important; border-color:#30363d !important; }
        .nomen-btn { background:#1f6feb !important; }
        .nomen-btn__link { color:#ffffff !important; }
        .nomen-brand { color:#8b949e !important; }
        .nomen-brand-name { color:#e6edf3 !important; }
    }
</style>
</head>
<body style="margin:0;padding:0;background:#f6f8fa;">
<div style="display:none;font-size:1px;color:#f6f8fa;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">{$e($preheader)}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="nomen-wrap" style="background:#f6f8fa;padding:32px 12px;">
<tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" class="nomen-card" style="width:600px;max-width:100%;background:#ffffff;border:1px solid #d1d9e0;border-radius:14px;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Hiragino Sans GB','Microsoft YaHei',sans-serif;">
    <tr><td class="nomen-head nomen-pad" align="center" style="background:#f6f8fa;border-bottom:1px solid #d1d9e0;padding:28px 40px 24px;">
        <a href="{$e(self::site())}" target="_blank" style="text-decoration:none;">
            <img src="{$e(self::logo())}" alt="{$e((string)Brand::get('name'))}" width="44" height="44" style="display:block;margin:0 auto 10px;width:44px;height:44px;border-radius:10px;">
        </a>
        <div class="nomen-brand-name" style="font-size:16px;font-weight:700;color:#1f2328;letter-spacing:0.3px;">{$e((string)Brand::get('name'))}</div>
        <div style="margin-top:12px;">{$badgeHtml}</div>
    </td></tr>
    <tr><td class="nomen-body nomen-pad" style="padding:32px 40px 36px;">
        <h1 class="nomen-title" style="margin:0;font-size:19px;line-height:1.55;font-weight:700;color:#1f2328;">{$e($title)}</h1>
        {$subtitleHtml}
        <div style="height:22px;line-height:22px;font-size:0;">&nbsp;</div>
        {$bodyHtml}
        {$buttonHtml}
        {$footnoteHtml}
    </td></tr>
    <tr><td class="nomen-footer nomen-pad" align="center" style="background:#f6f8fa;border-top:1px solid #d1d9e0;padding:22px 40px 24px;">
        <div class="nomen-brand" style="font-size:13px;color:#59636e;line-height:1.7;">
            <a href="{$e(self::site())}" target="_blank" style="color:#0969da;text-decoration:none;font-weight:600;">{$e((string)Brand::get('name'))}</a>
            &nbsp;·&nbsp;
            <a href="{$e($prefsUrl)}" target="_blank" style="color:#0969da;text-decoration:none;">{$t('Manage email notification preferences')}</a>
        </div>
        <div class="nomen-brand" style="font-size:12px;color:#8b949e;line-height:1.7;margin-top:8px;">
            {$t('This email was sent automatically — do not reply directly.')}
        </div>
    </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
HTML;
    }

    private static function renderBlock(array $b, callable $e): string
    {
        switch ($b['type'] ?? 'paragraph') {
            case 'paragraph':
                $cls = !empty($b['muted']) ? 'nomen-muted' : 'nomen-text';
                $color = !empty($b['muted']) ? '#59636e' : '#1f2328';
                // 段内换行按 <br> 保留：通知正文里的编号项就是这样一行一条
                $text = nl2br($e($b['text'] ?? ''), false);
                return '<p class="' . $cls . '" style="margin:0 0 14px;font-size:15px;line-height:1.75;color:' . $color . ';">'
                    . $text . '</p>';

            case 'heading':
                return '<h2 class="nomen-text" style="margin:22px 0 10px;font-size:16px;font-weight:700;color:#1f2328;">'
                    . $e($b['text'] ?? '') . '</h2>';

            case 'divider':
                return '<hr class="nomen-divider" style="border:none;border-top:1px solid #d1d9e0;margin:24px 0;">';

            case 'code':
                $label = !empty($b['label'])
                    ? '<div class="nomen-label" style="font-size:12px;font-weight:600;letter-spacing:0.4px;color:#59636e;margin-bottom:8px;">' . $e($b['label']) . '</div>'
                    : '';
                return '<div style="margin:0 0 18px;">' . $label
                    . '<div class="nomen-box nomen-code" style="background:#f6f8fa;border:1px dashed #d1d9e0;border-radius:10px;'
                    . 'padding:20px 14px;font-family:\'SF Mono\',Consolas,\'Courier New\',monospace;font-size:30px;font-weight:700;'
                    . 'letter-spacing:6px;text-align:center;color:#0969da;word-break:break-all;">' . $e($b['value'] ?? '') . '</div></div>';

            case 'link':
                $label = !empty($b['label'])
                    ? '<div class="nomen-label" style="font-size:12px;font-weight:600;letter-spacing:0.4px;color:#59636e;margin-bottom:8px;">' . $e($b['label']) . '</div>'
                    : '';
                return '<div style="margin:0 0 18px;">' . $label
                    . '<div class="nomen-box" style="background:#f6f8fa;border:1px dashed #d1d9e0;border-radius:10px;padding:16px;'
                    . 'font-family:\'SF Mono\',Consolas,\'Courier New\',monospace;font-size:13px;line-height:1.7;color:#1f2328;'
                    . 'word-break:break-all;">' . $e($b['url'] ?? '') . '</div></div>';

            case 'info':
                $rows = '';
                foreach (($b['items'] ?? []) as $i => $item) {
                    $top = $i === 0 ? '0' : '12px';
                    $rows .= '<tr>'
                        . '<td class="nomen-label" style="padding:' . $top . ' 12px 0 0;font-size:12px;font-weight:600;'
                        . 'letter-spacing:0.4px;color:#59636e;white-space:nowrap;vertical-align:top;width:96px;">'
                        . $e($item['label'] ?? '') . '</td>'
                        . '<td class="nomen-value" style="padding:' . $top . ' 0 0;font-size:14px;line-height:1.7;'
                        . 'font-weight:500;color:#1f2328;word-break:break-word;">' . $e($item['value'] ?? '') . '</td>'
                        . '</tr>';
                }
                return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
                    . 'style="margin:0 0 18px;background:#f6f8fa;border:1px solid #d1d9e0;border-radius:10px;" class="nomen-box">'
                    . '<tr><td style="padding:16px 18px;"><table role="presentation" width="100%" cellpadding="0" '
                    . 'cellspacing="0" border="0">' . $rows . '</table></td></tr></table>';

            case 'list':
                $ordered = !empty($b['ordered']);
                $tag = $ordered ? 'ol' : 'ul';
                $items = '';
                foreach (($b['items'] ?? []) as $item) {
                    $items .= '<li class="nomen-text" style="margin:0 0 8px;font-size:15px;line-height:1.75;color:#1f2328;">'
                        . $e($item) . '</li>';
                }
                return '<' . $tag . ' style="margin:0 0 16px;padding-left:22px;">' . $items . '</' . $tag . '>';

            case 'alert':
                $tone = in_array($b['tone'] ?? 'info', ['warning', 'info', 'danger', 'success'], true)
                    ? $b['tone'] : 'info';
                $palette = [
                    'warning' => ['bg' => '#fff8c5', 'bd' => '#d4a72c', 'fg' => '#7d4e00'],
                    'info'    => ['bg' => '#ddf4ff', 'bd' => '#54aeff', 'fg' => '#0a3069'],
                    'danger'  => ['bg' => '#ffebe9', 'bd' => '#ff7b72', 'fg' => '#a40e26'],
                    'success' => ['bg' => '#dafbe1', 'bd' => '#4ac26b', 'fg' => '#0f5323'],
                ][$tone];
                return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;">'
                    . '<tr><td class="nomen-alert--' . $tone . '" style="background:' . $palette['bg'] . ';border-left:4px solid '
                    . $palette['bd'] . ';border-radius:0 10px 10px 0;padding:14px 18px;font-size:14px;line-height:1.75;'
                    . 'color:' . $palette['fg'] . ';">' . $e($b['text'] ?? '') . '</td></tr></table>';

            case 'raw':
                return (string)($b['html'] ?? '');

            default:
                return '';
        }
    }

    /**
     * 站内消息 → 邮件：与消息中心同一封内容，换成绝对链接
     */
    public static function notification(string $category, string $title, string $body, ?string $link = null, ?string $locale = null): string
    {
        $locale = $locale ?? I18n::locale();
        $badges = [
            NotificationHelper::TYPE_SYSTEM_IMPORTANT => ['text' => I18n::tIn($locale, 'Important system notice'), 'tone' => 'important'],
            NotificationHelper::TYPE_SYSTEM_GENERAL   => ['text' => I18n::tIn($locale, 'General system notice'), 'tone' => 'general'],
            NotificationHelper::TYPE_SYSTEM_INTERACT  => ['text' => I18n::tIn($locale, 'System interaction'), 'tone' => 'interact'],
        ];

        $blocks = [];
        foreach (preg_split('/\n{2,}/', trim($body)) as $para) {
            $para = trim($para);
            if ($para === '') continue;

            $lines = preg_split('/\n/', $para);
            // 整段都是「1. …」这种编号行时才收成有序列表，其余段落保留原始换行
            $allNumbered = count($lines) > 1;
            foreach ($lines as $line) {
                if (!preg_match('/^\s*\d+[.、]\s*/u', $line)) { $allNumbered = false; break; }
            }

            if ($allNumbered) {
                $items = array_map(fn($l) => preg_replace('/^\s*\d+[.、]\s*/u', '', trim($l)), $lines);
                $blocks[] = ['type' => 'list', 'ordered' => true, 'items' => $items];
            } else {
                $blocks[] = ['type' => 'paragraph', 'text' => $para];
            }
        }

        $url = $link ? (str_starts_with($link, 'http') ? $link : self::url($link)) : self::url('/notifications');

        return self::render([
            'preheader'  => $title,
            'badge'      => $badges[$category] ?? null,
            'title'      => $title,
            'blocks'     => $blocks,
            'button'     => ['text' => I18n::tIn($locale, 'Open in Nomen'), 'url' => $url],
            'prefs_url'  => self::url('/settings#sec-notifications'),
        ], $locale);
    }
}

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
 * 站点品牌信息：名称、地址、图标、页脚。布局和模板都从这里取，别写死。
 */
final class Brand
{
    private static ?array $data = null;

    public static function all(): array
    {
        if (self::$data === null) {
            self::$data = require dirname(__DIR__) . '/config/brand.php';
        }

        return self::$data;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    /** 页脚版权行：支持 [文字](链接)，其余内容按纯文本转义。 */
    public static function copyrightHtml(string $text): string
    {
        $out = '';
        $offset = 0;

        preg_match_all('/\[([^\]]+)\]\(([^)\s]+)\)/u', $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $i => [$whole, $start]) {
            $out .= htmlspecialchars(substr($text, $offset, $start - $offset), ENT_QUOTES);
            $out .= '<a href="' . htmlspecialchars($matches[2][$i][0], ENT_QUOTES) . '">'
                . htmlspecialchars($matches[1][$i][0], ENT_QUOTES) . '</a>';
            $offset = $start + strlen($whole);
        }

        return $out . htmlspecialchars(substr($text, $offset), ENT_QUOTES);
    }

    /** @return array<int, array{label: string, url: string}> */
    public static function footerLinks(): array
    {
        $links = (array)self::get('footer_links', []);

        $docs = Plugins::mountUrl('pages');
        if ($docs !== null) {
            $links[] = ['label' => 'Docs', 'url' => $docs];
        }

        return $links;
    }

    /**
     * 站点政策：后台设置优先，没改过就用 config 里的默认值，最多三条。
     *
     * @return array<int, array{role: string, label: string, url: string}>
     */
    public static function policies(): array
    {
        $out = self::configuredPolicies();
        if ($out !== []) {
            return $out;
        }

        // 老配置兼容：legal_links 不限条数，原样保留。
        $legacy = [];
        foreach ((array)self::get('legal_links', []) as $link) {
            if (!is_array($link)) {
                continue;
            }
            $label = trim((string)($link['label'] ?? ''));
            $url = trim((string)($link['url'] ?? ''));
            if ($label !== '' && $url !== '') {
                $legacy[] = ['role' => 'other', 'label' => $label, 'url' => $url];
            }
        }
        if ($legacy !== []) {
            return $legacy;
        }

        $fallback = [
            ['role' => 'privacy', 'url' => trim((string)self::get('privacy_url', '')), 'label' => 'Privacy Policy'],
            ['role' => 'terms', 'url' => trim((string)self::get('terms_url', '')), 'label' => 'Terms of Service'],
            ['role' => 'other', 'url' => trim((string)self::get('developer_guidelines_url', '')), 'label' => 'Developer Guidelines'],
        ];
        foreach ($fallback as $link) {
            if ($link['url'] !== '') {
                $out[] = $link;
            }
        }

        return $out;
    }

    /**
     * 后台设置或 config 里明确配置的政策（不含老配置回退），最多三条。
     *
     * @return array<int, array{role: string, label: string, url: string}>
     */
    public static function configuredPolicies(): array
    {
        $list = self::listSetting('brand.policies') ?? (array)self::get('policies', []);
        $out = [];

        foreach ($list as $i => $policy) {
            if (!is_array($policy)) {
                continue;
            }
            $url = trim((string)($policy['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $label = trim((string)($policy['label'] ?? ''));
            $role = self::policyRole((string)($policy['role'] ?? ''), $label, $i);
            if ($label === '') {
                $label = match ($role) {
                    'privacy' => 'Privacy Policy',
                    'terms'   => 'Terms of Service',
                    default   => '',
                };
            }
            if ($label === '') {
                continue;
            }
            $out[] = ['role' => $role, 'label' => $label, 'url' => $url];
            if (count($out) === 3) {
                break;
            }
        }

        return $out;
    }

    /** 政策里用作注册同意的两条：role=privacy / role=terms。 */
    public static function privacyUrl(): string
    {
        foreach (self::policies() as $policy) {
            if ($policy['role'] === 'privacy') {
                return $policy['url'];
            }
        }

        return trim((string)self::get('privacy_url', ''));
    }

    public static function termsUrl(): string
    {
        foreach (self::policies() as $policy) {
            if ($policy['role'] === 'terms') {
                return $policy['url'];
            }
        }

        return trim((string)self::get('terms_url', ''));
    }

    /** 附加条款：不限条数，单独一组展示。 */
    public static function additionalTerms(): array
    {
        $list = self::listSetting('brand.additional_terms') ?? (array)self::get('additional_terms', []);
        $out = [];

        foreach ($list as $link) {
            if (!is_array($link)) {
                continue;
            }
            $label = trim((string)($link['label'] ?? ''));
            $url = trim((string)($link['url'] ?? ''));
            if ($label !== '' && $url !== '') {
                $out[] = ['label' => $label, 'url' => $url];
            }
        }

        return $out;
    }

    /** 认证页「登录即代表同意」的政策链接；附加条款单独渲染。 */
    public static function legalLinks(): array
    {
        $out = [];
        foreach (self::policies() as $policy) {
            $out[] = ['label' => $policy['label'], 'url' => $policy['url']];
        }

        return $out;
    }

    /** 后台存的 JSON 列表；没存过（或存的是空列表）返回 null，交给 config 默认值。 */
    private static function listSetting(string $key): ?array
    {
        $raw = Settings::get($key, null);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) && $data !== [] ? $data : null;
    }

    private static function policyRole(string $role, string $label, int $index): string
    {
        if (in_array($role, ['privacy', 'terms', 'other'], true)) {
            return $role;
        }

        $lower = strtolower($label);
        if ($lower === 'privacy policy' || str_contains($lower, 'privacy')) {
            return 'privacy';
        }
        if ($lower === 'terms of service' || str_contains($lower, 'terms')) {
            return 'terms';
        }

        return $index === 0 ? 'privacy' : ($index === 1 ? 'terms' : 'other');
    }
}

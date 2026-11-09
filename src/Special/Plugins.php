<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

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

        $html = '<table class="table"><tr><th>' . t('Plugins') . '</th><th>' . t('Version') . '</th><th>' . t('Status') . '</th></tr>';
        foreach ($all as $slug => $manifest) {
            if (isset($loaded[$slug])) {
                $status = t('Running');
            } elseif (in_array($slug, $enabled, true)) {
                $status = t('Failed to enable (dependencies missing — see error_log)');
            } else {
                $status = t('Not enabled');
            }
            $html .= '<tr><td>' . htmlspecialchars((string)$manifest['name'])
                . '<br><span class="form-hint">' . htmlspecialchars(t((string)($manifest['description'] ?? ''))) . '</span></td>'
                . '<td>' . htmlspecialchars((string)($manifest['version'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars($status) . '</td></tr>';
        }
        $html .= '</table>';

        $html .= '<p class="form-hint">' . t('To enable a plugin, add its directory name to <code>enabled</code> in <code>config/plugins.php</code>. GeoIP additionally needs <code>composer require geoip2/geoip2</code> and its data file.') . '</p>';

        $caps = [
            ['gd', t('CAPTCHA images for sign-in, thumbnails for appeal screenshots'), Captcha::available()],
            ['fileinfo', t('Avatar and app icon uploads'), function_exists('finfo_open')],
            ['redis', t('Rate limiting, signing out other devices (degrades gracefully without it)'), extension_loaded('redis')],
        ];

        $html .= '<h2>' . t('PHP extensions') . '</h2><table class="table"><tr><th>' . t('Extension') . '</th><th>' . t('Used for') . '</th><th>' . t('Status') . '</th></tr>';
        foreach ($caps as [$ext, $what, $ok]) {
            $html .= '<tr><td>' . htmlspecialchars($ext) . '</td><td>' . htmlspecialchars($what) . '</td>'
                . '<td>' . ($ok ? t('Available') : t('Missing')) . '</td></tr>';
        }
        $html .= '</table>';

        return $html;
    }
}

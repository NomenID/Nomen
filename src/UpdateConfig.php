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
 * 读 config/autoupdate.php，缺省值写在这里。文件不在也能跑（默认关闭）。
 * 少数几个键允许用环境变量覆盖，方便容器 / 面板部署。
 */
final class UpdateConfig
{
    private static ?array $data = null;

    private const DEFAULTS = [
        'feed'           => '',
        'download_base'  => 'https://dl.caellab.com/file/nomen/',
        'min_php'        => '8.1',
        'scope'          => 'patch',
        'interval'       => 43200,
        'check_at'       => '',
        'window'         => '',
        'min_priority'   => 'normal',
        'notify_email'   => '',
        'backup'         => [
            'database' => true,
            'timeout'  => 300,
            'uploads'  => true,
            'max_mb'   => 512,
            'keep'     => 5,
            'extra'    => [],
            'exclude'  => [],
        ],
        'protect'         => [],
        'max_package_mb'  => 200,
        'min_free_mb'     => 100,
        'maintenance_ttl' => 900,
        'lock_ttl'        => 900,
    ];

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $file = dirname(__DIR__) . '/config/autoupdate.php';
        $loaded = is_file($file) ? require $file : [];
        if (!is_array($loaded)) {
            $loaded = [];
        }

        $backup = array_merge(
            self::DEFAULTS['backup'],
            is_array($loaded['backup'] ?? null) ? $loaded['backup'] : []
        );
        $config = array_merge(self::DEFAULTS, $loaded);
        $config['backup'] = $backup;

        foreach ([
            'feed'          => 'NOMEN_UPDATE_FEED',
            'download_base' => 'NOMEN_UPDATE_DOWNLOAD_BASE',
            'scope'         => 'NOMEN_UPDATE_SCOPE',
        ] as $key => $env) {
            $value = getenv($env);
            if ($value !== false && $value !== '') {
                $config[$key] = $value;
            }
        }

        return self::$data = $config;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $data = self::all();
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        $cursor = $data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$part];
        }

        return $cursor;
    }
}

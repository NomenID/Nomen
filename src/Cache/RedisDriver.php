<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Cache;

use Redis;

class RedisDriver implements Driver, Flushable
{
    private Redis $client;
    private string $prefix;

    public function __construct(Redis $client, string $prefix = 'nomen:cache:')
    {
        $this->client = $client;
        $this->prefix = $prefix;
    }

    public function get(string $key): mixed
    {
        $raw = $this->client->get($this->prefix . $key);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $payload = @unserialize($raw, ['allowed_classes' => false]);

        return is_array($payload) ? ($payload['value'] ?? null) : null;
    }

    public function put(string $key, mixed $value, int $ttl): void
    {
        $this->client->setex($this->prefix . $key, max(1, $ttl), serialize(['value' => $value]));
    }

    public function forget(string $key): void
    {
        $this->client->del($this->prefix . $key);
    }

    /**
     * 只删本站前缀下的键。Redis 的淘汰器不认识前缀，所以这件事必须由我们自己做——
     * 别用 FLUSHDB：同一个库里可能还有别的程序。
     */
    public function flush(): int
    {
        $iterator = null;
        $deleted = 0;

        while (true) {
            $keys = $this->client->scan($iterator, $this->prefix . '*', 500);
            if ($keys === false) {
                break;
            }
            if ($keys !== []) {
                $deleted += (int)$this->client->unlink($keys);
            }
            if ((int)$iterator === 0) {
                break;
            }
        }

        return $deleted;
    }
}

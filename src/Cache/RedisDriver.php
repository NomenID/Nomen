<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Cache;

use Redis;

class RedisDriver implements Driver
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
}

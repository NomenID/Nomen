<?php
namespace Nomen\Src\Cache;

interface Driver
{
    /** 命中返回值，未命中返回 null */
    public function get(string $key): mixed;

    public function put(string $key, mixed $value, int $ttl): void;

    public function forget(string $key): void;
}

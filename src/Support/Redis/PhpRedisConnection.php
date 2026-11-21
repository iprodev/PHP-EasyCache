<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Support\Redis;

use Redis;

/**
 * @internal
 */
final class PhpRedisConnection extends RedisConnection
{
    public function __construct(private readonly Redis $redis)
    {
    }

    public function get(string $key): ?string
    {
        $value = $this->redis->get($key);
        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, int $ttl): bool
    {
        $result = $ttl > 0 ? $this->redis->setex($key, $ttl, $value) : $this->redis->set($key, $value);
        return $result === true;
    }

    public function setIfAbsent(string $key, string $value, int $ttlMs): bool
    {
        return $this->redis->set($key, $value, ['nx', 'px' => $ttlMs]) === true;
    }

    public function delete(array $keys): void
    {
        if ($keys !== []) {
            $this->redis->del($keys);
        }
    }

    public function exists(string $key): bool
    {
        $result = $this->redis->exists($key);
        return is_int($result) ? $result > 0 : $result === true;
    }

    public function getMany(array $keys): array
    {
        $values = $this->redis->mget($keys);
        $result = [];
        foreach (array_keys($keys) as $i) {
            $value = is_array($values) ? ($values[$i] ?? null) : null;
            $result[] = is_string($value) ? $value : null;
        }
        return $result;
    }

    public function setMany(array $values, int $ttl): bool
    {
        $pipe = $this->redis->multi(Redis::PIPELINE);
        if (!$pipe instanceof Redis) {
            return false;
        }
        foreach ($values as $key => $value) {
            if ($ttl > 0) {
                $pipe->setex((string) $key, $ttl, $value);
            } else {
                $pipe->set((string) $key, $value);
            }
        }
        $results = $pipe->exec();
        if (!is_array($results)) {
            return false;
        }
        foreach ($results as $result) {
            if ($result !== true) {
                return false;
            }
        }
        return true;
    }

    public function deleteByPrefix(string $prefix, int $batchSize): void
    {
        // A client-level prefix (Redis::OPT_PREFIX) is added to DEL arguments
        // automatically but not to SCAN patterns (unless SCAN_PREFIX is set).
        $clientPrefix = $this->redis->getOption(Redis::OPT_PREFIX);
        $clientPrefix = is_string($clientPrefix) ? $clientPrefix : '';
        $scanOption = $this->redis->getOption(Redis::OPT_SCAN);
        $scanAddsPrefix = defined('Redis::SCAN_PREFIX')
            && is_int($scanOption)
            && ($scanOption & Redis::SCAN_PREFIX) !== 0;

        $pattern = self::escapeGlob(($scanAddsPrefix ? '' : $clientPrefix) . $prefix) . '*';

        $iterator = null;
        while (($keys = $this->redis->scan($iterator, $pattern, $batchSize)) !== false) {
            $this->delete(self::stripPrefix($keys, $clientPrefix));
        }
    }

    public function deleteIfEquals(string $key, string $value): void
    {
        $this->redis->eval(self::DELETE_IF_EQUALS_SCRIPT, [$key, $value], 1);
    }
}

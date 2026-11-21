<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Lock;

use InvalidArgumentException;
use Iprodev\EasyCache\Support\RedisClient;

/**
 * Distributed locks stored in Redis, for stampede protection across servers.
 */
final class RedisLockProvider implements LockProviderInterface
{
    private object $redis;
    private string $prefix;
    private int $ttlMs;

    /**
     * @param object $redis  A \Redis (phpredis) or \Predis\ClientInterface instance
     * @param string $prefix Key prefix for lock keys
     * @param int    $ttlMs  Lock expiry in milliseconds (protects against crashed holders)
     */
    public function __construct(object $redis, string $prefix = 'ec:lock:', int $ttlMs = 30000)
    {
        RedisClient::assertSupported($redis);
        if ($prefix === '') {
            throw new InvalidArgumentException('Redis lock prefix must not be empty');
        }
        if ($ttlMs < 1) {
            throw new InvalidArgumentException('Redis lock TTL must be positive');
        }

        $this->redis = $redis;
        $this->prefix = $prefix;
        $this->ttlMs = $ttlMs;
    }

    public function create(string $key): LockInterface
    {
        return new RedisLock($this->redis, $this->prefix . $key, $this->ttlMs);
    }
}

<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Lock;

use InvalidArgumentException;
use Iprodev\EasyCache\Support\Redis\RedisConnection;

/**
 * Distributed locks stored in Redis, for stampede protection across servers.
 */
final readonly class RedisLockProvider implements LockProviderInterface
{
    private RedisConnection $redis;

    /**
     * @param object $redis  A \Redis (phpredis) or \Predis\ClientInterface instance
     * @param string $prefix Key prefix for lock keys
     * @param int    $ttlMs  Lock expiry in milliseconds (protects against crashed holders)
     */
    public function __construct(object $redis, private string $prefix = 'ec:lock:', private int $ttlMs = 30000)
    {
        if ($prefix === '') {
            throw new InvalidArgumentException('Redis lock prefix must not be empty');
        }
        if ($ttlMs < 1) {
            throw new InvalidArgumentException('Redis lock TTL must be positive');
        }

        $this->redis = RedisConnection::wrap($redis);
    }

    public function create(string $key): LockInterface
    {
        return new RedisLock($this->redis, $this->prefix . $key, $this->ttlMs);
    }
}

<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Lock;

use Iprodev\EasyCache\Support\Redis\RedisConnection;
use Throwable;

/**
 * A lock held in Redis with SET NX PX and released with a compare-and-delete
 * Lua script, so a holder can never release somebody else's lock.
 */
final class RedisLock implements LockInterface
{
    private ?string $token = null;
    private readonly RedisConnection $redis;

    /**
     * @param object $redis A \Redis (phpredis) or \Predis\ClientInterface instance
     */
    public function __construct(object $redis, private readonly string $key, private readonly int $ttlMs)
    {
        $this->redis = $redis instanceof RedisConnection ? $redis : RedisConnection::wrap($redis);
    }

    public function acquire(bool $blocking = true, ?float $timeout = null): bool
    {
        if ($this->token !== null) {
            return true;
        }

        // Waiting longer than the lock TTL is pointless: the lock expires by then.
        $deadline = microtime(true) + max(0.0, $timeout ?? $this->ttlMs / 1000);
        $sleep = 5000;

        while (true) {
            $token = bin2hex(random_bytes(16));
            try {
                if ($this->redis->setIfAbsent($this->key, $token, $this->ttlMs)) {
                    $this->token = $token;
                    return true;
                }
            } catch (Throwable) {
                return false;
            }
            if (!$blocking || microtime(true) >= $deadline) {
                return false;
            }
            usleep($sleep);
            $sleep = min($sleep * 2, 100000);
        }
    }

    public function release(): void
    {
        if ($this->token === null) {
            return;
        }

        $token = $this->token;
        $this->token = null;

        try {
            $this->redis->deleteIfEquals($this->key, $token);
        } catch (Throwable) {
            // The lock expires on its own after its TTL.
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}

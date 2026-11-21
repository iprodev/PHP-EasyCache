<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Lock;

use Iprodev\EasyCache\Support\RedisClient;
use Throwable;

/**
 * A lock held in Redis with SET NX PX and released with a compare-and-delete
 * Lua script, so a holder can never release somebody else's lock.
 */
final class RedisLock implements LockInterface
{
    private const RELEASE_SCRIPT = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
end
return 0
LUA;

    private object $redis;
    private string $key;
    private int $ttlMs;
    private ?string $token = null;

    public function __construct(object $redis, string $key, int $ttlMs)
    {
        RedisClient::assertSupported($redis);
        $this->redis = $redis;
        $this->key = $key;
        $this->ttlMs = $ttlMs;
    }

    public function acquire(bool $blocking = true, ?float $timeout = null): bool
    {
        if ($this->token !== null) {
            return true;
        }

        // Waiting longer than the lock TTL is pointless: the lock expires by then.
        $timeout = $timeout ?? ($this->ttlMs / 1000);
        $deadline = microtime(true) + max(0.0, $timeout);
        $sleep = 5000;

        do {
            $token = bin2hex(random_bytes(16));
            if ($this->trySet($token)) {
                $this->token = $token;
                return true;
            }
            if (!$blocking || microtime(true) >= $deadline) {
                return false;
            }
            usleep($sleep);
            $sleep = min($sleep * 2, 100000);
        } while (true);
    }

    public function release(): void
    {
        if ($this->token === null) {
            return;
        }

        $token = $this->token;
        $this->token = null;

        try {
            if (RedisClient::isPhpRedis($this->redis)) {
                /** @var \Redis $redis */
                $redis = $this->redis;
                $redis->eval(self::RELEASE_SCRIPT, [$this->key, $token], 1);
            } else {
                /** @phpstan-ignore-next-line Predis magic method */
                $this->redis->eval(self::RELEASE_SCRIPT, 1, $this->key, $token);
            }
        } catch (Throwable) {
            // The lock expires on its own after its TTL.
        }
    }

    public function __destruct()
    {
        $this->release();
    }

    private function trySet(string $token): bool
    {
        try {
            if (RedisClient::isPhpRedis($this->redis)) {
                /** @var \Redis $redis */
                $redis = $this->redis;
                return (bool) $redis->set($this->key, $token, ['nx', 'px' => $this->ttlMs]);
            }

            /** @phpstan-ignore-next-line Predis magic method */
            $result = $this->redis->set($this->key, $token, 'PX', $this->ttlMs, 'NX');

            return $result !== null && (string) $result === 'OK';
        } catch (Throwable) {
            return false;
        }
    }
}

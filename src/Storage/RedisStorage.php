<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Storage;

final class RedisStorage implements StorageInterface
{
    /** @var \Redis|\Predis\ClientInterface */
    private $redis;
    private string $prefix;

    /**
     * @param \Redis|\Predis\ClientInterface $redisClient
     * @param string $prefix Key prefix for namespacing
     */
    public function __construct($redisClient, string $prefix = 'ec:')
    {
        if (
            !($redisClient instanceof \Redis) &&
            !interface_exists('Predis\ClientInterface') ||
            !($redisClient instanceof \Predis\ClientInterface)
        ) {
            throw new \InvalidArgumentException(
                'Redis client must be instance of \Redis or \Predis\ClientInterface'
            );
        }

        $this->redis = $redisClient;
        $this->prefix = $prefix;
    }

    private function k(string $key): string
    {
        return $this->prefix . $key;
    }

    public function get(string $key): ?string
    {
        try {
            $val = $this->redis->get($this->k($key));
            return is_string($val) ? $val : null;
        } catch (\Throwable $e) {
            error_log("Redis get failed for key {$key}: " . $e->getMessage());
            return null;
        }
    }

    public function set(string $key, string $payload, int $ttl): bool
    {
        try {
            $k = $this->k($key);

            if ($ttl > 0) {
                return (bool) $this->redis->setex($k, $ttl, $payload);
            }

            return (bool) $this->redis->set($k, $payload);
        } catch (\Throwable $e) {
            error_log("Redis set failed for key {$key}: " . $e->getMessage());
            return false;
        }
    }

    public function delete(string $key): bool
    {
        try {
            return (bool) $this->redis->del($this->k($key));
        } catch (\Throwable $e) {
            error_log("Redis delete failed for key {$key}: " . $e->getMessage());
            return false;
        }
    }

    public function has(string $key): bool
    {
        try {
            return (bool) $this->redis->exists($this->k($key));
        } catch (\Throwable $e) {
            error_log("Redis has failed for key {$key}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear only keys with the configured prefix.
     * This is safe for shared Redis databases.
     */
    public function clear(): bool
    {
        try {
            $deleted = 0;
            $pattern = $this->prefix . '*';

            // Use SCAN for phpredis or keys() for predis
            if ($this->redis instanceof \Redis) {
                if (method_exists($this->redis, 'scan')) {
                    $it = null;
                    while (true) {
                        $keys = $this->redis->scan($it, $pattern, 1000);
                        if ($keys === false) {
                            break;
                        }
                        if (!empty($keys)) {
                            $this->redis->del($keys);
                            $deleted += count($keys);
                        }
                    }
                } else {
                    // Fallback for older Redis versions
                    $keys = $this->redis->keys($pattern);
                    if (!empty($keys)) {
                        $this->redis->del($keys);
                        $deleted += count($keys);
                    }
                }
            } else {
                // Predis
                $keys = $this->redis->keys($pattern);
                if (!empty($keys)) {
                    $this->redis->del($keys);
                    $deleted += count($keys);
                }
            }

            return true;
        } catch (\Throwable $e) {
            error_log("Redis clear failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Redis handles expiration automatically via TTL.
     */
    public function prune(): int
    {
        return 0;
    }
}

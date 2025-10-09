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
     */
    public function __construct($redisClient, string $prefix = 'ec:')
    {
        $this->redis = $redisClient;
        $this->prefix = $prefix;
    }

    private function k(string $key): string { return $this->prefix . $key; }

    public function get(string $key): ?string
    {
        $val = $this->redis->get($this->k($key));
        return is_string($val) ? $val : null;
    }

    public function set(string $key, string $payload, int $ttl): bool
    {
        $k = $this->k($key);
        if ($ttl > 0) {
            return (bool) $this->redis->setex($k, $ttl, $payload);
        }
        return (bool) $this->redis->set($k, $payload);
    }

    public function delete(string $key): bool
    {
        return (bool) $this->redis->del($this->k($key));
    }

    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($this->k($key));
    }

    public function clear(): bool
    {
        // delete by prefix
        $deleted = 0;
        if (method_exists($this->redis, 'scan')) {
            $it = NULL;
            while (true) {
                $keys = $this->redis->scan($it, $this->prefix.'*', 1000);
                if ($keys === false) break;
                if (!empty($keys)) { $this->redis->del($keys); $deleted += count($keys); }
            }
        } else {
            foreach ($this->redis->keys($this->prefix.'*') as $k) {
                $this->redis->del($k); $deleted++;
            }
        }
        return $deleted >= 0;
    }

    public function prune(): int { return 0; }
}

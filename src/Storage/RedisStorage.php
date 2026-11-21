<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Storage;

use InvalidArgumentException;
use Iprodev\EasyCache\Support\LogsErrors;
use Iprodev\EasyCache\Support\Redis\RedisConnection;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Redis storage for phpredis (\Redis) and Predis clients.
 */
final class RedisStorage implements StorageInterface, LoggerAwareInterface
{
    use LogsErrors;

    private const int BATCH_SIZE = 1000;

    private RedisConnection $redis;
    private string $prefix;

    /**
     * @param object $redisClient A \Redis (phpredis) or \Predis\ClientInterface instance
     * @param string $prefix      Key prefix for namespacing (required, clear() only removes keys with it)
     */
    public function __construct(object $redisClient, string $prefix = 'ec:', ?LoggerInterface $logger = null)
    {
        if ($prefix === '') {
            throw new InvalidArgumentException(
                'RedisStorage requires a non-empty prefix: clear() deletes every key that starts with it'
            );
        }

        $this->redis = RedisConnection::wrap($redisClient);
        $this->prefix = $prefix;
        $this->logger = $logger;
    }

    public function get(string $key): ?string
    {
        try {
            return $this->redis->get($this->k($key));
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: Redis get failed for key {key}', ['key' => $key], $e);
            return null;
        }
    }

    public function set(string $key, string $payload, int $ttl): bool
    {
        try {
            return $this->redis->set($this->k($key), $payload, max(0, $ttl));
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: Redis set failed for key {key}', ['key' => $key], $e);
            return false;
        }
    }

    public function delete(string $key): bool
    {
        try {
            $this->redis->delete([$this->k($key)]);
            return true;
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: Redis delete failed for key {key}', ['key' => $key], $e);
            return false;
        }
    }

    public function has(string $key): bool
    {
        try {
            return $this->redis->exists($this->k($key));
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: Redis has failed for key {key}', ['key' => $key], $e);
            return false;
        }
    }

    public function getMany(array $keys): array
    {
        $result = [];
        try {
            foreach (array_chunk($keys, self::BATCH_SIZE) as $chunk) {
                $values = $this->redis->getMany(array_map($this->k(...), $chunk));
                foreach ($chunk as $i => $key) {
                    if (isset($values[$i])) {
                        $result[$key] = $values[$i];
                    }
                }
            }
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: Redis getMany failed', ['count' => count($keys)], $e);
        }

        return $result;
    }

    public function setMany(array $payloads, int $ttl): bool
    {
        try {
            $ok = true;
            foreach (array_chunk($payloads, self::BATCH_SIZE, true) as $chunk) {
                $prefixed = [];
                foreach ($chunk as $key => $payload) {
                    $prefixed[$this->k((string) $key)] = $payload;
                }
                $ok = $this->redis->setMany($prefixed, max(0, $ttl)) && $ok;
            }
            return $ok;
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: Redis setMany failed', ['count' => count($payloads)], $e);
            return false;
        }
    }

    public function deleteMany(array $keys): bool
    {
        try {
            foreach (array_chunk($keys, self::BATCH_SIZE) as $chunk) {
                $this->redis->delete(array_map($this->k(...), $chunk));
            }
            return true;
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: Redis deleteMany failed', ['count' => count($keys)], $e);
            return false;
        }
    }

    /**
     * Delete only keys with the configured prefix, using non-blocking SCAN
     * (never KEYS). Safe for shared Redis databases.
     */
    public function clear(): bool
    {
        try {
            $this->redis->deleteByPrefix($this->prefix, self::BATCH_SIZE);
            return true;
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: Redis clear failed', [], $e);
            return false;
        }
    }

    private function k(string $key): string
    {
        return $this->prefix . $key;
    }
}

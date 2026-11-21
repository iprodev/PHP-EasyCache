<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Storage;

use InvalidArgumentException;
use Iprodev\EasyCache\Support\LogsErrors;
use Iprodev\EasyCache\Support\RedisClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Redis storage for phpredis (\Redis) and Predis clients.
 */
final class RedisStorage implements BatchStorageInterface, LoggerAwareInterface
{
    use LogsErrors;

    private const BATCH_SIZE = 1000;

    private object $redis;
    private bool $phpredis;
    private string $prefix;

    /**
     * @param object $redisClient A \Redis (phpredis) or \Predis\ClientInterface instance
     * @param string $prefix Key prefix for namespacing (required, clear() only removes keys with it)
     */
    public function __construct(object $redisClient, string $prefix = 'ec:', ?LoggerInterface $logger = null)
    {
        RedisClient::assertSupported($redisClient);

        if ($prefix === '') {
            throw new InvalidArgumentException(
                'RedisStorage requires a non-empty prefix: clear() deletes every key that starts with it'
            );
        }

        $this->redis = $redisClient;
        $this->phpredis = RedisClient::isPhpRedis($redisClient);
        $this->prefix = $prefix;
        $this->logger = $logger;
    }

    public function get(string $key): ?string
    {
        try {
            $value = $this->client()->get($this->k($key));
            return is_string($value) ? $value : null;
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: Redis get failed for key {key}', ['key' => $key], $e);
            return null;
        }
    }

    public function set(string $key, string $payload, int $ttl): bool
    {
        try {
            $k = $this->k($key);
            $result = $ttl > 0
                ? $this->client()->setex($k, $ttl, $payload)
                : $this->client()->set($k, $payload);

            return $this->isOk($result);
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: Redis set failed for key {key}', ['key' => $key], $e);
            return false;
        }
    }

    public function delete(string $key): bool
    {
        try {
            $this->client()->del($this->k($key));
            return true;
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: Redis delete failed for key {key}', ['key' => $key], $e);
            return false;
        }
    }

    public function has(string $key): bool
    {
        try {
            return (bool) $this->client()->exists($this->k($key));
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: Redis has failed for key {key}', ['key' => $key], $e);
            return false;
        }
    }

    public function getMany(array $keys): array
    {
        $result = [];
        if ($keys === []) {
            return $result;
        }

        try {
            foreach (array_chunk($keys, self::BATCH_SIZE) as $chunk) {
                $values = $this->client()->mget(array_map([$this, 'k'], $chunk));
                if (!is_array($values)) {
                    continue;
                }
                foreach (array_values($chunk) as $i => $key) {
                    $value = $values[$i] ?? null;
                    if (is_string($value)) {
                        $result[$key] = $value;
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
        if ($payloads === []) {
            return true;
        }

        try {
            $ok = true;
            foreach (array_chunk($payloads, self::BATCH_SIZE, true) as $chunk) {
                $results = $this->pipeline(function (mixed $pipe) use ($chunk, $ttl): void {
                    foreach ($chunk as $key => $payload) {
                        $k = $this->k((string) $key);
                        if ($ttl > 0) {
                            $pipe->setex($k, $ttl, $payload);
                        } else {
                            $pipe->set($k, $payload);
                        }
                    }
                });

                foreach ($results as $result) {
                    $ok = $this->isOk($result) && $ok;
                }
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
                $this->client()->del(array_map([$this, 'k'], $chunk));
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
            return $this->phpredis ? $this->clearPhpRedis() : $this->clearPredis();
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: Redis clear failed', [], $e);
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

    private function clearPhpRedis(): bool
    {
        /** @var \Redis $redis */
        $redis = $this->redis;

        // A client-level prefix (Redis::OPT_PREFIX) is added to DEL arguments
        // automatically but not to SCAN patterns (unless SCAN_PREFIX is set).
        $clientPrefix = (string) $redis->getOption(\Redis::OPT_PREFIX);
        $scanAddsPrefix = defined('Redis::SCAN_PREFIX')
            && (((int) $redis->getOption(\Redis::OPT_SCAN)) & \Redis::SCAN_PREFIX) !== 0;

        $pattern = RedisClient::escapeGlob(($scanAddsPrefix ? '' : $clientPrefix) . $this->prefix) . '*';

        $iterator = null;
        while (($keys = $redis->scan($iterator, $pattern, self::BATCH_SIZE)) !== false) {
            if ($keys !== []) {
                $redis->del($this->stripPrefix($keys, $clientPrefix));
            }
        }

        return true;
    }

    private function clearPredis(): bool
    {
        $client = $this->client();
        $clientPrefix = $this->predisPrefix();

        // A client-level prefix (the "prefix" option) is added to DEL arguments
        // by Predis but not to the SCAN MATCH pattern, and SCAN returns full names.
        $pattern = RedisClient::escapeGlob($clientPrefix . $this->prefix) . '*';
        $cursor = '0';

        do {
            $response = $client->scan($cursor, ['MATCH' => $pattern, 'COUNT' => self::BATCH_SIZE]);
            if (!is_array($response) || count($response) < 2) {
                return false;
            }
            [$cursor, $keys] = $response;
            if (is_array($keys) && $keys !== []) {
                $client->del($this->stripPrefix($keys, $clientPrefix));
            }
        } while ((string) $cursor !== '0');

        return true;
    }

    private function predisPrefix(): string
    {
        try {
            $prefix = $this->client()->getOptions()->prefix ?? null;
            if (is_object($prefix) && method_exists($prefix, 'getPrefix')) {
                return (string) $prefix->getPrefix();
            }
        } catch (Throwable) {
        }

        return '';
    }

    /**
     * @param array<int|string, mixed> $keys
     * @return list<string>
     */
    private function stripPrefix(array $keys, string $clientPrefix): array
    {
        $result = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            if ($clientPrefix !== '' && str_starts_with($key, $clientPrefix)) {
                $key = substr($key, strlen($clientPrefix));
            }
            $result[] = $key;
        }
        return $result;
    }

    /**
     * @param callable(mixed): void $commands
     * @return array<int, mixed>
     */
    private function pipeline(callable $commands): array
    {
        if ($this->phpredis) {
            /** @var \Redis $redis */
            $redis = $this->redis;
            $pipe = $redis->multi(\Redis::PIPELINE);
            $commands($pipe);
            $results = $pipe->exec();
            return is_array($results) ? $results : [false];
        }

        $results = $this->client()->pipeline(static function (mixed $pipe) use ($commands): void {
            $commands($pipe);
        });

        return is_array($results) ? $results : [false];
    }

    private function isOk(mixed $result): bool
    {
        if ($result === false || $result === null) {
            return false;
        }
        if (is_object($result)) {
            if (is_a($result, 'Predis\Response\ErrorInterface')) {
                return false;
            }
            return true;
        }

        return (bool) $result;
    }

    /**
     * The client typed loosely, so phpredis and Predis calls share one code path.
     */
    private function client(): mixed
    {
        return $this->redis;
    }

    private function k(string $key): string
    {
        return $this->prefix . $key;
    }
}

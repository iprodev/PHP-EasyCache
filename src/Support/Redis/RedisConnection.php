<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Support\Redis;

use InvalidArgumentException;

/**
 * The few Redis operations EasyCache needs, implemented for phpredis and Predis.
 *
 * @internal
 */
abstract class RedisConnection
{
    /**
     * Wrap a \Redis (phpredis) or \Predis\ClientInterface client.
     */
    public static function wrap(object $client): self
    {
        if (class_exists(\Redis::class, false) && $client instanceof \Redis) {
            return new PhpRedisConnection($client);
        }
        if (interface_exists(\Predis\ClientInterface::class) && $client instanceof \Predis\ClientInterface) {
            return new PredisConnection($client);
        }

        throw new InvalidArgumentException(
            'Redis client must be an instance of \Redis (phpredis) or \Predis\ClientInterface'
        );
    }

    abstract public function get(string $key): ?string;

    /**
     * Store a value; a TTL of 0 means no expiry.
     */
    abstract public function set(string $key, string $value, int $ttl): bool;

    /**
     * SET key value NX PX ttl.
     */
    abstract public function setIfAbsent(string $key, string $value, int $ttlMs): bool;

    /**
     * @param list<string> $keys
     */
    abstract public function delete(array $keys): void;

    abstract public function exists(string $key): bool;

    /**
     * @param list<string> $keys
     * @return list<string|null> Values in the order of $keys (null when missing)
     */
    abstract public function getMany(array $keys): array;

    /**
     * Store several values with one TTL in one round trip.
     *
     * @param array<string, string> $values
     */
    abstract public function setMany(array $values, int $ttl): bool;

    /**
     * Delete every key that starts with $prefix, with non-blocking SCAN.
     */
    abstract public function deleteByPrefix(string $prefix, int $batchSize): void;

    /**
     * Delete $key only if it holds $value (atomic, Lua).
     */
    abstract public function deleteIfEquals(string $key, string $value): void;

    protected const string DELETE_IF_EQUALS_SCRIPT = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
end
return 0
LUA;

    /**
     * Escape glob special characters so a literal string can be used in a
     * SCAN MATCH pattern.
     */
    protected static function escapeGlob(string $literal): string
    {
        return (string) preg_replace('/([*?\[\]\\\\^])/', '\\\\$1', $literal);
    }

    /**
     * @param array<array-key, mixed> $keys
     * @return list<string>
     */
    protected static function stripPrefix(array $keys, string $clientPrefix): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                continue;
            }
            if ($clientPrefix !== '' && str_starts_with($key, $clientPrefix)) {
                $key = substr($key, strlen($clientPrefix));
            }
            $result[] = $key;
        }
        return $result;
    }
}

<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Support;

use InvalidArgumentException;

/**
 * Helpers to work with both phpredis (\Redis) and Predis clients.
 *
 * @internal
 */
final class RedisClient
{
    public static function isPhpRedis(object $client): bool
    {
        return class_exists(\Redis::class, false) && $client instanceof \Redis;
    }

    public static function isPredis(object $client): bool
    {
        return interface_exists('Predis\ClientInterface') && is_a($client, 'Predis\ClientInterface');
    }

    public static function assertSupported(mixed $client): void
    {
        if (!is_object($client) || (!self::isPhpRedis($client) && !self::isPredis($client))) {
            throw new InvalidArgumentException(
                'Redis client must be an instance of \Redis (phpredis) or \Predis\ClientInterface'
            );
        }
    }

    /**
     * Escape glob special characters so a literal string can be used in a
     * SCAN MATCH pattern.
     */
    public static function escapeGlob(string $literal): string
    {
        return (string) preg_replace('/([*?\[\]\\\\^])/', '\\\\$1', $literal);
    }
}

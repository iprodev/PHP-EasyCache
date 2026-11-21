<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool set(string $key, mixed $value, null|int|\DateInterval $ttl = null)
 * @method static bool delete(string $key)
 * @method static bool clear()
 * @method static array<string, mixed> getMultiple(iterable<string> $keys, mixed $default = null)
 * @method static bool setMultiple(iterable<string, mixed> $values, null|int|\DateInterval $ttl = null)
 * @method static bool deleteMultiple(iterable<string> $keys)
 * @method static bool has(string $key)
 * @method static mixed getOrSetSWR(string $key, callable $producer, null|int|\DateInterval $ttl = null, int $swr = 0, int $sei = 0, array{mode?: string} $options = [])
 * @method static mixed remember(string $key, null|int|\DateInterval $ttl, callable $producer)
 * @method static bool forever(string $key, mixed $value)
 * @method static \Iprodev\EasyCache\Cache\TaggedCache tags(string|list<string> $tags)
 * @method static bool invalidateTags(string|list<string> $tags)
 * @method static int prune()
 * @method static array<string, int|float> getStats()
 * @method static void resetStats()
 *
 * @see \Iprodev\EasyCache\Cache\MultiTierCache
 */
final class EasyCache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'easycache';
    }
}

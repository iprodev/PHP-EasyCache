<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool set(string $key, mixed $value, null|int|\DateInterval $ttl = null)
 * @method static mixed getOrSetSWR(string $key, callable $producer, int|null $ttl = null)
 */
final class EasyCache extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'easycache';
    }
}

<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Cache;

/**
 * How getOrSetSWR() refreshes an entry inside its stale-while-revalidate window.
 */
enum RefreshMode: string
{
    /**
     * The process that wins the lock refreshes immediately and returns the
     * new value; other processes get the stale value without waiting.
     */
    case Sync = 'sync';

    /**
     * The stale value is returned and the refresh runs after the response has
     * been sent (PHP-FPM/LiteSpeed). Falls back to Sync elsewhere.
     */
    case Defer = 'defer';
}

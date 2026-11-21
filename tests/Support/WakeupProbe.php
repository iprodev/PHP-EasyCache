<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Support;

/**
 * Counts how often instances are unserialized, to detect object injection.
 */
final class WakeupProbe
{
    public static int $wakeups = 0;

    public function __wakeup(): void
    {
        self::$wakeups++;
    }
}

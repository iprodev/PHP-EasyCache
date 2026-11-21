<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * PSR-20 clock backed by the system time.
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}

<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * PSR-20 clock that only moves when told to. Intended for tests, so that
 * expiry, stale-while-revalidate and stale-if-error behaviour can be
 * exercised without calling sleep().
 */
final class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(?DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new DateTimeImmutable();
    }

    public static function at(int $timestamp): self
    {
        return new self((new DateTimeImmutable())->setTimestamp($timestamp));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->setTimestamp($this->now->getTimestamp() + $seconds);
    }
}

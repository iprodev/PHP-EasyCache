<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Lock;

/**
 * A lock that can never be acquired (e.g. the lock directory is unusable).
 *
 * @internal
 */
final class UnavailableLock implements LockInterface
{
    public function acquire(bool $blocking = true, ?float $timeout = null): bool
    {
        return false;
    }

    public function release(): void
    {
    }
}

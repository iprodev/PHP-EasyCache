<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Lock;

interface LockInterface
{
    /**
     * Acquire the lock.
     *
     * @param bool       $blocking Wait for the lock when it is held elsewhere.
     * @param float|null $timeout  Maximum seconds to wait when blocking (null = implementation default).
     */
    public function acquire(bool $blocking = true, ?float $timeout = null): bool;

    /**
     * Release the lock if it is held. Safe to call more than once.
     */
    public function release(): void;
}

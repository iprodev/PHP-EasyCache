<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Lock;

/**
 * Disables locking: every lock is acquired immediately. Use only when
 * stampede protection is not needed.
 */
final class NullLockProvider implements LockProviderInterface
{
    public function create(string $key): LockInterface
    {
        return new class () implements LockInterface {
            public function acquire(bool $blocking = true, ?float $timeout = null): bool
            {
                return true;
            }

            public function release(): void
            {
            }
        };
    }
}

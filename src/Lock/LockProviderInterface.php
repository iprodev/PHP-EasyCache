<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Lock;

interface LockProviderInterface
{
    /**
     * Create a (not yet acquired) lock for the given cache key.
     */
    public function create(string $key): LockInterface;
}

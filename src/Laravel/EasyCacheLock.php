<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Laravel;

use Illuminate\Cache\Lock;

/**
 * Laravel atomic lock (Cache::lock()) for the "easycache" store, acquired
 * with the store's atomic add().
 */
final class EasyCacheLock extends Lock
{
    /**
     * @param string      $name
     * @param int         $seconds 0 = until released
     * @param string|null $owner
     */
    public function __construct(private readonly EasyCacheStore $store, $name, $seconds, $owner = null)
    {
        parent::__construct($name, $seconds, $owner);
    }

    public function acquire(): bool
    {
        return $this->store->add($this->name, $this->owner, $this->seconds);
    }

    public function release(): bool
    {
        if ($this->isOwnedByCurrentProcess()) {
            return $this->store->forget($this->name);
        }

        return false;
    }

    public function forceRelease(): void
    {
        $this->store->forget($this->name);
    }

    protected function getCurrentOwner(): mixed
    {
        return $this->store->get($this->name);
    }
}

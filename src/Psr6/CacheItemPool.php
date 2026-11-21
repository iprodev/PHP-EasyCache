<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Psr6;

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Clock\SystemClock;
use Iprodev\EasyCache\Exceptions\InvalidArgument as CacheInvalidArgument;
use Iprodev\EasyCache\Util\KeyValidator;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * PSR-6 adapter over a MultiTierCache. Requires the psr/cache package.
 *
 *     $pool = new CacheItemPool($cache);
 *     $item = $pool->getItem('user-42');
 *     if (!$item->isHit()) {
 *         $pool->save($item->set($repo->find(42))->expiresAfter(600));
 *     }
 *
 * Deferred items are written with one batch per expiry when commit() is
 * called, and at the latest when the pool is destroyed.
 */
final class CacheItemPool implements CacheItemPoolInterface
{
    private readonly ClockInterface $clock;
    private readonly object $miss;
    /** @var array<string, CacheItem> */
    private array $deferred = [];

    /**
     * @param ClockInterface|null $clock Pass the clock given to the cache, if any
     */
    public function __construct(private readonly MultiTierCache $cache, ?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
        $this->miss = new \stdClass();
    }

    public function getItem(string $key): CacheItemInterface
    {
        self::validate($key);

        if (isset($this->deferred[$key]) && !$this->isExpired($this->deferred[$key])) {
            return new CacheItem($key, $this->deferred[$key]->value(), true, $this->clock);
        }

        $value = $this->cache->get($key, $this->miss);
        $hit = $value !== $this->miss;

        return new CacheItem($key, $hit ? $value : null, $hit, $this->clock);
    }

    /**
     * @param string[] $keys
     * @return array<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $toFetch = [];
        foreach ($keys as $key) {
            self::validate($key);
            if (!isset($this->deferred[$key]) || $this->isExpired($this->deferred[$key])) {
                $toFetch[] = $key;
            }
        }

        $values = $toFetch === [] ? [] : $this->cache->getMultiple($toFetch, $this->miss);

        $items = [];
        foreach ($keys as $key) {
            if (!in_array($key, $toFetch, true)) {
                $items[$key] = new CacheItem($key, $this->deferred[$key]->value(), true, $this->clock);
                continue;
            }
            $value = $values[$key] ?? $this->miss;
            $hit = $value !== $this->miss;
            $items[$key] = new CacheItem($key, $hit ? $value : null, $hit, $this->clock);
        }

        return $items;
    }

    public function hasItem(string $key): bool
    {
        self::validate($key);

        if (isset($this->deferred[$key]) && !$this->isExpired($this->deferred[$key])) {
            return true;
        }

        return $this->cache->has($key);
    }

    public function clear(): bool
    {
        $this->deferred = [];
        return $this->cache->clear();
    }

    public function deleteItem(string $key): bool
    {
        self::validate($key);
        unset($this->deferred[$key]);

        return $this->cache->delete($key);
    }

    /**
     * @param string[] $keys
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            self::validate($key);
            unset($this->deferred[$key]);
        }

        return $this->cache->deleteMultiple($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof CacheItem) {
            return false;
        }

        unset($this->deferred[$item->getKey()]);

        $ttl = $this->ttl($item);
        if ($ttl !== null && $ttl <= 0) {
            // Already expired: make sure no older value survives.
            $this->cache->delete($item->getKey());
            return true;
        }

        return $this->cache->set($item->getKey(), $item->value(), $ttl);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof CacheItem) {
            return false;
        }

        $this->deferred[$item->getKey()] = $item;
        return true;
    }

    public function commit(): bool
    {
        $groups = [];
        $expired = [];
        foreach ($this->deferred as $key => $item) {
            $ttl = $this->ttl($item);
            if ($ttl !== null && $ttl <= 0) {
                $expired[] = $key;
                continue;
            }
            $groups[$ttl ?? 'default'][$key] = $item->value();
        }
        $this->deferred = [];

        $ok = $expired === [] || $this->cache->deleteMultiple($expired);
        foreach ($groups as $ttl => $values) {
            $ok = $this->cache->setMultiple($values, $ttl === 'default' ? null : $ttl) && $ok;
        }

        return $ok;
    }

    public function __destruct()
    {
        if ($this->deferred !== []) {
            $this->commit();
        }
    }

    /**
     * Seconds until the item expires, or null for the cache's default TTL.
     */
    private function ttl(CacheItem $item): ?int
    {
        $expiry = $item->expiry();

        return $expiry === null ? null : $expiry - $this->clock->now()->getTimestamp();
    }

    private function isExpired(CacheItem $item): bool
    {
        $ttl = $this->ttl($item);

        return $ttl !== null && $ttl <= 0;
    }

    /**
     * @throws InvalidArgument
     */
    private static function validate(string $key): void
    {
        try {
            KeyValidator::assert($key);
        } catch (CacheInvalidArgument $e) {
            throw new InvalidArgument($e->getMessage(), 0, $e);
        }
    }
}

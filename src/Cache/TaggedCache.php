<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Cache;

use DateInterval;
use Iprodev\EasyCache\Exceptions\InvalidArgument;
use Iprodev\EasyCache\Util\KeyValidator;
use Psr\SimpleCache\CacheInterface;

/**
 * A PSR-16 view of a MultiTierCache whose entries belong to a set of tags.
 *
 * Keys are namespaced by the current version of every tag, so invalidating
 * a tag (MultiTierCache::invalidateTags() or clear() on this view) makes all
 * its entries unreachable at once. Read entries with the same tags they were
 * written with.
 */
final class TaggedCache implements CacheInterface
{
    private MultiTierCache $cache;
    /** @var list<string> */
    private array $tags;

    /**
     * @param list<string> $tags
     */
    public function __construct(MultiTierCache $cache, array $tags)
    {
        $this->cache = $cache;
        $this->tags = $tags;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($this->key($key), $default);
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        return $this->cache->set($this->key($key), $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->cache->delete($this->key($key));
    }

    /**
     * Invalidate the tags of this view (and therefore every entry using any of them).
     */
    public function clear(): bool
    {
        return $this->cache->invalidateTags($this->tags);
    }

    /**
     * @param iterable<mixed> $keys
     * @return array<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): array
    {
        $map = $this->keyMap($keys);
        $values = $this->cache->getMultiple(array_keys($map), $default);

        $result = [];
        foreach ($map as $internal => $original) {
            $result[$original] = $values[$internal] ?? $default;
        }
        return $result;
    }

    /**
     * @param iterable<mixed, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $items = [];
        foreach ($values as $key => $value) {
            $items[$this->key(KeyValidator::normalize($key))] = $value;
        }
        return $this->cache->setMultiple($items, $ttl);
    }

    /**
     * @param iterable<mixed> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        return $this->cache->deleteMultiple(array_keys($this->keyMap($keys)));
    }

    public function has(string $key): bool
    {
        return $this->cache->has($this->key($key));
    }

    /**
     * @param callable(): mixed $producer
     * @param array{mode?: string} $options
     */
    public function getOrSetSWR(
        string $key,
        callable $producer,
        null|int|DateInterval $ttl = null,
        int $swrSeconds = 0,
        int $staleIfErrorSeconds = 0,
        array $options = []
    ): mixed {
        return $this->cache->getOrSetSWR(
            $this->key($key),
            $producer,
            $ttl,
            $swrSeconds,
            $staleIfErrorSeconds,
            $options
        );
    }

    /**
     * @param callable(): mixed $producer
     */
    public function remember(string $key, null|int|DateInterval $ttl, callable $producer): mixed
    {
        return $this->cache->remember($this->key($key), $ttl, $producer);
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->cache->forever($this->key($key), $value);
    }

    private function key(string $key): string
    {
        return $this->cache->taggedKey($this->tags, $key);
    }

    /**
     * @param iterable<mixed> $keys
     * @return array<string, string> internal key => original key
     */
    private function keyMap(iterable $keys): array
    {
        if (!is_iterable($keys)) {
            throw new InvalidArgument('Keys must be iterable');
        }

        $map = [];
        foreach ($keys as $key) {
            $key = KeyValidator::normalize($key);
            $map[$this->key($key)] = $key;
        }
        return $map;
    }
}

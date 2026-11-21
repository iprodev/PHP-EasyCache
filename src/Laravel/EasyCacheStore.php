<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Laravel;

use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Iprodev\EasyCache\Cache\MultiTierCache;

/**
 * Laravel cache store backed by a MultiTierCache, registered as the
 * "easycache" cache driver:
 *
 *     Cache::store('easycache')->remember('users', 600, fn () => User::all());
 *
 * Laravel keys may contain characters that EasyCache keys cannot (":", "/",
 * spaces...): those characters (and "%") are percent-encoded, which keeps
 * the mapping one-to-one; keys that would become longer than 255 bytes are
 * hashed.
 */
final class EasyCacheStore extends TaggableStore implements LockProvider
{
    private readonly object $miss;

    public function __construct(private readonly MultiTierCache $cache, private readonly string $prefix = '')
    {
        $this->miss = new \stdClass();
    }

    public function getCache(): MultiTierCache
    {
        return $this->cache;
    }

    /**
     * @param string $key
     */
    public function get($key): mixed
    {
        return $this->cache->get($this->key($key));
    }

    /**
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        $map = [];
        foreach ($keys as $key) {
            $map[$this->key($key)] = $key;
        }

        $values = $this->cache->getMultiple(array_keys($map));

        $result = [];
        foreach ($map as $internal => $key) {
            $result[$key] = $values[$internal] ?? null;
        }
        return $result;
    }

    /**
     * @param string $key
     * @param int    $seconds
     */
    public function put($key, $value, $seconds): bool
    {
        return $this->cache->set($this->key($key), $value, (int) $seconds);
    }

    /**
     * @param array<string, mixed> $values
     * @param int                  $seconds
     */
    public function putMany(array $values, $seconds): bool
    {
        $items = [];
        foreach ($values as $key => $value) {
            $items[$this->key((string) $key)] = $value;
        }

        return $this->cache->setMultiple($items, (int) $seconds);
    }

    /**
     * Store a value if the key does not exist yet (atomic).
     *
     * @param string $key
     * @param int    $seconds 0 = no expiry
     */
    public function add($key, mixed $value, $seconds): bool
    {
        return $this->cache->add($this->key($key), $value, max(0, (int) $seconds));
    }

    /**
     * @param string $key
     * @param int    $value
     */
    public function increment($key, $value = 1): int|false
    {
        // Like Laravel's Redis store, new counters do not expire.
        return $this->cache->increment($this->key($key), (int) $value, 0);
    }

    /**
     * @param string $key
     * @param int    $value
     */
    public function decrement($key, $value = 1): int|false
    {
        return $this->cache->decrement($this->key($key), (int) $value, 0);
    }

    /**
     * @param string $key
     */
    public function forever($key, $value): bool
    {
        return $this->cache->forever($this->key($key), $value);
    }

    /**
     * @param string $key
     * @param int    $seconds
     */
    public function touch($key, $seconds): bool
    {
        $internal = $this->key($key);
        $value = $this->cache->get($internal, $this->miss);
        if ($value === $this->miss) {
            return false;
        }

        return $this->cache->set($internal, $value, (int) $seconds);
    }

    /**
     * @param string $key
     */
    public function forget($key): bool
    {
        return $this->cache->delete($this->key($key));
    }

    public function flush(): bool
    {
        return $this->cache->clear();
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * @param string      $name
     * @param int         $seconds 0 = until released
     * @param string|null $owner
     */
    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        return new EasyCacheLock($this, 'lock.' . $name, (int) $seconds, $owner);
    }

    /**
     * @param string $name
     * @param string $owner
     */
    public function restoreLock($name, $owner): Lock
    {
        return $this->lock($name, 0, $owner);
    }

    /**
     * Map a Laravel key to a valid EasyCache key.
     */
    private function key(string $key): string
    {
        $full = $this->prefix . $key;

        // Percent-encode bytes that are not allowed in EasyCache keys, and "%"
        // itself so that the encoding is one-to-one.
        $encoded = (string) preg_replace_callback(
            '/[^\x21-\x7E]|[{}()\/\\\\@:%]/',
            static fn (array $match): string => sprintf('%%%02X', ord($match[0])),
            $full
        );

        if ($encoded !== '' && strlen($encoded) <= 255) {
            return $encoded;
        }

        // "%%" never occurs in encoded keys, so hashed keys cannot collide with them.
        return '%%' . hash('sha256', $full);
    }
}

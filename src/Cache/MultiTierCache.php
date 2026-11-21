<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Cache;

use DateInterval;
use InvalidArgumentException;
use Iprodev\EasyCache\Clock\SystemClock;
use Iprodev\EasyCache\Compression\CompressorInterface;
use Iprodev\EasyCache\Compression\GzipCompressor;
use Iprodev\EasyCache\Compression\NullCompressor;
use Iprodev\EasyCache\Compression\ZstdCompressor;
use Iprodev\EasyCache\Exceptions\InvalidArgument;
use Iprodev\EasyCache\Lock\FileLockProvider;
use Iprodev\EasyCache\Lock\LockProviderInterface;
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Serialization\SerializerInterface;
use Iprodev\EasyCache\Storage\PrunableStorageInterface;
use Iprodev\EasyCache\Storage\StorageInterface;
use Iprodev\EasyCache\Util\KeyValidator;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * PSR-16 cache over one or more storage tiers (fastest first), with
 * stale-while-revalidate, stale-if-error, stampede protection, optional
 * compression and optional HMAC signing of entries.
 *
 * Entry format (binary, version "EC03"):
 *
 *   "EC03" | flags (1) | expires (uint64 BE) | swr (uint32 BE) | sei (uint32 BE)
 *   | serializer name length (1) + name | compressor name length (1) + name
 *   | [HMAC-SHA256 (32) when flags & 1] | body
 *
 * The header is parsed with unpack(), never with unserialize(), so reading an
 * entry cannot instantiate objects unless the configured serializer does so.
 * Serializers that can (SerializerInterface::allowsObjects()) require a
 * signing key, so that only entries written by this application are read.
 */
final class MultiTierCache implements CacheInterface
{
    private const string MAGIC = 'EC03';
    private const int FLAG_SIGNED = 1;
    private const int FIXED_HEADER_LENGTH = 21; // magic(4) + flags(1) + expires(8) + swr(4) + sei(4)
    private const int MAC_LENGTH = 32;
    private const int MAX_WINDOW = 4294967295;
    private const string TAG_VERSION_PREFIX = '__ec_tag.';
    private const string ATOMIC_LOCK_PREFIX = 'ec-atomic:';

    /** @var list<StorageInterface> */
    private array $tiers;
    private SerializerInterface $serializer;
    private CompressorInterface $compressor;
    /** @var array<string, CompressorInterface|null> */
    private array $compressors = [];
    private int $defaultTtl;
    private ?LoggerInterface $logger;
    private ClockInterface $clock;
    private LockProviderInterface $locks;
    private ?string $signingKey;
    private float $lockTimeout;

    /** @var array<string, callable(): void> */
    private array $deferred = [];
    private bool $shutdownRegistered = false;

    /** @var array{hits: int, misses: int, stale_hits: int, stale_if_error: int, writes: int, deletes: int, errors: int} */
    private array $stats = [
        'hits' => 0,
        'misses' => 0,
        'stale_hits' => 0,
        'stale_if_error' => 0,
        'writes' => 0,
        'deletes' => 0,
        'errors' => 0,
    ];

    /**
     * Arguments after $logger are best passed by name (or use builder()).
     *
     * @param StorageInterface[]         $tiers         Read/write order; tier[0] is the fastest
     * @param SerializerInterface|null   $serializer    Defaults to NativeSerializer without objects
     * @param CompressorInterface|null   $compressor    Defaults to NullCompressor
     * @param int                        $defaultTtl    TTL in seconds when none is given (0 = never expire)
     * @param LoggerInterface|null       $logger        PSR-3 logger, also handed to tiers without one
     * @param ClockInterface|null        $clock         PSR-20 clock (defaults to the system clock)
     * @param string|null                $signingKey    Secret (>= 16 bytes) used to sign entries with HMAC-SHA256;
     *                                                  unsigned or tampered entries are then rejected
     * @param LockProviderInterface|null $lockProvider  Lock implementation (defaults to file locks in a
     *                                                  private per-user temp directory)
     * @param float                      $lockTimeout   Seconds to wait for a lock before computing without it
     * @param bool                       $allowUnsignedObjects Allow a serializer that can create objects
     *                                                  without a signing key. Only safe when nobody else
     *                                                  can write to any of the tiers.
     */
    public function __construct(
        array $tiers,
        ?SerializerInterface $serializer = null,
        ?CompressorInterface $compressor = null,
        int $defaultTtl = 3600,
        ?LoggerInterface $logger = null,
        ?ClockInterface $clock = null,
        ?string $signingKey = null,
        ?LockProviderInterface $lockProvider = null,
        float $lockTimeout = 30.0,
        bool $allowUnsignedObjects = false
    ) {
        if ($tiers === []) {
            throw new InvalidArgumentException('At least one storage tier is required.');
        }
        foreach ($tiers as $tier) {
            if (!$tier instanceof StorageInterface) {
                throw new InvalidArgumentException('Every tier must implement ' . StorageInterface::class);
            }
        }
        if ($signingKey !== null && strlen($signingKey) < 16) {
            throw new InvalidArgumentException('The signing key must be at least 16 bytes long.');
        }

        $this->tiers = array_values($tiers);
        $this->serializer = $serializer ?? new NativeSerializer();
        $this->compressor = $compressor ?? new NullCompressor();
        $this->defaultTtl = max(0, $defaultTtl);
        $this->logger = $logger;
        $this->clock = $clock ?? new SystemClock();
        $this->signingKey = $signingKey;
        $this->locks = $lockProvider ?? new FileLockProvider();
        $this->lockTimeout = max(0.0, $lockTimeout);

        self::assertName($this->serializer->name(), 'Serializer');
        self::assertName($this->compressor->name(), 'Compressor');

        if ($this->serializer->allowsObjects() && $signingKey === null && !$allowUnsignedObjects) {
            throw new InvalidArgumentException(
                'The configured serializer can create PHP objects, which is only safe with trusted cache data. '
                . 'Pass a signingKey (recommended), restrict NativeSerializer allowedClasses to false, '
                . 'or set allowUnsignedObjects: true if nobody else can write to any cache tier.'
            );
        }

        if ($logger !== null) {
            foreach ($this->tiers as $tier) {
                if (
                    $tier instanceof LoggerAwareInterface
                    && !(method_exists($tier, 'hasLogger') && $tier->hasLogger())
                ) {
                    $tier->setLogger($logger);
                }
            }
        }
    }

    /**
     * Fluent alternative to the constructor.
     */
    public static function builder(): CacheBuilder
    {
        return new CacheBuilder();
    }

    // ---------------------------------------------------------------------
    // PSR-16
    // ---------------------------------------------------------------------

    public function get(string $key, mixed $default = null): mixed
    {
        KeyValidator::assert($key);

        try {
            $hit = $this->lookup([$key], false)[$key] ?? null;
        } catch (Throwable $e) {
            $this->failure('warning', 'Cache get failed for key {key}', $key, $e);
            return $default;
        }

        if ($hit === null) {
            $this->stats['misses']++;
            return $default;
        }

        $this->stats['hits']++;
        return $hit['value'];
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        KeyValidator::assert($key);

        try {
            $seconds = $this->resolveTtl($ttl);
            if ($ttl !== null && $seconds <= 0) {
                return $this->delete($key);
            }

            return $this->store($key, $value, $this->expiresAt($seconds), 0, 0);
        } catch (Throwable $e) {
            $this->failure('error', 'Cache set failed for key {key}', $key, $e);
            return false;
        }
    }

    public function delete(string $key): bool
    {
        KeyValidator::assert($key);

        $ok = true;
        foreach ($this->tiers as $i => $tier) {
            try {
                $ok = $tier->delete($key) && $ok;
            } catch (Throwable $e) {
                $this->failure('warning', "Delete failed on tier {$i} for key {key}", $key, $e);
                $ok = false;
            }
        }
        $this->stats['deletes']++;

        return $ok;
    }

    public function clear(): bool
    {
        $ok = true;
        foreach ($this->tiers as $i => $tier) {
            try {
                $ok = $tier->clear() && $ok;
            } catch (Throwable $e) {
                $this->failure('warning', "Clear failed on tier {$i}", null, $e);
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * @param iterable<mixed> $keys
     * @return array<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): array
    {
        $list = $this->normalizeKeys($keys);

        try {
            $hits = $this->lookup(array_values(array_unique($list)), false);
        } catch (Throwable $e) {
            $this->failure('warning', 'Cache getMultiple failed', null, $e);
            $hits = [];
        }

        $result = [];
        foreach ($list as $key) {
            if (array_key_exists($key, $hits)) {
                $result[$key] = $hits[$key]['value'];
                $this->stats['hits']++;
            } else {
                $result[$key] = $default;
                $this->stats['misses']++;
            }
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
            $items[KeyValidator::normalize($key)] = $value;
        }
        if ($items === []) {
            return true;
        }

        try {
            $seconds = $this->resolveTtl($ttl);
            if ($ttl !== null && $seconds <= 0) {
                return $this->deleteMultiple(array_map('strval', array_keys($items)));
            }
            $expires = $this->expiresAt($seconds);
        } catch (Throwable $e) {
            $this->failure('error', 'Cache setMultiple failed', null, $e);
            return false;
        }

        $ok = true;
        $payloads = [];
        foreach ($items as $key => $value) {
            try {
                $payloads[(string) $key] = $this->encodeRecord($value, $expires, 0, 0);
            } catch (Throwable $e) {
                $this->failure('error', 'Cache set failed for key {key}', (string) $key, $e);
                $ok = false;
            }
        }

        $ttlSeconds = $this->physicalTtl($expires, 0, 0);
        foreach ($this->tiers as $i => $tier) {
            try {
                $ok = $tier->setMany($payloads, $ttlSeconds) && $ok;
            } catch (Throwable $e) {
                $this->failure('warning', "setMultiple failed on tier {$i}", null, $e);
                $ok = false;
            }
        }
        $this->stats['writes'] += count($payloads);

        return $ok;
    }

    /**
     * @param iterable<mixed> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $list = array_values(array_unique($this->normalizeKeys($keys)));
        if ($list === []) {
            return true;
        }

        $ok = true;
        foreach ($this->tiers as $i => $tier) {
            try {
                $ok = $tier->deleteMany($list) && $ok;
            } catch (Throwable $e) {
                $this->failure('warning', "deleteMultiple failed on tier {$i}", null, $e);
                $ok = false;
            }
        }
        $this->stats['deletes'] += count($list);

        return $ok;
    }

    public function has(string $key): bool
    {
        KeyValidator::assert($key);

        try {
            return isset($this->lookup([$key], false, false, false)[$key]);
        } catch (Throwable $e) {
            $this->failure('warning', 'Cache has failed for key {key}', $key, $e);
            return false;
        }
    }

    // ---------------------------------------------------------------------
    // Extensions
    // ---------------------------------------------------------------------

    /**
     * Get a value or compute it, with stale-while-revalidate and stale-if-error.
     *
     * - Fresh entry: returned as is.
     * - Expired but within $swrSeconds: the stale value is served while the
     *   entry is refreshed. With RefreshMode::Sync the process that wins the
     *   lock refreshes right away and returns the new value (others get the
     *   stale value without waiting); with RefreshMode::Defer the refresh runs
     *   after the response has been sent (PHP-FPM/LiteSpeed; elsewhere Sync).
     * - Expired but within $staleIfErrorSeconds: recomputed; if the producer
     *   throws, the stale value is returned instead of the exception.
     * - Missing: computed under a per-key lock so concurrent requests do not
     *   all run the producer (cache stampede protection).
     *
     * A null TTL uses the default TTL; a TTL <= 0 computes the value without caching it.
     *
     * @param callable(): mixed $producer
     */
    public function getOrSetSWR(
        string $key,
        callable $producer,
        null|int|DateInterval $ttl = null,
        int $swrSeconds = 0,
        int $staleIfErrorSeconds = 0,
        RefreshMode $mode = RefreshMode::Sync
    ): mixed {
        KeyValidator::assert($key);

        $seconds = $this->resolveTtl($ttl);
        if ($ttl !== null && $seconds <= 0) {
            return $producer();
        }

        $swr = min(self::MAX_WINDOW, max(0, $swrSeconds));
        $sei = min(self::MAX_WINDOW, max(0, $staleIfErrorSeconds));

        $hit = null;
        try {
            $hit = $this->lookup([$key], true)[$key] ?? null;
        } catch (Throwable $e) {
            $this->failure('warning', 'SWR cache read failed for key {key}', $key, $e);
        }

        if ($hit !== null && !$hit['stale']) {
            $this->stats['hits']++;
            return $hit['value'];
        }

        if ($hit !== null && $hit['within_swr']) {
            $this->stats['stale_hits']++;

            if ($mode === RefreshMode::Defer && self::canDefer()) {
                $this->defer($key, function () use ($key, $producer, $seconds, $swr, $sei): void {
                    $this->revalidate($key, $producer, $seconds, $swr, $sei);
                });
                return $hit['value'];
            }

            $fresh = $this->revalidate($key, $producer, $seconds, $swr, $sei);
            return $fresh['ok'] ? $fresh['value'] : $hit['value'];
        }

        if ($hit === null) {
            $this->stats['misses']++;
        }

        // Missing, or stale and only usable as a fallback if the producer fails.
        return $this->computeWithLock($key, $producer, $seconds, $swr, $sei, $hit);
    }

    /**
     * Get a value or compute and cache it (no stale serving).
     *
     * @param callable(): mixed $producer
     */
    public function remember(string $key, null|int|DateInterval $ttl, callable $producer): mixed
    {
        return $this->getOrSetSWR($key, $producer, $ttl);
    }

    /**
     * Atomically add $by to an integer entry and return the new value.
     *
     * A missing (or expired) entry starts at 0 and is created with $ttl
     * (null = default TTL, <= 0 = no expiry); an existing entry keeps its
     * expiry. Returns false when the entry holds a non-integer value or the
     * lock cannot be acquired within the lock timeout.
     *
     * Atomicity comes from the lock provider: file locks serialize the
     * processes of one machine, RedisLockProvider all servers.
     */
    public function increment(string $key, int $by = 1, null|int|DateInterval $ttl = null): int|false
    {
        KeyValidator::assert($key);

        return $this->atomically($key, function () use ($key, $by, $ttl): int|false {
            $current = $this->lookup([$key], false)[$key] ?? null;

            if ($current === null) {
                $value = $by;
                $expires = $this->atomicExpiry($ttl);
                $swr = $sei = 0;
            } else {
                $old = $current['value'];
                if (is_string($old) && preg_match('/^-?\d+$/', $old) === 1) {
                    $old = (int) $old;
                }
                if (!is_int($old)) {
                    $this->log('warning', 'Cannot increment key {key}: the cached value is not an integer', [
                        'key' => $key,
                    ]);
                    return false;
                }
                $value = $old + $by;
                $expires = $current['expires'];
                $swr = $current['swr'];
                $sei = $current['sei'];
            }

            return $this->store($key, $value, $expires, $swr, $sei) ? $value : false;
        });
    }

    /**
     * Atomically subtract $by from an integer entry (see increment()).
     */
    public function decrement(string $key, int $by = 1, null|int|DateInterval $ttl = null): int|false
    {
        return $this->increment($key, -$by, $ttl);
    }

    /**
     * Store a value only if the key has no fresh entry (atomic, see increment()).
     *
     * $ttl: null = default TTL, <= 0 = no expiry. Returns true when the value was stored.
     */
    public function add(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        KeyValidator::assert($key);

        return $this->atomically($key, function () use ($key, $value, $ttl): bool {
            if (isset($this->lookup([$key], false, false, false)[$key])) {
                return false;
            }

            return $this->store($key, $value, $this->atomicExpiry($ttl), 0, 0);
        }) === true;
    }

    /**
     * Store a value without expiry.
     */
    public function forever(string $key, mixed $value): bool
    {
        KeyValidator::assert($key);

        try {
            return $this->store($key, $value, 0, 0, 0);
        } catch (Throwable $e) {
            $this->failure('error', 'Cache set failed for key {key}', $key, $e);
            return false;
        }
    }

    /**
     * A view of the cache whose entries are invalidated together with the
     * given tags (see invalidateTags()). Entries must be read with the same
     * set of tags they were written with.
     *
     * @param string|list<string> $tags
     */
    public function tags(string|array $tags): TaggedCache
    {
        return new TaggedCache($this, $this->normalizeTags($tags));
    }

    /**
     * Invalidate every entry written with any of the given tags.
     *
     * Invalidated entries are not deleted, they just become unreachable and
     * expire through their TTL (or prune()).
     *
     * @param string|list<string> $tags
     */
    public function invalidateTags(string|array $tags): bool
    {
        $ok = true;
        foreach ($this->normalizeTags($tags) as $tag) {
            $ok = $this->forever(self::TAG_VERSION_PREFIX . $tag, bin2hex(random_bytes(8))) && $ok;
        }
        return $ok;
    }

    /**
     * Build the internal key for an entry under a set of tags.
     *
     * @internal Used by TaggedCache.
     * @param list<string> $tags
     */
    public function taggedKey(array $tags, string $key): string
    {
        return self::namespacedKey($this->tagNamespace($tags), $key);
    }

    /**
     * Key prefix for the current versions of a set of tags. All versions are
     * read with one getMultiple(); missing versions are created atomically.
     *
     * @internal Used by TaggedCache.
     * @param list<string> $tags
     */
    public function tagNamespace(array $tags): string
    {
        $versionKeys = [];
        foreach ($tags as $tag) {
            $versionKeys[$tag] = self::TAG_VERSION_PREFIX . $tag;
        }

        $current = $this->getMultiple(array_values($versionKeys));
        $parts = [];
        foreach ($versionKeys as $tag => $versionKey) {
            $version = $current[$versionKey] ?? null;
            if (!is_string($version)) {
                $version = bin2hex(random_bytes(8));
                if (!$this->add($versionKey, $version, 0)) {
                    // Another process created the version first: use theirs.
                    $existing = $this->get($versionKey);
                    $version = is_string($existing) ? $existing : $version;
                }
            }
            $parts[] = $tag . '=' . $version;
        }

        return 'tag.' . hash('xxh128', implode('|', $parts)) . '.';
    }

    /**
     * @internal Used by TaggedCache.
     */
    public static function namespacedKey(string $namespace, string $key): string
    {
        KeyValidator::assert($key);

        $namespaced = $namespace . $key;
        if (strlen($namespaced) > KeyValidator::MAX_LENGTH) {
            throw new InvalidArgument(sprintf(
                'Cache key is too long to be used with tags (max %d bytes)',
                KeyValidator::MAX_LENGTH - strlen($namespace)
            ));
        }

        return $namespaced;
    }

    /**
     * Prune expired items across tiers.
     *
     * @return int Number of items pruned
     */
    public function prune(): int
    {
        $sum = 0;
        foreach ($this->tiers as $i => $tier) {
            if (!$tier instanceof PrunableStorageInterface) {
                continue;
            }
            try {
                $sum += $tier->prune();
            } catch (Throwable $e) {
                $this->failure('warning', "Prune failed on tier {$i}", null, $e);
            }
        }
        return $sum;
    }

    /**
     * Counters collected by this instance since creation (or resetStats()).
     *
     * @return array{
     *   hits: int, misses: int, stale_hits: int, stale_if_error: int,
     *   writes: int, deletes: int, errors: int, hit_ratio: float
     * }
     */
    public function getStats(): array
    {
        $lookups = $this->stats['hits'] + $this->stats['misses'] + $this->stats['stale_hits'];
        $served = $this->stats['hits'] + $this->stats['stale_hits'];

        return $this->stats + ['hit_ratio' => $lookups > 0 ? round($served / $lookups, 4) : 0.0];
    }

    public function resetStats(): void
    {
        foreach ($this->stats as $name => $value) {
            $this->stats[$name] = 0;
        }
    }

    // ---------------------------------------------------------------------
    // SWR internals
    // ---------------------------------------------------------------------

    /**
     * Refresh an entry if no other process is doing so already.
     *
     * @param callable(): mixed $producer
     * @return array{ok: bool, value: mixed}
     */
    private function revalidate(string $key, callable $producer, int $seconds, int $swr, int $sei): array
    {
        $lock = $this->locks->create($key);

        try {
            if (!$lock->acquire(false)) {
                return ['ok' => false, 'value' => null];
            }
        } catch (Throwable $e) {
            $this->failure('warning', 'Failed to acquire lock for key {key}', $key, $e);
            return ['ok' => false, 'value' => null];
        }

        try {
            // Another process may have refreshed the entry meanwhile.
            $current = $this->lookup([$key], false)[$key] ?? null;
            if ($current !== null) {
                return ['ok' => true, 'value' => $current['value']];
            }

            $value = $producer();
            $this->storeComputed($key, $value, $seconds, $swr, $sei);

            return ['ok' => true, 'value' => $value];
        } catch (Throwable $e) {
            $this->failure('warning', 'SWR refresh failed for key {key}', $key, $e);
            return ['ok' => false, 'value' => null];
        } finally {
            $lock->release();
        }
    }

    /**
     * @param callable(): mixed $producer
     * @param array{
     *   value: mixed, stale: bool, within_swr: bool, within_sei: bool, expires: int, swr: int, sei: int
     * }|null $stale
     */
    private function computeWithLock(
        string $key,
        callable $producer,
        int $seconds,
        int $swr,
        int $sei,
        ?array $stale
    ): mixed {
        $lock = $this->locks->create($key);
        $locked = false;

        try {
            $locked = $lock->acquire(true, $this->lockTimeout);
        } catch (Throwable $e) {
            $this->failure('warning', 'Failed to acquire lock for key {key}', $key, $e);
        }

        if (!$locked) {
            $this->log('warning', 'Could not acquire lock for key {key}, computing without it', ['key' => $key]);
        }

        try {
            if ($locked) {
                // Double-check: another process may have computed it while we waited.
                try {
                    $current = $this->lookup([$key], false)[$key] ?? null;
                } catch (Throwable $e) {
                    $current = null;
                }
                if ($current !== null) {
                    return $current['value'];
                }
            }

            try {
                $value = $producer();
            } catch (Throwable $e) {
                if ($stale !== null && $stale['within_sei']) {
                    $this->stats['stale_if_error']++;
                    $this->failure('warning', 'Producer failed for key {key}, serving stale value', $key, $e);
                    return $stale['value'];
                }
                $this->failure('error', 'Producer failed for key {key}', $key, $e);
                throw $e;
            }

            $this->storeComputed($key, $value, $seconds, $swr, $sei);

            return $value;
        } finally {
            if ($locked) {
                $lock->release();
            }
        }
    }

    /**
     * Run $operation while holding the atomic-operation lock of $key.
     *
     * @template T
     * @param callable(): T $operation
     * @return T|false false when the lock could not be acquired or the operation failed
     */
    private function atomically(string $key, callable $operation): mixed
    {
        $lock = $this->locks->create(self::ATOMIC_LOCK_PREFIX . $key);

        try {
            if (!$lock->acquire(true, $this->lockTimeout)) {
                $this->log('warning', 'Could not acquire lock for atomic operation on key {key}', ['key' => $key]);
                return false;
            }
        } catch (Throwable $e) {
            $this->failure('warning', 'Failed to acquire lock for key {key}', $key, $e);
            return false;
        }

        try {
            return $operation();
        } catch (Throwable $e) {
            $this->failure('error', 'Atomic operation failed for key {key}', $key, $e);
            return false;
        } finally {
            $lock->release();
        }
    }

    private function atomicExpiry(null|int|DateInterval $ttl): int
    {
        $seconds = $this->resolveTtl($ttl);

        return $seconds > 0 ? $this->now() + $seconds : 0;
    }

    private function storeComputed(string $key, mixed $value, int $seconds, int $swr, int $sei): void
    {
        try {
            $this->store($key, $value, $this->expiresAt($seconds), $swr, $sei);
        } catch (Throwable $e) {
            $this->failure('error', 'Failed to store computed value for key {key}', $key, $e);
        }
    }

    private static function canDefer(): bool
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    }

    /**
     * @param callable(): void $job
     */
    private function defer(string $key, callable $job): void
    {
        if (isset($this->deferred[$key])) {
            return;
        }
        $this->deferred[$key] = $job;

        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(function (): void {
                $this->runDeferred();
            });
        }
    }

    private function runDeferred(): void
    {
        if ($this->deferred === []) {
            return;
        }

        try {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                @litespeed_finish_request();
            }
        } catch (Throwable) {
            // Ignore: the jobs still run, only the response is not flushed early.
        }

        $jobs = $this->deferred;
        $this->deferred = [];
        foreach ($jobs as $job) {
            try {
                $job();
            } catch (Throwable $e) {
                $this->failure('warning', 'Deferred revalidation failed', null, $e);
            }
        }
    }

    // ---------------------------------------------------------------------
    // Tier access
    // ---------------------------------------------------------------------

    /**
     * Look up keys across tiers.
     *
     * A tier's entry is used when it is fresh. Expired entries do not stop the
     * search: lower tiers may hold a fresher copy (written by another server).
     * With $allowStale, the first expired-but-still-usable entry is returned
     * when no tier has a fresh one. Entries found in lower tiers are copied
     * to the tiers above them.
     *
     * @param list<string> $keys
     * @return array<string, array{
     *   value: mixed, stale: bool, within_swr: bool, within_sei: bool, expires: int, swr: int, sei: int
     * }>
     */
    private function lookup(array $keys, bool $allowStale, bool $withValue = true, bool $backfill = true): array
    {
        $now = $this->now();
        $pending = [];
        foreach ($keys as $key) {
            $pending[$key] = true;
        }

        /** @var array<string, array{record: array{e: int, swr: int, sei: int, v: mixed}, raw: string, tier: int, stale: bool}> $found */
        $found = [];

        foreach ($this->tiers as $index => $tier) {
            if ($pending === []) {
                break;
            }

            $wanted = array_map('strval', array_keys($pending));
            try {
                $raws = $this->fetch($tier, $wanted);
            } catch (Throwable $e) {
                $this->failure('warning', "Read failed on tier {$index}", null, $e);
                continue;
            }

            foreach ($wanted as $key) {
                $raw = $raws[$key] ?? null;
                if (!is_string($raw)) {
                    continue;
                }

                try {
                    $record = $this->decodeRecord($raw, $withValue);
                } catch (Throwable $e) {
                    $this->failure('warning', "Failed to decode entry for key {key} on tier {$index}", $key, $e);
                    $record = null;
                }
                if ($record === null) {
                    continue;
                }

                $state = $this->classify($record, $now);
                if ($state === 'fresh') {
                    $found[$key] = ['record' => $record, 'raw' => $raw, 'tier' => $index, 'stale' => false];
                    unset($pending[$key]);
                } elseif ($state === 'stale' && $allowStale && !isset($found[$key])) {
                    // Keep looking in lower tiers for a fresh copy.
                    $found[$key] = ['record' => $record, 'raw' => $raw, 'tier' => $index, 'stale' => true];
                }
            }
        }

        $result = [];
        foreach ($found as $key => $entry) {
            $record = $entry['record'];
            $key = (string) $key;

            if ($backfill && $entry['tier'] > 0) {
                $this->backfill($key, $entry['raw'], $entry['tier'], $record);
            }

            $expired = $entry['stale'];
            $result[$key] = [
                'value' => $record['v'],
                'stale' => $expired,
                'within_swr' => $expired && $record['swr'] > 0 && $now < $record['e'] + $record['swr'],
                'within_sei' => $expired && $record['sei'] > 0 && $now < $record['e'] + $record['sei'],
                'expires' => $record['e'],
                'swr' => $record['swr'],
                'sei' => $record['sei'],
            ];
        }

        return $result;
    }

    /**
     * @param array{e: int, swr: int, sei: int, v: mixed} $record
     */
    private function backfill(string $key, string $raw, int $foundTier, array $record): void
    {
        $ttl = $this->physicalTtl($record['e'], $record['swr'], $record['sei']);

        for ($j = 0; $j < $foundTier; $j++) {
            try {
                $this->tiers[$j]->set($key, $raw, $ttl);
            } catch (Throwable $e) {
                $this->failure('warning', "Backfill failed on tier {$j} for key {key}", $key, $e);
            }
        }
    }

    /**
     * @param list<string> $keys
     * @return array<string, string>
     */
    private function fetch(StorageInterface $tier, array $keys): array
    {
        if (count($keys) > 1) {
            return $tier->getMany($keys);
        }

        $result = [];
        foreach ($keys as $key) {
            $raw = $tier->get($key);
            if ($raw !== null) {
                $result[$key] = $raw;
            }
        }
        return $result;
    }

    private function store(string $key, mixed $value, int $expires, int $swr, int $sei): bool
    {
        $payload = $this->encodeRecord($value, $expires, $swr, $sei);
        $ttl = $this->physicalTtl($expires, $swr, $sei);

        $ok = true;
        foreach ($this->tiers as $i => $tier) {
            try {
                $ok = $tier->set($key, $payload, $ttl) && $ok;
            } catch (Throwable $e) {
                $this->failure('warning', "Write failed on tier {$i} for key {key}", $key, $e);
                $ok = false;
            }
        }
        $this->stats['writes']++;

        return $ok;
    }

    /**
     * @param array{e: int, swr: int, sei: int, v: mixed} $record
     * @return 'fresh'|'stale'|'dead'
     */
    private function classify(array $record, int $now): string
    {
        $expires = $record['e'];
        if ($expires === 0 || $now < $expires) {
            return 'fresh';
        }

        $window = max($record['swr'], $record['sei']);

        return $now < $expires + $window ? 'stale' : 'dead';
    }

    // ---------------------------------------------------------------------
    // Encoding
    // ---------------------------------------------------------------------

    private function encodeRecord(mixed $value, int $expires, int $swr, int $sei): string
    {
        $body = $this->serializer->serialize($value);

        $compressorName = $this->compressor->name();
        if ($compressorName !== 'none') {
            $body = $this->compressor->compress($body);
        }

        $serializerName = $this->serializer->name();
        $flags = $this->signingKey !== null ? self::FLAG_SIGNED : 0;

        $header = self::MAGIC
            . chr($flags)
            . pack('JNN', max(0, $expires), $swr, $sei)
            . chr(strlen($serializerName)) . $serializerName
            . chr(strlen($compressorName)) . $compressorName;

        if ($this->signingKey !== null) {
            return $header . hash_hmac('sha256', $header . $body, $this->signingKey, true) . $body;
        }

        return $header . $body;
    }

    /**
     * @return array{e: int, swr: int, sei: int, v: mixed}|null
     */
    private function decodeRecord(string $raw, bool $withValue = true): ?array
    {
        $length = strlen($raw);
        if ($length < self::FIXED_HEADER_LENGTH + 2 || strncmp($raw, self::MAGIC, 4) !== 0) {
            return null;
        }

        $flags = ord($raw[4]);
        /** @var array{e: int, swr: int, sei: int} $times */
        $times = unpack('Je/Nswr/Nsei', $raw, 5);

        $pos = self::FIXED_HEADER_LENGTH;
        $serializerName = self::readName($raw, $pos);
        $compressorName = $serializerName === null ? null : self::readName($raw, $pos);
        if ($serializerName === null || $compressorName === null) {
            return null;
        }
        $headerLength = $pos;

        $mac = null;
        if (($flags & self::FLAG_SIGNED) !== 0) {
            if ($pos + self::MAC_LENGTH > $length) {
                return null;
            }
            $mac = substr($raw, $pos, self::MAC_LENGTH);
            $pos += self::MAC_LENGTH;
        }

        if ($this->signingKey !== null) {
            if ($mac === null) {
                $this->log('warning', 'Rejected unsigned cache entry (a signing key is configured)');
                return null;
            }
            $signed = substr($raw, 0, $headerLength) . substr($raw, $pos);
            $expected = hash_hmac('sha256', $signed, $this->signingKey, true);
            if (!hash_equals($expected, $mac)) {
                $this->log('warning', 'Rejected cache entry with an invalid signature');
                return null;
            }
        }

        if ($serializerName !== $this->serializer->name()) {
            return null;
        }

        $compressor = null;
        if ($compressorName !== 'none') {
            $compressor = $this->compressorFor($compressorName);
            if ($compressor === null) {
                return null;
            }
        }

        $record = ['e' => $times['e'], 'swr' => $times['swr'], 'sei' => $times['sei'], 'v' => null];
        if (!$withValue) {
            return $record;
        }

        $body = substr($raw, $pos);
        if ($compressor !== null) {
            $body = $compressor->decompress($body);
        }
        $record['v'] = $this->serializer->deserialize($body);

        return $record;
    }

    private static function readName(string $raw, int &$pos): ?string
    {
        if ($pos >= strlen($raw)) {
            return null;
        }
        $nameLength = ord($raw[$pos]);
        $pos++;
        if ($nameLength === 0 || $pos + $nameLength > strlen($raw)) {
            return null;
        }
        $name = substr($raw, $pos, $nameLength);
        $pos += $nameLength;

        return $name;
    }

    private function compressorFor(string $name): ?CompressorInterface
    {
        if ($name === $this->compressor->name()) {
            return $this->compressor;
        }

        if (!array_key_exists($name, $this->compressors)) {
            $this->compressors[$name] = match (true) {
                $name === 'gzip' && function_exists('gzuncompress') => new GzipCompressor(),
                $name === 'zstd' && function_exists('zstd_uncompress') => new ZstdCompressor(),
                default => null,
            };
        }

        return $this->compressors[$name];
    }

    private static function assertName(string $name, string $what): void
    {
        if ($name === '' || strlen($name) > 255) {
            throw new InvalidArgumentException("{$what} name must be 1-255 bytes long.");
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * TTL in seconds; for a null TTL the default TTL (where 0 means "never expire").
     */
    private function resolveTtl(null|int|DateInterval $ttl): int
    {
        if ($ttl === null) {
            return $this->defaultTtl;
        }

        if ($ttl instanceof DateInterval) {
            $now = $this->clock->now();
            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }

        return $ttl;
    }

    private function expiresAt(int $seconds): int
    {
        return $seconds > 0 ? $this->now() + $seconds : 0;
    }

    /**
     * How long storages keep an entry: until the end of its stale windows.
     */
    private function physicalTtl(int $expires, int $swr, int $sei): int
    {
        if ($expires === 0) {
            return 0;
        }

        return max(1, $expires + max($swr, $sei) - $this->now());
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /**
     * @param iterable<mixed> $keys
     * @return list<string>
     */
    private function normalizeKeys(iterable $keys): array
    {
        $list = [];
        foreach ($keys as $key) {
            $list[] = KeyValidator::normalize($key);
        }
        return $list;
    }

    /**
     * @param string|list<string> $tags
     * @return list<string>
     */
    private function normalizeTags(string|array $tags): array
    {
        $tags = is_string($tags) ? [$tags] : $tags;
        if ($tags === []) {
            throw new InvalidArgument('At least one tag is required');
        }

        $result = [];
        foreach ($tags as $tag) {
            $tag = KeyValidator::normalize($tag);
            KeyValidator::assert(self::TAG_VERSION_PREFIX . $tag);
            $result[$tag] = $tag;
        }
        ksort($result, SORT_STRING);

        return array_values($result);
    }

    private function failure(string $level, string $message, ?string $key, Throwable $e): void
    {
        $this->stats['errors']++;
        $context = ['exception' => $e, 'error' => $e->getMessage()];
        if ($key !== null) {
            $context['key'] = $key;
        }
        $this->log($level, $message . ': {error}', $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $this->logger?->log($level, 'EasyCache: ' . $message, $context);
    }
}

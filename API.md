# API Reference

Complete API documentation for PHP EasyCache v3.1.

## Table of Contents

- [MultiTierCache](#multitiercache)
- [TaggedCache](#taggedcache)
- [Storage Backends](#storage-backends)
- [Locks](#locks)
- [Serializers](#serializers)
- [Compressors](#compressors)
- [Clocks](#clocks)
- [Utilities](#utilities)
- [Exceptions](#exceptions)
- [Laravel Integration](#laravel-integration)
- [Entry Format](#entry-format)

---

## MultiTierCache

The main cache class implementing the PSR-16 `CacheInterface`.

### Constructor

```php
public function __construct(
    array $tiers,
    ?SerializerInterface $serializer = null,
    ?CompressorInterface $compressor = null,
    int $defaultTtl = 3600,
    ?LoggerInterface $logger = null,
    ?string $lockPath = null,
    ?ClockInterface $clock = null,
    ?string $signingKey = null,
    ?LockProviderInterface $lockProvider = null,
    float $lockTimeout = 30.0
)
```

**Parameters:**
- `$tiers` (StorageInterface[]): Storages ordered from fastest to slowest
- `$serializer`: Serializer (default: `NativeSerializer`)
- `$compressor`: Compressor (default: `NullCompressor`)
- `$defaultTtl` (int): TTL used when none is given, in seconds. `0` = never expire (default: 3600)
- `$logger`: PSR-3 logger. It is also handed to every tier that implements `LoggerAwareInterface` and has no logger yet
- `$lockPath` (string|null): Directory for file locks (default: a private per-user directory in the system temp dir). Ignored when `$lockProvider` is given
- `$clock` (Psr\Clock\ClockInterface|null): Time source (default: `SystemClock`)
- `$signingKey` (string|null): Secret of at least 16 bytes. When set, entries are signed with HMAC-SHA256 and unsigned or tampered entries are rejected before they are deserialized
- `$lockProvider` (LockProviderInterface|null): Lock implementation (default: `FileLockProvider`)
- `$lockTimeout` (float): Seconds to wait for a lock on a cache miss before computing without it (default: 30)

**Throws:**
- `\InvalidArgumentException` if `$tiers` is empty or contains something that is not a `StorageInterface`, if the signing key is shorter than 16 bytes, or if a serializer/compressor name is empty or longer than 255 bytes

The constructor does not touch the filesystem: the lock directory is created on first use.

**Example:**
```php
$cache = new MultiTierCache(
    [new ApcuStorage('app:', maxTtl: 60), new FileStorage('/var/cache/app')],
    new NativeSerializer(allowedClasses: [UserDto::class]),
    new GzipCompressor(5),
    defaultTtl: 3600,
    logger: $logger,
    signingKey: $_ENV['EASYCACHE_SIGNING_KEY'],
);
```

---

### get()

Retrieve an item from the cache.

```php
public function get(string $key, mixed $default = null): mixed
```

**Parameters:**
- `$key` (string): Cache key (see [KeyValidator](#keyvalidator))
- `$default` (mixed): Value returned on a miss

**Returns:** The cached value (which may be `null` or `false`) or `$default`

**Throws:** `InvalidArgument` if the key is invalid

Tiers are read in order. An expired copy in a fast tier does not stop the
search: a slower tier may hold a fresher copy (written by another server),
which is then copied to the faster tiers (backfill).

```php
$value = $cache->get('user_123', ['name' => 'Unknown']);
```

---

### set()

Store an item in all tiers.

```php
public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
```

**Parameters:**
- `$key` (string): Cache key
- `$value` (mixed): Any value the serializer supports
- `$ttl`: `null` = default TTL; a positive int or `DateInterval` = lifetime; `0` or a negative value **deletes** the item (PSR-16)

**Returns:** `true` if every tier stored the item

**Throws:** `InvalidArgument` if the key is invalid

```php
$cache->set('user_123', $user, 3600);
$cache->set('config', $config, new DateInterval('P1D'));
```

---

### delete()

```php
public function delete(string $key): bool
```

**Returns:** `true` when the item is gone from every tier, also when it did not exist; `false` only on errors

---

### clear()

```php
public function clear(): bool
```

Clears every tier. Each storage only removes its own entries (prefix /
naming scheme), never unrelated data.

---

### has()

```php
public function has(string $key): bool
```

**Returns:** `true` if a fresh entry exists (also when the cached value is `null`). The value is not deserialized.

---

### getMultiple()

```php
public function getMultiple(iterable $keys, mixed $default = null): array
```

**Returns:** Array of key => value, in the order of `$keys`

**Throws:** `InvalidArgument` if any key is invalid or not a string

Storages implementing `BatchStorageInterface` are read in one round trip per
tier, and lower tiers are only asked for keys that are still missing.

```php
$cache->getMultiple(['key1', 'key2', 'key3'], null);
// ['key1' => 'value1', 'key2' => 'value2', 'key3' => null]
```

---

### setMultiple()

```php
public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
```

All keys are validated before anything is written. A TTL `<= 0` deletes the keys.

---

### deleteMultiple()

```php
public function deleteMultiple(iterable $keys): bool
```

---

### getOrSetSWR()

Get a value or compute it, with stale-while-revalidate, stale-if-error and stampede protection.

```php
public function getOrSetSWR(
    string $key,
    callable $producer,
    null|int|DateInterval $ttl = null,
    int $swrSeconds = 0,
    int $staleIfErrorSeconds = 0,
    array $options = []
): mixed
```

**Parameters:**
- `$producer` (callable): `function(): mixed`, computes a fresh value
- `$ttl`: Fresh lifetime. `null` = default TTL (`0` = never expire). A value `<= 0` computes the value **without caching** it
- `$swrSeconds` (int): How long after expiry the stale value may be served while it is refreshed
- `$staleIfErrorSeconds` (int): How long after expiry the stale value may be served if the producer fails
- `$options['mode']`: `'sync'` (default) or `'defer'`

**Returns:** The cached or computed value

**Throws:**
- `InvalidArgument` if the key or mode is invalid
- Exceptions from `$producer` when there is no usable stale value

**Behaviour:**

| State of the entry | What happens |
|---|---|
| Fresh | Returned immediately |
| Expired, within `swrSeconds` | One process refreshes the entry; processes that cannot get the lock get the stale value without waiting. In `sync` mode the refreshing process returns the new value; in `defer` mode the stale value is returned and the refresh runs after the response is sent (PHP-FPM/LiteSpeed, otherwise `sync`). If the refresh fails, the stale value is returned |
| Expired, only within `staleIfErrorSeconds` | Recomputed. If the producer throws, the stale value is returned instead |
| Missing (or too old) | Computed under a per-key lock (waiting at most `lockTimeout`). After getting the lock the cache is checked again, so concurrent misses run the producer once |

`null` results are cached like any other value.

```php
$data = $cache->getOrSetSWR(
    'expensive_data',
    fn () => computeExpensiveData(),
    ttl: 300,
    swrSeconds: 60,
    staleIfErrorSeconds: 600,
    options: ['mode' => 'defer'],
);
```

---

### remember()

```php
public function remember(string $key, null|int|DateInterval $ttl, callable $producer): mixed
```

Shortcut for `getOrSetSWR($key, $producer, $ttl)` (no stale serving, stampede protection included).

---

### forever()

```php
public function forever(string $key, mixed $value): bool
```

Stores a value without expiry.

---

### tags() / invalidateTags()

```php
public function tags(string|array $tags): TaggedCache
public function invalidateTags(string|array $tags): bool
```

`tags()` returns a [TaggedCache](#taggedcache) view. `invalidateTags()` makes
every entry written with any of the tags unreachable. Invalidated entries are
not deleted; they expire through their TTL or `prune()`.

```php
$cache->tags(['users', 'team-7'])->set('user-42', $user, 3600);
$cache->invalidateTags('team-7');
```

---

### prune()

```php
public function prune(): int
```

**Returns:** Number of removed entries. `FileStorage` and `PdoStorage` delete
expired entries (FileStorage also removes files in an unknown format and
orphaned temp files); APCu and Redis expire entries on their own.

---

### getStats() / resetStats()

```php
public function getStats(): array
public function resetStats(): void
```

Counters of this instance:

```php
[
    'hits' => 120, 'misses' => 8, 'stale_hits' => 3, 'stale_if_error' => 1,
    'writes' => 10, 'deletes' => 2, 'errors' => 0, 'hit_ratio' => 0.9426,
]
```

---

## TaggedCache

A PSR-16 view returned by `MultiTierCache::tags()`. It supports `get`, `set`,
`delete`, `has`, `getMultiple`, `setMultiple`, `deleteMultiple`,
`getOrSetSWR`, `remember` and `forever`. `clear()` invalidates the view's tags.
Entries must be read with the same set of tags they were written with (the
order does not matter). Keys may be up to 217 bytes.

---

## Storage Backends

### StorageInterface

```php
interface StorageInterface
{
    public function get(string $key): ?string;          // null when missing or expired
    public function set(string $key, string $payload, int $ttl): bool; // $ttl 0 = no expiry
    public function delete(string $key): bool;          // true also when the key did not exist
    public function has(string $key): bool;
    public function clear(): bool;                      // only this storage's entries
    public function prune(): int;                       // number of removed entries
}
```

### BatchStorageInterface

Optional, implemented by all built-in storages:

```php
interface BatchStorageInterface extends StorageInterface
{
    public function getMany(array $keys): array;              // found payloads indexed by key
    public function setMany(array $payloads, int $ttl): bool;
    public function deleteMany(array $keys): bool;
}
```

All built-in storages implement `Psr\Log\LoggerAwareInterface` and report
errors through PSR-3 instead of `error_log()`.

---

### FileStorage

```php
public function __construct(
    string $path,
    string $ext = '.cache',
    int $shards = 2,
    int $fileMode = 0640,
    int $dirMode = 0770,
    ?ClockInterface $clock = null,
    ?LoggerInterface $logger = null
)
```

**Parameters:**
- `$path`: Cache directory (created if missing). Must not be empty or `/`
- `$ext`: File extension, letters/digits/`.`/`_`/`-` only
- `$shards`: Sub-directory levels 0-3
- `$fileMode` / `$dirMode`: Exact permissions (umask is not applied)

**Throws:** `\InvalidArgumentException` for an invalid path or extension; `\RuntimeException` if the directory cannot be created or is not writable

**Notes:**
- File names are the xxh128 hash of the key; layout `path/a1/b2/<hash>.cache` with 2 shards
- Each file starts with `ECF1` + the expiry timestamp: `get()` ignores (and removes) expired files, `prune()` deletes them
- Atomic writes: exclusive temp file (`fopen('x')`, never follows symlinks) + `rename()`
- Directories are created on write only
- `clear()` only deletes files that match the storage's naming scheme inside shard directories and never follows symlinks

---

### ApcuStorage

```php
public function __construct(string $prefix = 'ec:', int $maxTtl = 0, ?LoggerInterface $logger = null)
public static function isAvailable(): bool
```

- `$prefix`: Required, non-empty (`clear()` deletes every key with it)
- `$maxTtl`: Cap for the TTL of entries in this tier (0 = no cap). APCu is local to one server; a short cap lets other servers' updates become visible quickly

**Throws:** `\RuntimeException` if APCu is missing or disabled (`apc.enable_cli=1` is needed in CLI); `\InvalidArgumentException` for an empty prefix

---

### RedisStorage

```php
public function __construct(object $redisClient, string $prefix = 'ec:', ?LoggerInterface $logger = null)
```

- `$redisClient`: `\Redis` (phpredis) or `\Predis\ClientInterface`
- `$prefix`: Required, non-empty

**Throws:** `\InvalidArgumentException` for an unsupported client or an empty prefix

**Notes:**
- `SETEX` for TTLs, `MGET` for batch reads, pipelines for batch writes
- `clear()` uses non-blocking `SCAN` with the escaped prefix (also for Predis) and handles client-level prefixes (`Redis::OPT_PREFIX`, Predis `prefix` option)
- `prune()` returns 0 (Redis expires keys itself)

---

### PdoStorage

```php
public function __construct(
    PDO $pdo,
    string $table = 'easycache',
    ?ClockInterface $clock = null,
    ?LoggerInterface $logger = null
)
public function ensureTable(): void
public function upgradeSchema(): void
```

- `$table`: `[A-Za-z_][A-Za-z0-9_]*`, optionally `schema.table`; anything else throws `\InvalidArgumentException`
- The connection's attributes (error mode...) are not changed; exception and silent error modes both work
- Payloads are bound as binary (`PDO::PARAM_LOB`)
- Keys longer than 64 bytes are stored as `':' . sha256` so they fit the key column
- `ensureTable()` creates the table if needed:

| Driver | Key | Payload | Expiry |
|---|---|---|---|
| SQLite | `TEXT` | `BLOB` | `INTEGER` |
| MySQL/MariaDB | `VARBINARY(64)` (case-sensitive) | `LONGBLOB` | `BIGINT` |
| PostgreSQL | `VARCHAR(64)` | `BYTEA` | `BIGINT` |

- `upgradeSchema()` migrates MySQL tables created by EasyCache < 3.1 (`BLOB`, case-insensitive key)
- `prune()` deletes expired rows and returns their number

---

## Locks

### LockInterface / LockProviderInterface

```php
interface LockInterface
{
    public function acquire(bool $blocking = true, ?float $timeout = null): bool;
    public function release(): void;
}

interface LockProviderInterface
{
    public function create(string $key): LockInterface;
}
```

### FileLockProvider

```php
public function __construct(?string $directory = null, int $mode = 0770)
```

`flock()` based locks (one machine). Without a directory, a per-user
directory in the system temp dir is used: it is created with mode `0700` and
only used when it is owned by the current user and not writable by others.
If the directory is unusable, locks cannot be acquired and values are
computed (and still cached) without locking.

### RedisLockProvider

```php
public function __construct(object $redis, string $prefix = 'ec:lock:', int $ttlMs = 30000)
```

Distributed locks (`SET key token NX PX ttl`, released with a compare-and-delete
Lua script) for several servers. The TTL protects against crashed holders;
a blocking `acquire()` waits at most the TTL.

### NullLockProvider

Disables locking (every lock is acquired immediately).

### Util\Lock

```php
public function __construct(string $path, bool $removeOnRelease = true)
public function acquire(bool $blocking = true, ?float $timeout = null): bool
public function release(): void
public function isAcquired(): bool
```

The file lock used by `FileLockProvider`. It refuses symlinked lock files,
supports a timeout and removes the lock file on release (race free: after
locking it checks that the locked file is still the one at the path).

---

## Serializers

### SerializerInterface

```php
interface SerializerInterface
{
    public function serialize(mixed $value): string;
    public function deserialize(string $payload): mixed; // must throw on invalid input
    public function name(): string;                      // 1-255 bytes, stored with each entry
}
```

### NativeSerializer

```php
public function __construct(bool|array $allowedClasses = true, int $maxDepth = 0)
```

- `$allowedClasses`: `true` (any class), `false` (no objects) or a list of class names. Other classes become `__PHP_Incomplete_Class`
- `$maxDepth`: Maximum nesting depth (0 = PHP default)
- Throws `\UnexpectedValueException` for invalid payloads (a stored `false` is still returned as `false`)
- Name: `php`

> Unserializing attacker-controlled data can execute code. Restrict
> `$allowedClasses` and/or use a signing key.

### JsonSerializer

```php
public function __construct(int $depth = 512)
```

- Never creates objects: objects come back as associative arrays
- Floats keep their type (`1.0` stays a float)
- Throws `\JsonException` on invalid data
- Name: `json`

---

## Compressors

### CompressorInterface

```php
interface CompressorInterface
{
    public function compress(string $data): string;
    public function decompress(string $data): string;
    public function name(): string; // stored with each entry; "none" = not compressed
}
```

The compressor name is stored with every entry. Entries compressed with
`gzip` or `zstd` stay readable after switching to another compressor;
entries written with a custom compressor need that compressor to be read
(otherwise they are misses).

### NullCompressor

No compression. Name: `none`.

### GzipCompressor

```php
public function __construct(int $level = 3, int $maxDecompressedSize = 64 * 1024 * 1024)
```

- `$level`: 0-9 (clamped)
- `$maxDecompressedSize`: Refuse to inflate more than this many bytes (0 = unlimited)
- Requires ext-zlib. Name: `gzip`

### ZstdCompressor

```php
public function __construct(int $level = 3, int $maxDecompressedSize = 64 * 1024 * 1024)
```

- `$level`: 1-22 (clamped)
- Requires ext-zstd. Name: `zstd`

---

## Clocks

Both implement `Psr\Clock\ClockInterface`.

- `SystemClock`: the system time (default)
- `FrozenClock`: for tests: `new FrozenClock()`, `FrozenClock::at($timestamp)`, `->advance(int $seconds)`, `->set(DateTimeImmutable $now)`

Pass the same clock to `MultiTierCache` and to `FileStorage`/`PdoStorage`
to control expiry in tests without `sleep()`.

---

## Utilities

### KeyValidator

```php
public static function assert(string $key): void
public static function normalize(mixed $key): string // for keys from iterables; ints are accepted
```

**Rules:**
- 1 to 255 bytes (`KeyValidator::MAX_LENGTH`)
- Printable ASCII only (`!` to `~`): no whitespace, no control characters, no non-ASCII bytes
- None of the reserved characters `{}()/\@:` (`KeyValidator::RESERVED_CHARACTERS`)

```php
KeyValidator::assert('user_123');          // OK
KeyValidator::assert('user-3f2a-77b2');    // OK
KeyValidator::assert('user:123');          // throws
KeyValidator::assert("user\n");            // throws
```

---

## Exceptions

### InvalidArgument

```php
final class InvalidArgument extends \InvalidArgumentException
    implements Psr\SimpleCache\InvalidArgumentException
```

Thrown for invalid keys, tags and SWR modes.

---

## Laravel Integration

### Container and facade

```php
app('easycache');                                    // MultiTierCache singleton
app(\Iprodev\EasyCache\Cache\MultiTierCache::class); // same instance
EasyCache::remember('stats', 300, fn () => $this->computeStats());
```

The facade exposes every public `MultiTierCache` method.

### Artisan commands

```bash
php artisan easycache:prune   # delete expired entries
php artisan easycache:clear   # delete every entry
```

### Configuration

`php artisan vendor:publish --tag=easycache-config` publishes
`config/easycache.php`. Main options (all configurable through `EASYCACHE_*`
environment variables, see the README):

```php
return [
    'drivers' => ['file'],              // apcu, redis, file, pdo (fastest first)
    'skip_unavailable_drivers' => true, // log and skip drivers that fail to initialise
    'default_ttl' => 600,
    'sign' => true,                     // sign entries...
    'signing_key' => null,              // ...with this key, or one derived from APP_KEY
    'lock' => ['driver' => 'file', 'path' => storage_path('framework/cache/easycache-locks'), 'timeout' => 30],
    'apcu' => ['prefix' => 'ec:', 'max_ttl' => 60],
    'redis' => ['client' => 'phpredis', 'host' => '127.0.0.1', 'port' => 6379, 'prefix' => 'ec:'],
    'file' => ['path' => storage_path('framework/cache/easycache'), 'ext' => '.cache', 'shards' => 2],
    'pdo' => ['dsn' => '', 'table' => 'easycache', 'auto_create' => true], // '' = SQLite in storage/
    'serializer' => ['driver' => 'php', 'allowed_classes' => true],
    'compressor' => ['driver' => 'none', 'level' => 3],
];
```

---

## Entry Format

```
"EC03" | flags (1 byte, bit 0 = signed) | expires (uint64 BE, 0 = never)
| swr (uint32 BE) | sei (uint32 BE)
| serializer name length (1) + name | compressor name length (1) + name
| [HMAC-SHA256 of header + body (32 bytes), if signed]
| body = compress(serialize(value))
```

The header is parsed with `unpack()`; only the body goes through the
configured serializer, and only after the signature (if any) was verified.
Entries in any other format are treated as misses.

---

## Error Handling

Storage, serialization and compression failures are logged (PSR-3) and
counted in `getStats()['errors']`; the operation then returns `false`, the
default value or a miss instead of throwing.

**Exceptions that ARE thrown:**
- `InvalidArgument`: invalid keys, tags or options
- `\InvalidArgumentException` / `\RuntimeException`: construction errors (missing extensions, invalid paths, short signing key...)
- Exceptions from your producer in `getOrSetSWR()`/`remember()` when no stale value can be served

---

For more examples, see [EXAMPLES.md](EXAMPLES.md).

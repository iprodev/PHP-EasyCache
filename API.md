# API Reference

Complete API documentation for PHP EasyCache v3.

## Table of Contents

- [MultiTierCache](#multitiercache)
- [Storage Backends](#storage-backends)
- [Serializers](#serializers)
- [Compressors](#compressors)
- [Utilities](#utilities)
- [Exceptions](#exceptions)

---

## MultiTierCache

The main cache class implementing PSR-16 CacheInterface.

### Constructor

```php
public function __construct(
    array $tiers,
    ?SerializerInterface $serializer = null,
    ?CompressorInterface $compressor = null,
    int $defaultTtl = 3600,
    ?LoggerInterface $logger = null,
    ?string $lockPath = null
)
```

**Parameters:**
- `$tiers` (array): Array of StorageInterface instances, ordered from fastest to slowest
- `$serializer` (SerializerInterface|null): Serializer instance (default: NativeSerializer)
- `$compressor` (CompressorInterface|null): Compressor instance (default: NullCompressor)
- `$defaultTtl` (int): Default TTL in seconds (default: 3600)
- `$logger` (LoggerInterface|null): PSR-3 logger instance
- `$lockPath` (string|null): Directory for lock files (default: sys_get_temp_dir()/ec-locks)

**Throws:**
- `\InvalidArgumentException` if `$tiers` is empty

**Example:**
```php
$cache = new MultiTierCache(
    [new ApcuStorage(), new FileStorage('/cache')],
    new NativeSerializer(),
    new GzipCompressor(5),
    3600,
    $logger,
    '/var/lock/cache'
);
```

---

### get()

Retrieve an item from the cache.

```php
public function get(string $key, mixed $default = null): mixed
```

**Parameters:**
- `$key` (string): Cache key (max 64 chars, alphanumeric + `_` `.`)
- `$default` (mixed): Default value if key doesn't exist

**Returns:** Cached value or `$default`

**Throws:**
- `InvalidArgument` if key is invalid

**Example:**
```php
$value = $cache->get('user_123', ['name' => 'Unknown']);
```

---

### set()

Store an item in the cache.

```php
public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
```

**Parameters:**
- `$key` (string): Cache key
- `$value` (mixed): Value to cache (any serializable type)
- `$ttl` (int|DateInterval|null): Time to live. null = use default, 0 = forever

**Returns:** `true` on success, `false` on failure

**Throws:**
- `InvalidArgument` if key is invalid

**Example:**
```php
$cache->set('user_123', $user, 3600);
$cache->set('config', $config, new DateInterval('P1D'));
$cache->set('permanent', $data, 0);
```

---

### delete()

Remove an item from the cache.

```php
public function delete(string $key): bool
```

**Parameters:**
- `$key` (string): Cache key

**Returns:** `true` on success

**Throws:**
- `InvalidArgument` if key is invalid

**Example:**
```php
$cache->delete('user_123');
```

---

### clear()

Clear all items from the cache.

```php
public function clear(): bool
```

**Returns:** `true` on success

**Example:**
```php
$cache->clear();
```

---

### has()

Check if an item exists in the cache.

```php
public function has(string $key): bool
```

**Parameters:**
- `$key` (string): Cache key

**Returns:** `true` if exists and not expired

**Throws:**
- `InvalidArgument` if key is invalid

**Example:**
```php
if ($cache->has('user_123')) {
    echo "User is cached";
}
```

---

### getMultiple()

Retrieve multiple items from the cache.

```php
public function getMultiple(iterable $keys, mixed $default = null): iterable
```

**Parameters:**
- `$keys` (iterable): List of cache keys
- `$default` (mixed): Default value for missing keys

**Returns:** Associative array of key => value

**Throws:**
- `InvalidArgument` if keys is not iterable

**Example:**
```php
$results = $cache->getMultiple(['key1', 'key2', 'key3'], null);
// ['key1' => 'value1', 'key2' => 'value2', 'key3' => null]
```

---

### setMultiple()

Store multiple items in the cache.

```php
public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
```

**Parameters:**
- `$values` (iterable): Associative array of key => value
- `$ttl` (int|DateInterval|null): Time to live

**Returns:** `true` if all succeed

**Throws:**
- `InvalidArgument` if values is not iterable

**Example:**
```php
$cache->setMultiple([
    'key1' => 'value1',
    'key2' => 'value2',
], 3600);
```

---

### deleteMultiple()

Remove multiple items from the cache.

```php
public function deleteMultiple(iterable $keys): bool
```

**Parameters:**
- `$keys` (iterable): List of cache keys

**Returns:** `true` if all succeed

**Throws:**
- `InvalidArgument` if keys is not iterable

**Example:**
```php
$cache->deleteMultiple(['key1', 'key2', 'key3']);
```

---

### getOrSetSWR()

Get or set with Stale-While-Revalidate support.

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
- `$key` (string): Cache key
- `$producer` (callable): Function to produce fresh value: `function(): mixed`
- `$ttl` (int|DateInterval|null): Fresh data TTL
- `$swrSeconds` (int): Stale-while-revalidate window in seconds
- `$staleIfErrorSeconds` (int): Stale-if-error window in seconds
- `$options` (array): Options array
  - `mode` (string): 'sync' or 'defer' (default: 'sync')

**Returns:** Cached or fresh value

**Throws:**
- `InvalidArgument` if key is invalid
- Re-throws exceptions from `$producer` on cache miss

**Example:**
```php
$data = $cache->getOrSetSWR(
    'expensive_data',
    fn() => computeExpensiveData(),
    300,     // Fresh for 5 minutes
    60,      // Serve stale for 1 minute
    600,     // Serve stale for 10 minutes if error
    ['mode' => 'defer']
);
```

**Behavior:**
1. **Cache hit (fresh):** Returns cached value immediately
2. **Cache hit (stale, within SWR):** Returns stale value, triggers background refresh
3. **Cache miss:** Acquires lock, calls producer, caches result
4. **Producer error with stale data:** Returns stale if within staleIfError window

---

### prune()

Remove expired items from storage backends.

```php
public function prune(): int
```

**Returns:** Number of items pruned

**Note:** Only works with backends that support pruning (PdoStorage). File/APCu/Redis handle expiration automatically.

**Example:**
```php
$pruned = $cache->prune();
echo "Removed {$pruned} expired items";
```

---

## Storage Backends

### StorageInterface

All storage backends must implement this interface.

```php
interface StorageInterface
{
    public function get(string $key): ?string;
    public function set(string $key, string $payload, int $ttl): bool;
    public function delete(string $key): bool;
    public function has(string $key): bool;
    public function clear(): bool;
    public function prune(): int;
}
```

---

### FileStorage

File-based cache storage with directory sharding.

#### Constructor

```php
public function __construct(
    string $path,
    string $ext = '.cache',
    int $shards = 2
)
```

**Parameters:**
- `$path` (string): Cache directory path
- `$ext` (string): File extension (default: '.cache')
- `$shards` (int): Sharding level 0-3 (default: 2)

**Throws:**
- `\RuntimeException` if directory cannot be created or is not writable

**Example:**
```php
$storage = new FileStorage('/var/cache/app', '.cache', 2);
```

#### Methods

All methods from `StorageInterface`.

**Notes:**
- Uses atomic writes (temp file + rename)
- Uses flock() for read locking
- Supports 0-3 levels of directory sharding
- `prune()` returns 0 (expiration handled by MultiTierCache)

---

### ApcuStorage

APCu memory cache storage.

#### Constructor

```php
public function __construct(string $prefix = 'ec:')
```

**Parameters:**
- `$prefix` (string): Key prefix for namespacing (default: 'ec:')

**Throws:**
- `\RuntimeException` if APCu extension is not available or not enabled

**Example:**
```php
$storage = new ApcuStorage('myapp:');
```

#### Methods

All methods from `StorageInterface`.

**Notes:**
- `clear()` only removes keys with the configured prefix
- Extremely fast (in-memory)
- Shared between PHP-FPM workers
- `prune()` returns 0 (APCu handles expiration)

---

### RedisStorage

Redis cache storage supporting phpredis and predis.

#### Constructor

```php
public function __construct($redisClient, string $prefix = 'ec:')
```

**Parameters:**
- `$redisClient` (Redis|Predis\ClientInterface): Redis client instance
- `$prefix` (string): Key prefix (default: 'ec:')

**Throws:**
- `\InvalidArgumentException` if client is invalid type

**Example:**
```php
// phpredis
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$storage = new RedisStorage($redis, 'app:');

// predis
$redis = new Predis\Client('tcp://127.0.0.1:6379');
$storage = new RedisStorage($redis, 'app:');
```

#### Methods

All methods from `StorageInterface`.

**Notes:**
- Uses SETEX for TTL support
- `clear()` uses SCAN to remove only prefixed keys
- `prune()` returns 0 (Redis handles expiration)

---

### PdoStorage

PDO database cache storage.

#### Constructor

```php
public function __construct(PDO $pdo, string $table = 'easycache')
```

**Parameters:**
- `$pdo` (PDO): PDO instance
- `$table` (string): Table name (default: 'easycache')

**Example:**
```php
$pdo = new PDO('mysql:host=localhost;dbname=cache', 'user', 'pass');
$storage = new PdoStorage($pdo, 'cache_items');
```

#### ensureTable()

Create the cache table if it doesn't exist.

```php
public function ensureTable(): void
```

**Throws:**
- `\RuntimeException` if table creation fails

**Example:**
```php
$storage->ensureTable();
```

**Table Schema:**
- `k` (VARCHAR(64) PRIMARY KEY): Cache key
- `payload` (BLOB): Serialized and compressed data
- `expires_at` (BIGINT): Expiration timestamp

#### Methods

All methods from `StorageInterface`.

**Notes:**
- `prune()` deletes expired rows and returns count
- Supports MySQL, PostgreSQL, SQLite
- Uses UPSERT for atomic set operations

---

## Serializers

### SerializerInterface

```php
interface SerializerInterface
{
    public function serialize(mixed $value): string;
    public function deserialize(string $payload): mixed;
    public function name(): string;
}
```

---

### NativeSerializer

PHP native serialization.

```php
$serializer = new NativeSerializer();
```

**Features:**
- Supports objects and complex types
- Name: 'php'
- Uses `serialize()` and `unserialize()`

---

### JsonSerializer

JSON serialization.

```php
$serializer = new JsonSerializer();
```

**Features:**
- Portable across languages
- Name: 'json'
- Uses `JSON_THROW_ON_ERROR` flag
- Preserves Unicode characters

**Throws:**
- `\JsonException` on invalid data

---

## Compressors

### CompressorInterface

```php
interface CompressorInterface
{
    public function compress(string $data): string;
    public function decompress(string $data): string;
    public function name(): string;
}
```

---

### NullCompressor

No compression.

```php
$compressor = new NullCompressor();
```

**Features:**
- Pass-through (no compression)
- Name: 'none'

---

### GzipCompressor

Gzip compression.

```php
public function __construct(int $level = 3)
```

**Parameters:**
- `$level` (int): Compression level 0-9 (default: 3)

**Example:**
```php
$compressor = new GzipCompressor(5);
```

**Features:**
- Name: 'gzip'
- Requires ext-zlib
- Level 0 = no compression, 9 = maximum

**Throws:**
- `\RuntimeException` if zlib not available or compression fails

---

### ZstdCompressor

Zstandard compression.

```php
public function __construct(int $level = 3)
```

**Parameters:**
- `$level` (int): Compression level (default: 3)

**Example:**
```php
$compressor = new ZstdCompressor(3);
```

**Features:**
- Name: 'zstd'
- Requires ext-zstd
- Faster than Gzip

**Throws:**
- `\RuntimeException` if zstd not available or compression fails

---

## Utilities

### KeyValidator

Validates cache keys according to PSR-16.

#### assert()

```php
public static function assert(string $key): void
```

**Parameters:**
- `$key` (string): Key to validate

**Throws:**
- `InvalidArgument` if key is invalid

**Rules:**
- Max 64 characters
- Only: `A-Za-z0-9_.`
- No: `{}()/\@:`

**Example:**
```php
KeyValidator::assert('user_123');     // OK
KeyValidator::assert('user:123');     // Throws
KeyValidator::assert('user/profile'); // Throws
```

---

### Lock

File-based locking mechanism.

#### Constructor

```php
public function __construct(string $path)
```

**Parameters:**
- `$path` (string): Lock file path

---

#### acquire()

```php
public function acquire(bool $blocking = true): bool
```

**Parameters:**
- `$blocking` (bool): Wait for lock (true) or fail immediately (false)

**Returns:** `true` if lock acquired

**Example:**
```php
$lock = new Lock('/tmp/my.lock');

// Blocking
if ($lock->acquire(true)) {
    // Do work
    $lock->release();
}

// Non-blocking
if ($lock->acquire(false)) {
    // Do work
} else {
    echo "Could not acquire lock";
}
```

---

#### release()

```php
public function release(): void
```

**Note:** Automatically called in destructor.

---

## Exceptions

### InvalidArgument

Thrown for invalid cache keys or arguments.

```php
class InvalidArgument extends \InvalidArgumentException 
    implements Psr\SimpleCache\InvalidArgumentException
```

**Example:**
```php
try {
    $cache->set('invalid:key', 'value');
} catch (InvalidArgument $e) {
    echo "Invalid key: " . $e->getMessage();
}
```

---

## Laravel Integration

### Facade

```php
use EasyCache;

EasyCache::set('key', 'value', 3600);
$value = EasyCache::get('key');
```

All methods from `MultiTierCache` are available.

### Configuration

Published to `config/easycache.php`:

```php
return [
    'drivers' => ['apcu', 'redis', 'file'],
    'default_ttl' => 600,
    'lock_path' => storage_path('framework/cache/easycache-locks'),
    
    'serializer' => [
        'driver' => 'php', // php|json
    ],
    
    'compressor' => [
        'driver' => 'gzip', // none|gzip|zstd
        'level' => 3,
    ],
    
    // ... backend-specific options
];
```

### Service Provider

Auto-discovered. Registers:
- `EasyCache` facade
- `easycache` singleton in container

---

## Type Reference

### Common Types

```php
// TTL types
int           // seconds
DateInterval  // e.g., new DateInterval('PT1H')
null          // use default TTL

// Supported value types
string
int
float
bool
null
array
object (with NativeSerializer)
```

---

## Error Handling

All operations are wrapped in try-catch internally. Failures are logged if logger is provided and return false/null instead of throwing.

**Exceptions that ARE thrown:**
- `InvalidArgument`: Invalid keys or parameters
- `\RuntimeException`: Construction errors (missing extensions, invalid paths)
- `\JsonException`: JSON serialization errors (JsonSerializer)

**Exceptions that are NOT thrown (logged instead):**
- Storage failures (read/write errors)
- Compression/decompression errors
- Lock acquisition failures

---

For more examples, see [EXAMPLES.md](EXAMPLES.md).

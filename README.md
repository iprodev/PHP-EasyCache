# PHP EasyCache v4 — PSR‑16 Multi‑Backend Cache with SWR

**EasyCache** is a pragmatic, batteries‑included cache library that implements the **PSR‑16 Simple Cache** interface and adds production‑grade features on top:

- 🚀 Multi‑tier storage: **APCu, Redis, File, and PDO (MySQL/PostgreSQL/SQLite)**
- 🔒 **Atomic writes** for file storage, **stampede protection** with per‑key locks (file or Redis)
- ⚡ **Full SWR** (*stale‑while‑revalidate* + *stale‑if‑error*)
- 🛡️ **Secure by default**: no objects are unserialized unless you allow them, and then only from HMAC‑signed entries
- 🔧 **Pluggable Serializer & Compressor** (PHP/JSON + None/Gzip/Zstd or your own)
- 🔄 Automatic **backfill** between tiers (e.g., a Redis hit is written back to APCu)
- 📦 **Batch operations** (`MGET`, `IN (...)`, `apcu_fetch([...])`), **tags**, `remember()`, `forever()`, stats
- 🔢 **Atomic counters** (`increment()`, `decrement()`) and `add()`
- 🧩 **PSR‑6 adapter** and a **Laravel cache store** (`Cache::store('easycache')`, tags, locks, rate limiting)
- 🎯 First‑class **Laravel** integration: Service Provider, Facade and artisan commands
- ✅ Tested against File, SQLite, MySQL, PostgreSQL, Redis (phpredis + Predis) and APCu

> Version: **v4.0.0** — Requires **PHP 8.3+** and `psr/simple-cache` `^3`.
> Upgrading from 3.x? Read [MIGRATION.md](MIGRATION.md).

📖 **Documentation in other languages:**
- [فارسی (Persian)](README_FA.md)
- [کوردی (Kurdish Sorani)](README_KU.md)

---

## 📦 Installation

```bash
composer require iprodev/php-easycache
```

### Optional dependencies

- `ext-apcu` for the APCu tier
- `ext-redis` or `predis/predis:^2.0` for the Redis tier
- `ext-zlib` for Gzip compression
- `ext-zstd` for Zstd compression

---

## 🚀 Quick Start (PSR‑16)

```php
use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Storage\ApcuStorage;
use Iprodev\EasyCache\Storage\RedisStorage;
use Iprodev\EasyCache\Storage\FileStorage;
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Compression\GzipCompressor;

// Tiers: APCu -> Redis -> File
$apcu  = new ApcuStorage('ec:');

// phpredis (example); predis is also supported
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redisStore = new RedisStorage($redis, 'ec:');

$file  = new FileStorage('/var/cache/myapp');

$cache = new MultiTierCache(
    [$apcu, $redisStore, $file],
    new NativeSerializer(),
    new GzipCompressor(3),
    defaultTtl: 600,
    signingKey: getenv('EASYCACHE_SIGNING_KEY') ?: null, // secret >= 16 bytes, recommended
);

// PSR-16 API
$cache->set('user_42', ['id'=>42, 'name'=>'Ava'], 300);
$data = $cache->get('user_42'); // ['id'=>42, 'name'=>'Ava']
```

---

## 🎯 Core Features

### 1. Multi-Tier Caching

Organize your cache in tiers from fastest to slowest. The library automatically:
- Reads from the fastest available tier
- Writes to all tiers
- Backfills faster tiers when data is found in slower tiers
- Skips expired copies in fast tiers when a slower tier holds a fresher copy

> ⚠️ **Several servers?** APCu (and any other server‑local tier) is not shared:
> a `set()`/`delete()` on server A does not touch server B's APCu. Cap the TTL
> of the local tier (`new ApcuStorage('app:', maxTtl: 60)`) so updates from
> other servers become visible quickly, and use `RedisLockProvider` for
> stampede protection across servers.

```php
// Example: Memory -> Redis -> Database
$cache = new MultiTierCache(
    [
        new ApcuStorage('app:'),      // Fast: In-memory
        new RedisStorage($redis),     // Medium: Network cache
        new PdoStorage($pdo, 'cache') // Slow: Database fallback
    ],
    new NativeSerializer(),
    new NullCompressor(),
    3600 // 1 hour default TTL
);
```

### 2. Stale-While-Revalidate (SWR)

When data expires but is still inside the SWR window, **stale data is served** while the entry is refreshed by a single process. This prevents cache stampedes and keeps response times low.

```php
$result = $cache->getOrSetSWR(
    key: 'posts_homepage',
    producer: function () {
        // Expensive API call or database query
        return fetchPostsFromDatabase();
    },
    ttl: 300,                  // 5 minutes of fresh data
    swrSeconds: 120,           // Serve stale up to 2 minutes after expiry
    staleIfErrorSeconds: 600,  // If refresh fails, serve stale up to 10 minutes
    mode: RefreshMode::Defer   // Refresh after the response is sent
);
```

`RefreshMode` is `Iprodev\EasyCache\Cache\RefreshMode`.

**How it works:**
1. **Fresh** entry → returned immediately.
2. **Expired, within `swrSeconds`** → one process refreshes it, the others get the stale value without waiting.
   - `RefreshMode::Sync` (default): the refreshing process returns the **new** value.
   - `RefreshMode::Defer`: the stale value is returned and the refresh runs after the response is sent
     (`fastcgi_finish_request()` / LiteSpeed; elsewhere it falls back to `Sync`).
   - If the refresh fails, the stale value is served.
3. **Expired, within `staleIfErrorSeconds` only** → the value is recomputed; the stale value is served
   **only if the producer throws**.
4. **Missing** → computed under a per‑key lock, so concurrent requests run the producer only once.

A `null` TTL uses the default TTL (`0` = never expire); a TTL `<= 0` computes the value without caching it.
`null` results are cached like any other value.

### 3. Security: Safe Defaults and Signed Entries

Anyone who can write to a cache backend (a Redis without password, a shared
cache directory, a shared database table...) could otherwise plant entries,
and unserializing a planted PHP object can execute code. EasyCache 4 is safe
by default:

- `NativeSerializer` caches scalars and arrays only. Objects of classes you
  did not allow are rejected when written and ignored when read.
- To cache objects, list their classes in `allowedClasses`. A serializer that
  can create objects then **requires a signing key**: every entry carries an
  HMAC‑SHA256 signature, and unsigned or modified entries are rejected
  **before** they are deserialized.

```php
$cache = new MultiTierCache(
    [$redisStore],
    new NativeSerializer(allowedClasses: [UserDto::class]), // true = any class
    signingKey: $_ENV['EASYCACHE_SIGNING_KEY'],             // >= 16 random bytes
);
```

If nobody but your application can write to any tier, you may pass
`allowUnsignedObjects: true` instead of a signing key.

### 4. More Helpers

```php
// Compute once, cache for 10 minutes
$user = $cache->remember('user-42', 600, fn () => $repo->find(42));

// No expiry
$cache->forever('feature-flags', $flags);

// Tags: invalidate groups of entries at once
$cache->tags(['users'])->set('user-42', $user);
$cache->tags(['users'])->get('user-42');
$cache->invalidateTags('users');

// Hit/miss counters of this instance
$cache->getStats(); // ['hits' => .., 'misses' => .., 'hit_ratio' => 0.93, ...]

// Atomic counters: a new counter lives for the given TTL (null = default, 0 = forever),
// later increments keep its expiry
$attempts = $cache->increment('login-attempts.' . sha1($ip), 1, 60);
$cache->decrement('stock.42');

// Store only if missing (atomic)
if ($cache->add('job.42.started', time(), 300)) {
    // this process won
}
```

`increment()`, `decrement()` and `add()` are atomic through the lock provider:
file locks serialize the processes of one machine, `RedisLockProvider` all
servers. They return `false` if the lock cannot be acquired within the lock
timeout (or the value is not an integer).

### 5. Pluggable Serialization

Choose the serializer that fits your needs:

```php
// PHP Native Serializer: scalars and arrays by default; objects only for listed
// classes (or true for any class), together with a signing key
use Iprodev\EasyCache\Serialization\NativeSerializer;
$cache = new MultiTierCache([$storage], new NativeSerializer());
$cache = new MultiTierCache([$storage], new NativeSerializer([Money::class]), signingKey: $key);

// JSON Serializer (portable, never creates objects; objects come back as arrays)
use Iprodev\EasyCache\Serialization\JsonSerializer;
$cache = new MultiTierCache([$storage], new JsonSerializer());
```

### 6. Pluggable Compression

Save memory and disk space:

```php
// No compression
use Iprodev\EasyCache\Compression\NullCompressor;
$cache = new MultiTierCache([$storage], $serializer, new NullCompressor());

// Gzip compression (balanced)
use Iprodev\EasyCache\Compression\GzipCompressor;
$cache = new MultiTierCache([$storage], $serializer, new GzipCompressor(5));

// Zstd compression (fastest)
use Iprodev\EasyCache\Compression\ZstdCompressor;
$cache = new MultiTierCache([$storage], $serializer, new ZstdCompressor(3));
```

The compressor name is stored with each entry, so switching compressors keeps
existing gzip/zstd entries readable. Decompression is capped (64 MB by default)
to protect against decompression bombs. Custom compressors implement
`CompressorInterface` with a unique `name()`.

---

## 💾 Storage Backends

### APCu Storage
Fast in-memory cache, perfect as the first tier.

```php
use Iprodev\EasyCache\Storage\ApcuStorage;

$storage = new ApcuStorage(
    prefix: 'myapp:', // Namespace your keys (required, must not be empty)
    maxTtl: 60        // Optional cap for entries in this server-local tier
);
```

**Features:**
- Lightning-fast memory access
- Shared between PHP-FPM workers (but not between servers)
- Automatic expiration
- Safe clear() that only deletes prefixed keys
- In CLI, requires `apc.enable_cli=1`

### Redis Storage
Network-based cache with persistence options.

```php
use Iprodev\EasyCache\Storage\RedisStorage;

// Using phpredis
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$storage = new RedisStorage($redis, 'myapp:');

// Using predis
$redis = new Predis\Client('tcp://127.0.0.1:6379');
$storage = new RedisStorage($redis, 'myapp:');
```

**Features:**
- Works with phpredis or predis (including client-level key prefixes)
- TTL support with SETEX, batch reads with MGET and pipelined writes
- Safe clear(): non-blocking `SCAN` over the (escaped) prefix only; an empty prefix is rejected
- Automatic expiration

### File Storage
Reliable disk-based cache with sharding.

```php
use Iprodev\EasyCache\Storage\FileStorage;

$storage = new FileStorage(
    path: '/var/cache/myapp',  // Cache directory (keep it outside the web root)
    ext: '.cache',             // File extension
    shards: 2,                 // Directory sharding level (0-3)
    fileMode: 0640,            // File permissions (independent of umask)
    dirMode: 0770              // Directory permissions
);
```

**Features:**
- Atomic writes (temp file + rename), symlink-safe temp files
- Expiry stored in each file: expired files are ignored and removed by `prune()`
- Directory sharding for performance; directories are only created on write
- `clear()` only deletes files matching the storage's own naming scheme and never follows symlinks

**Directory Sharding Example:**
```
With shards=2, key "user_123" (xxh128 hash: a1b2c3d4...):
/var/cache/myapp/a1/b2/a1b2c3d4....cache
```

Expired files are not deleted automatically: schedule `prune()` (or
`vendor/bin/easycache-prune /var/cache/myapp`, or `php artisan easycache:prune`).

### PDO Storage
SQL database cache for shared environments.

```php
use Iprodev\EasyCache\Storage\PdoStorage;

$pdo = new PDO('mysql:host=localhost;dbname=cache', 'user', 'pass');
$storage = new PdoStorage($pdo, 'easycache');

// Create table (run once during setup)
$storage->ensureTable();

// Tables created by EasyCache < 3.1 on MySQL: lift the 64KB limit and make keys case-sensitive
$storage->upgradeSchema();
```

**Supported databases:**
- SQLite: `sqlite:/path/to/cache.db`
- MySQL: `mysql:host=localhost;dbname=cache`
- PostgreSQL: `pgsql:host=localhost;dbname=cache`

**Features:**
- TTL support with expiration check
- Prune expired items with `prune()`
- UPSERT support (`ON CONFLICT` / `ON DUPLICATE KEY`), binary-safe payloads (`LONGBLOB`/`BYTEA`/`BLOB`)
- Works with any PDO error mode and never changes the connection's settings
- Keys longer than 64 bytes are stored hashed, so they fit the key column
- Indexed queries and batch reads with `IN (...)`

---

## 🎨 Complete Examples

### Example 1: Simple File Cache

```php
use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Storage\FileStorage;
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Compression\NullCompressor;

$storage = new FileStorage('/var/cache/myapp');
$cache = new MultiTierCache([$storage], new NativeSerializer(), new NullCompressor());

// Set with 1 hour TTL
$cache->set('user_profile', [
    'id' => 123,
    'name' => 'John Doe',
    'email' => 'john@example.com'
], 3600);

// Get
$profile = $cache->get('user_profile');

// Check existence
if ($cache->has('user_profile')) {
    echo "Profile is cached!";
}

// Delete
$cache->delete('user_profile');
```

### Example 2: Multi-Tier with Backfill

```php
// Setup: APCu (fast) -> Redis (medium) -> File (slow)
$apcu = new ApcuStorage('app:');
$redis = new RedisStorage($redisClient, 'app:');
$file = new FileStorage('/var/cache/app');

$cache = new MultiTierCache([$apcu, $redis, $file]);

// First request: Cache miss, data fetched and stored in all tiers
$data = $cache->get('expensive_data');

// APCu crashes and restarts...

// Next request: Data found in Redis, automatically backfilled to APCu
$data = $cache->get('expensive_data'); // Fast!
```

### Example 3: SWR for API Responses

```php
use Psr\Log\LoggerInterface;

$cache = new MultiTierCache(
    [$apcu, $redis],
    new NativeSerializer(),
    new GzipCompressor(5),
    600, // 10 min default TTL
    $logger // Optional PSR-3 logger
);

$posts = $cache->getOrSetSWR(
    key: 'api_posts_latest',
    producer: function() use ($apiClient) {
        // This is expensive
        return $apiClient->fetchPosts();
    },
    ttl: 300,          // Fresh for 5 minutes
    swrSeconds: 60,    // Serve stale for 1 minute while refreshing
    staleIfErrorSeconds: 300, // Serve stale for 5 minutes if API fails
    mode: RefreshMode::Defer // Refresh after response sent
);
```

### Example 4: Batch Operations

```php
// Set multiple
$cache->setMultiple([
    'key1' => 'value1',
    'key2' => 'value2',
    'key3' => 'value3',
], 3600);

// Get multiple with default
$results = $cache->getMultiple(['key1', 'key2', 'missing'], 'default');
// ['key1' => 'value1', 'key2' => 'value2', 'missing' => 'default']

// Delete multiple
$cache->deleteMultiple(['key1', 'key2']);
```

### Example 5: DateInterval TTL

```php
// Cache for 2 hours
$cache->set('key', 'value', new DateInterval('PT2H'));

// Cache for 1 day
$cache->set('key', 'value', new DateInterval('P1D'));

// Cache for 30 days
$cache->set('key', 'value', new DateInterval('P30D'));
```

### Example 6: Custom Logger Integration

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('cache');
$logger->pushHandler(new StreamHandler('/var/log/cache.log', Logger::WARNING));

$cache = new MultiTierCache(
    [$storage],
    new NativeSerializer(),
    new NullCompressor(),
    3600,
    $logger // Will log warnings and errors
);
```

### Example 7: Scheduled Cleanup

```php
// Run this in a cron job or scheduled task
$pruned = $cache->prune();
echo "Pruned {$pruned} expired items";

// File and PDO storages delete expired entries here;
// APCu and Redis expire entries on their own
```

---

## 🎭 Laravel Integration

### Setup

1. **Install the package:**
```bash
composer require iprodev/php-easycache
```

2. **Publish configuration:**
```bash
php artisan vendor:publish --tag=easycache-config
```

3. **Configure in `.env` (or `config/easycache.php`):**
```env
EASYCACHE_DRIVERS=apcu,redis,file   # default: file
EASYCACHE_DEFAULT_TTL=600
EASYCACHE_REDIS_HOST=127.0.0.1
EASYCACHE_REDIS_PASSWORD=secret
EASYCACHE_LOCK_DRIVER=redis         # file|redis|none
EASYCACHE_SERIALIZER=php            # php|json
EASYCACHE_COMPRESSOR=gzip           # none|gzip|zstd
```

- Entries are **signed** with a key derived from `APP_KEY` (set `EASYCACHE_SIGNING_KEY`
  to use your own, `EASYCACHE_SIGN=false` to disable). With signing disabled, the php
  serializer must not allow objects (`serializer.allowed_classes = false`) unless
  `EASYCACHE_ALLOW_UNSIGNED_OBJECTS=true`.
- Drivers that cannot be initialised (extension missing, Redis down...) are skipped with
  a warning in the log (`EASYCACHE_SKIP_UNAVAILABLE=false` to fail instead).
- The cache is available as `app('easycache')`, the `EasyCache` facade, or by type-hinting
  `Iprodev\EasyCache\Cache\MultiTierCache`.

### Using the Facade

```php
use EasyCache;

// Simple operations
EasyCache::set('user_settings', $settings, 3600);
$settings = EasyCache::get('user_settings');

// SWR pattern
$data = EasyCache::getOrSetSWR(
    'dashboard_stats',
    fn() => $this->computeStats(),
    300,  // Fresh for 5 min
    60,   // SWR for 1 min
    300   // Stale-if-error for 5 min
);

// Batch operations
EasyCache::setMultiple([
    'key1' => 'value1',
    'key2' => 'value2',
]);
```

### Laravel Cache Store

The provider registers an `easycache` cache driver, so EasyCache works with
Laravel's own cache API, tags, atomic locks and the rate limiter:

```php
Cache::store('easycache')->remember('users', 600, fn () => User::all());
Cache::store('easycache')->tags(['users'])->flush();
Cache::store('easycache')->lock('reports', 30)->get(fn () => $this->buildReports());
RateLimiter::for('api', fn () => Limit::perMinute(60)); // with CACHE_STORE=easycache
```

The store is available without configuration. To make it the default store,
set `CACHE_STORE=easycache` (or add `'easycache' => ['driver' => 'easycache']`
to `config/cache.php` with your own `prefix`). Laravel keys may contain any
character: characters that EasyCache keys do not allow are encoded.

### Artisan Commands

```bash
# Delete expired entries (file and PDO tiers)
php artisan easycache:prune

# Delete every EasyCache entry
php artisan easycache:clear
```

```php
// Schedule pruning (routes/console.php or app/Console/Kernel.php)
Schedule::command('easycache:prune')->hourly();
```

---

## 🔑 Key Rules (PSR‑16)

- **Allowed characters:** printable ASCII (`!` to `~`) except the reserved characters
- **Reserved characters (not allowed):** `{ } ( ) / \ @ :`
- **Not allowed:** whitespace, control characters (including newlines) and non-ASCII bytes
- **Max length:** 255 bytes

```php
// Valid keys
$cache->set('user_123', $data);
$cache->set('posts.latest', $data);
$cache->set('user-3f2a9c1e-77b2-4f3b-9a51-1c2d3e4f5a6b', $data);

// Invalid keys (will throw Psr\SimpleCache\InvalidArgumentException)
$cache->set('user:123', $data);    // Contains :
$cache->set('user/123', $data);    // Contains /
$cache->set('user 123', $data);    // Contains whitespace
$cache->set(str_repeat('x', 256), $data); // Too long
```

---

## 🧪 Testing & Quality Assurance

### Running Tests

```bash
# Run all tests
composer test

# Run with coverage
composer test -- --coverage-html coverage

# Run one test class
./vendor/bin/phpunit --filter SwrTest
```

Integration tests for Redis, MySQL and PostgreSQL run when the corresponding
environment variables are set (see `phpunit.xml.dist`), e.g.:

```bash
EASYCACHE_TEST_REDIS_HOST=127.0.0.1 \
EASYCACHE_TEST_MYSQL_DSN="mysql:host=127.0.0.1;dbname=easycache" EASYCACHE_TEST_MYSQL_USER=root \
composer test
```

APCu tests need `apc.enable_cli=1`.

### Static Analysis

```bash
# Run PHPStan
composer stan

# Check coding standards
composer cs

# Fix coding standards automatically
composer cs:fix
```

### Test Coverage

The test suite covers:
- ✅ A shared storage contract run against File, SQLite, MySQL, PostgreSQL, phpredis, Predis and APCu
- ✅ Multi-tier caching, backfill and fall-through to fresher tiers
- ✅ SWR and stale-if-error semantics (with a frozen PSR-20 clock, no `sleep()`)
- ✅ Security: signed/tampered/unsigned entries, object injection attempts
- ✅ PSR-16 compliance (data types, invalid keys for every method)
- ✅ Stampede protection with concurrent processes
- ✅ Serializers, compressors, key validation, file and Redis locks
- ✅ Laravel service provider, facade and artisan commands; the CLI prune tool

---

## 🔧 Advanced Configuration

### Locks

By default, locks are files in a private per-user directory in the system temp
dir (mode `0700`). They only coordinate processes on one machine.

```php
use Iprodev\EasyCache\Lock\FileLockProvider;
use Iprodev\EasyCache\Lock\RedisLockProvider;

// Custom lock directory
$cache = new MultiTierCache([$storage], lockProvider: new FileLockProvider('/var/lib/myapp/locks'));

// Distributed locks for several servers (SET NX PX + safe release)
$cache = new MultiTierCache(
    [$apcu, $redisStore],
    lockProvider: new RedisLockProvider($redis, 'myapp:lock:', ttlMs: 30000),
    lockTimeout: 10.0, // seconds to wait before computing without the lock
);
```

### Clock

All time calculations go through a PSR-20 clock, which makes expiry testable:

```php
use Iprodev\EasyCache\Clock\FrozenClock;

$clock = new FrozenClock();
$cache = new MultiTierCache([new FileStorage($dir, clock: $clock)], clock: $clock);
$cache->set('k', 'v', 60);
$clock->advance(61);
$cache->get('k'); // null
```

### File Storage Sharding Levels

```php
// No sharding: /cache/hash.cache
$storage = new FileStorage('/cache', '.cache', 0);

// 1 level: /cache/a1/hash.cache
$storage = new FileStorage('/cache', '.cache', 1);

// 2 levels: /cache/a1/b2/hash.cache (recommended)
$storage = new FileStorage('/cache', '.cache', 2);

// 3 levels: /cache/a1/b2/c3/hash.cache
$storage = new FileStorage('/cache', '.cache', 3);
```

### Environment Variables (Laravel)

| Variable | Default | Description |
|---|---|---|
| `EASYCACHE_DRIVERS` | `file` | Comma separated tiers: `apcu`, `redis`, `file`, `pdo` |
| `EASYCACHE_SKIP_UNAVAILABLE` | `true` | Skip drivers that cannot be initialised |
| `EASYCACHE_DEFAULT_TTL` | `600` | Default TTL in seconds (`0` = never expire) |
| `EASYCACHE_SIGN` / `EASYCACHE_SIGNING_KEY` | `true` / derived from `APP_KEY` | Entry signing |
| `EASYCACHE_ALLOW_UNSIGNED_OBJECTS` | `false` | Allow object caching with signing disabled (only if nobody else can write to the backends) |
| `EASYCACHE_LOCK_DRIVER` | `file` | `file`, `redis` or `none` |
| `EASYCACHE_LOCK_PATH` / `EASYCACHE_LOCK_TIMEOUT` | storage path / `30` | File lock directory, lock wait in seconds |
| `EASYCACHE_APCU_PREFIX` / `EASYCACHE_APCU_MAX_TTL` | `ec:` / `60` | APCu prefix and TTL cap |
| `EASYCACHE_REDIS_CLIENT` | `phpredis` | `phpredis` or `predis` |
| `EASYCACHE_REDIS_HOST` / `_PORT` / `_PASSWORD` / `_DB` / `_PREFIX` / `_SCHEME` / `_TIMEOUT` | | Redis connection |
| `EASYCACHE_FILE_PATH` | `storage/framework/cache/easycache` | File tier directory |
| `EASYCACHE_PDO_DSN` / `_USER` / `_PASSWORD` / `_TABLE` | SQLite in storage | PDO tier |
| `EASYCACHE_SERIALIZER` | `php` | `php` or `json` |
| `EASYCACHE_COMPRESSOR` / `EASYCACHE_COMPRESSION_LEVEL` | `none` / `3` | `none`, `gzip` or `zstd` |

---

## 🚨 Error Handling

All storage operations are wrapped with proper error handling. Failures are logged (if logger is provided) and don't crash your application:

```php
use Monolog\Logger;

$logger = new Logger('cache');
$cache = new MultiTierCache([$storage], $serializer, $compressor, 3600, $logger);

// If storage fails, operation returns false but doesn't throw
$result = $cache->set('key', 'value');
if (!$result) {
    // Check logs for details
    echo "Cache set failed, check logs";
}
```

**Logged Events:**
- Storage read/write failures (storages report through the same PSR-3 logger; they never write to `error_log()`)
- Rejected entries (unsigned or invalid signature)
- Compression/decompression errors
- Lock acquisition failures
- SWR refresh errors and stale-if-error fallbacks
- Serialization errors

`getStats()` exposes the number of errors next to hits and misses.

---

## 🏗️ Builder

Instead of constructor arguments you can use the fluent builder:

```php
$cache = MultiTierCache::builder()
    ->tier(new ApcuStorage('app:', maxTtl: 60))
    ->tier(new RedisStorage($redis, 'app:'))
    ->serializer(new NativeSerializer([UserDto::class]))
    ->signingKey($_ENV['EASYCACHE_SIGNING_KEY'])
    ->compressor(new GzipCompressor(3))
    ->defaultTtl(600)
    ->lockProvider(new RedisLockProvider($redis))
    ->logger($logger)
    ->build();
```

## 🧩 PSR‑6

For libraries that expect a PSR‑6 pool (`composer require psr/cache`):

```php
use Iprodev\EasyCache\Psr6\CacheItemPool;

$pool = new CacheItemPool($cache);

$item = $pool->getItem('user-42');
if (!$item->isHit()) {
    $item->set($repo->find(42))->expiresAfter(600);
    $pool->save($item);
}

// Deferred saves are written in one batch per expiry
$pool->saveDeferred($pool->getItem('a')->set(1));
$pool->saveDeferred($pool->getItem('b')->set(2));
$pool->commit();
```

## 🔄 Upgrading

See [MIGRATION.md](MIGRATION.md) for upgrading from 3.x (and older versions).
The v2 compatibility wrapper `Iprodev\EasyCache\EasyCache` was removed in 4.0.

---

## 📝 Best Practices

1. **Use multi-tier wisely:** APCu → Redis → File/PDO, with a short `maxTtl` on server-local tiers
2. **Set appropriate TTLs:** Balance freshness vs. performance
3. **Use SWR for expensive operations:** Prevent cache stampedes
4. **Sign entries in production:** pass a `signingKey`, and restrict `NativeSerializer` classes
5. **Protect your backends:** Redis with a password, cache directories outside the web root
6. **Monitor cache hit rates:** `getStats()` and the PSR-3 log
7. **Schedule pruning:** File and PDO storages need `prune()` / `easycache:prune`
8. **Use compression for large data:** GzipCompressor or ZstdCompressor
9. **Use RedisLockProvider** when several servers share a tier

---

## 🤝 Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

### Development Setup

```bash
git clone https://github.com/iprodev/php-easycache.git
cd php-easycache
composer install
composer test
```

---

## 📄 License

MIT © iprodev

---

## 🔗 Links

- [Documentation](https://github.com/iprodev/php-easycache/wiki)
- [API Reference - English](API.md)
- [API Reference - فارسی](API_FA.md)
- [API Reference - کوردی](API_KU.md)
- [Examples - English](EXAMPLES.md)
- [Examples - فارسی](EXAMPLES_FA.md)
- [Examples - کوردی](EXAMPLES_KU.md)
- [Changelog](CHANGELOG.md)
- [Security Policy](SECURITY.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)

---

## 💬 Support

- 📧 Email: support@iprodev.com
- 🔐 Security issues: see [SECURITY.md](SECURITY.md) (please do not open public issues)
- 🐛 Issues: [GitHub Issues](https://github.com/iprodev/php-easycache/issues)
- 💡 Discussions: [GitHub Discussions](https://github.com/iprodev/php-easycache/discussions)

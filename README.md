# PHP EasyCache v3 — PSR‑16 Multi‑Backend Cache with SWR

**EasyCache** is a pragmatic, batteries‑included cache library that implements the **PSR‑16 Simple Cache** interface and adds production‑grade features on top:

- 🚀 Multi‑tier storage: **APCu, Redis, File, and PDO (MySQL/PostgreSQL/SQLite)**
- 🔒 **Atomic writes** and **read locks** for file storage
- ⚡ **Full SWR** (*stale‑while‑revalidate* + *stale‑if‑error*), with non‑blocking per‑key locks
- 🔧 **Pluggable Serializer & Compressor** (PHP/JSON + None/Gzip/Zstd)
- 🔄 Automatic **backfill** between tiers (e.g., a Redis hit is written back to APCu)
- 🎯 First‑class **Laravel** integration via a Service Provider & Facade
- ✅ **Comprehensive test coverage** with PHPUnit
- 🛡️ **Improved error handling** with detailed logging support

> Version: **v3.0.1** — Requires **PHP 8.1+** and `psr/simple-cache:^3`.

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

$file  = new FileStorage(__DIR__.'/cache');

$cache = new MultiTierCache(
    [$apcu, $redisStore, $file],
    new NativeSerializer(),
    new GzipCompressor(3),
    defaultTtl: 600
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

When data expires but is still inside the SWR window, **stale data is served instantly** while a **refresh happens in the background**. This prevents cache stampedes and ensures fast response times.

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
    options: ['mode' => 'defer'] // Defer refresh until after response
);
```

**How it works:**
1. If data is fresh, it's returned immediately
2. If data is expired but within SWR window:
   - Stale data is returned instantly
   - Background refresh is triggered (non-blocking)
3. If refresh fails, stale data continues to be served (within staleIfError window)

### 3. Pluggable Serialization

Choose the serializer that fits your needs:

```php
// PHP Native Serializer (supports objects)
use Iprodev\EasyCache\Serialization\NativeSerializer;
$cache = new MultiTierCache([$storage], new NativeSerializer());

// JSON Serializer (portable, faster for simple data)
use Iprodev\EasyCache\Serialization\JsonSerializer;
$cache = new MultiTierCache([$storage], new JsonSerializer());
```

### 4. Pluggable Compression

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

---

## 💾 Storage Backends

### APCu Storage
Fast in-memory cache, perfect as the first tier.

```php
use Iprodev\EasyCache\Storage\ApcuStorage;

$storage = new ApcuStorage(
    prefix: 'myapp:' // Namespace your keys
);
```

**Features:**
- Lightning-fast memory access
- Shared between PHP-FPM workers
- Automatic expiration
- Safe clear() that only deletes prefixed keys

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
- Works with phpredis or predis
- TTL support with SETEX
- Safe clear() with prefix scanning
- Automatic expiration

### File Storage
Reliable disk-based cache with sharding.

```php
use Iprodev\EasyCache\Storage\FileStorage;

$storage = new FileStorage(
    path: '/var/cache/myapp',  // Cache directory
    ext: '.cache',              // File extension
    shards: 2                   // Directory sharding level (0-3)
);
```

**Features:**
- Atomic writes (temp file + rename)
- Read locks with flock()
- Directory sharding for performance
- Configurable file extension

**Directory Sharding Example:**
```
With shards=2, key "user_123" (hash: a1b2c3d4):
/var/cache/myapp/a1/b2/a1b2c3d4.cache
```

### PDO Storage
SQL database cache for shared environments.

```php
use Iprodev\EasyCache\Storage\PdoStorage;

$pdo = new PDO('mysql:host=localhost;dbname=cache', 'user', 'pass');
$storage = new PdoStorage($pdo, 'easycache');

// Create table (run once during setup)
$storage->ensureTable();
```

**Supported databases:**
- SQLite: `sqlite:/path/to/cache.db`
- MySQL: `mysql:host=localhost;dbname=cache`
- PostgreSQL: `pgsql:host=localhost;dbname=cache`

**Features:**
- TTL support with expiration check
- Prune expired items with `prune()`
- UPSERT support (INSERT ... ON CONFLICT)
- Indexed queries for performance

---

## 🎨 Complete Examples

### Example 1: Simple File Cache

```php
use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Storage\FileStorage;
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Compression\NullCompressor;

$storage = new FileStorage(__DIR__ . '/cache');
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
    options: ['mode' => 'defer'] // Refresh after response sent
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

// For PDO storage, this removes expired rows
// For File/APCu/Redis, expiration is automatic
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

3. **Configure in `config/easycache.php`:**
```php
return [
    'drivers' => ['apcu', 'redis', 'file'],
    'default_ttl' => 600,
    
    'serializer' => [
        'driver' => 'php', // php|json
    ],
    
    'compressor' => [
        'driver' => 'gzip', // none|gzip|zstd
        'level' => 5,
    ],
    
    'redis' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', null),
        'database' => env('REDIS_CACHE_DB', 1),
    ],
];
```

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

### Artisan Commands

The package includes a prune command:

```bash
# Prune expired cache items
php artisan easycache:prune

# Add to your scheduler (app/Console/Kernel.php)
$schedule->command('easycache:prune')->daily();
```

---

## 🔑 Key Rules (PSR‑16)

- **Allowed characters:** `[A-Za-z0-9_.]`
- **Max length:** 64 characters
- **Reserved characters (not allowed):** `{ } ( ) / \ @ :`

```php
// Valid keys
$cache->set('user_123', $data);
$cache->set('posts.latest', $data);
$cache->set('CamelCase', $data);

// Invalid keys (will throw InvalidArgument exception)
$cache->set('user:123', $data);    // Contains :
$cache->set('user/123', $data);    // Contains /
$cache->set('user@123', $data);    // Contains @
$cache->set(str_repeat('x', 65), $data); // Too long
```

---

## 🧪 Testing & Quality Assurance

### Running Tests

```bash
# Run all tests
composer test

# Run with coverage
composer test -- --coverage-html coverage

# Run specific test suite
./vendor/bin/phpunit --testsuite "Storage Tests"
```

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

The library includes comprehensive tests for:
- ✅ All storage backends (File, APCu, Redis, PDO)
- ✅ Multi-tier caching with backfill
- ✅ SWR functionality
- ✅ Serializers (Native, JSON)
- ✅ Compressors (Null, Gzip, Zstd)
- ✅ Key validation
- ✅ Lock mechanism
- ✅ Edge cases and error handling

---

## 🔧 Advanced Configuration

### Custom Lock Path

```php
$cache = new MultiTierCache(
    [$storage],
    $serializer,
    $compressor,
    3600,
    $logger,
    '/custom/lock/path' // Custom lock directory
);
```

### File Storage Sharding Levels

```php
// No sharding: /cache/md5hash.cache
$storage = new FileStorage('/cache', '.cache', 0);

// 1 level: /cache/a1/md5hash.cache
$storage = new FileStorage('/cache', '.cache', 1);

// 2 levels: /cache/a1/b2/md5hash.cache (recommended)
$storage = new FileStorage('/cache', '.cache', 2);

// 3 levels: /cache/a1/b2/c3/md5hash.cache
$storage = new FileStorage('/cache', '.cache', 3);
```

### Environment Variables (Laravel)

```env
# .env file
EASYCACHE_DRIVER=redis
EASYCACHE_REDIS_HOST=127.0.0.1
EASYCACHE_REDIS_PORT=6379
EASYCACHE_REDIS_PASSWORD=secret
EASYCACHE_REDIS_DB=1
EASYCACHE_DEFAULT_TTL=600
```

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
- Storage read/write failures
- Compression/decompression errors
- Lock acquisition failures
- SWR refresh errors
- Serialization errors

---

## 🔄 Backwards Compatibility

For projects upgrading from v2, use the BC wrapper:

```php
use Iprodev\EasyCache\EasyCache;

$cache = new EasyCache([
    'cache_path' => __DIR__ . '/cache',
    'cache_extension' => '.cache',
    'cache_time' => 3600,
    'directory_shards' => 2,
]);

// Works like v2
$cache->set('key', 'value');
$value = $cache->get('key');
```

---

## 📝 Best Practices

1. **Use multi-tier wisely:** APCu → Redis → File/PDO
2. **Set appropriate TTLs:** Balance freshness vs. performance
3. **Use SWR for expensive operations:** Prevent cache stampedes
4. **Monitor cache hit rates:** Use logging to track performance
5. **Schedule pruning:** For PDO storage, prune regularly
6. **Use compression for large data:** GzipCompressor or ZstdCompressor
7. **Namespace your keys:** Use prefixes to avoid collisions
8. **Test error scenarios:** Ensure your app handles cache failures gracefully

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
- 🐛 Issues: [GitHub Issues](https://github.com/iprodev/php-easycache/issues)
- 💡 Discussions: [GitHub Discussions](https://github.com/iprodev/php-easycache/discussions)

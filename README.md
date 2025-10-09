# PHP EasyCache v3 — PSR‑16 Multi‑Backend Cache with SWR

**EasyCache** is a pragmatic, batteries‑included cache library that implements the **PSR‑16 Simple Cache** interface and adds production‑grade features on top:

- Multi‑tier storage: **APCu, Redis, File, and PDO (MySQL/PostgreSQL/SQLite)**
- **Atomic writes** and **read locks** for file storage
- **Full SWR** (*stale‑while‑revalidate* + *stale‑if‑error*), with non‑blocking per‑key locks
- **Pluggable Serializer & Compressor** (PHP/JSON + None/Gzip/Zstd)
- Automatic **backfill** between tiers (e.g., a Redis hit is written back to APCu)
- First‑class **Laravel** integration via a Service Provider & Facade

> Version: **v3.0.0** — Requires **PHP 8.1+** and `psr/simple-cache:^3`.

---

## Installation

```bash
composer require iprodev/php-easycache
```

### Optional suggestions

- `ext-apcu` for the APCu tier
- `ext-redis` or `predis/predis:^2.0` for the Redis tier
- `ext-zlib` for Gzip and `ext-zstd` for Zstd compression

---

## Quick start (PSR‑16)

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

## Full SWR (stale‑while‑revalidate + stale‑if‑error)

When the item expires but is still inside the SWR window, **stale data is served** while a **refresh** runs in the background. If the refresh fails, stale data is served for `staleIfErrorSeconds`.

```php
$result = $cache->getOrSetSWR(
    key: 'posts_homepage',
    producer: function () {
        // An expensive API call
        return fetchPosts();
    },
    ttl: 300,                  // 5 minutes of fresh data
    swrSeconds: 120,           // serve stale up to 2 minutes after expiry and refresh
    staleIfErrorSeconds: 600,  // if refresh fails, serve stale up to 10 minutes
    options: ['mode' => 'defer'] // uses fastcgi_finish_request() if available
);
```

**Notes**

- The library uses **non‑blocking per‑key locks**, so only one worker refreshes a given key.
- With `mode="defer"`, if `fastcgi_finish_request()` exists, refresh happens after the response is flushed. Otherwise, refresh happens inline but still uses a non‑blocking lock.

---

## Pluggable Serializer & Compressor

```php
use Iprodev\EasyCache\Serialization\JsonSerializer;
use Iprodev\EasyCache\Compression\ZstdCompressor;

$cache = new MultiTierCache([$apcu, $file], new JsonSerializer(), new ZstdCompressor(5));
```

- Serializers: `NativeSerializer` (PHP `serialize`) and `JsonSerializer` (JSON).  
- Compressors: `NullCompressor`, `GzipCompressor`, `ZstdCompressor`.

The record header stores the **serializer and compressor names** to keep older cache files readable when you change configuration.

---

## Storage backends

### APCu
- Super‑fast local memory cache for FPM/CLI processes with shared memory.

### Redis
- Works with **phpredis** or **predis** clients.  
- `RedisStorage` uses `SETEX` for TTL > 0; keys are prefixed (default `ec:`).  
- `clear()` removes keys with the configured prefix only (safe for shared DBs).

### File
- Directory sharding (configurable) to keep directory sizes reasonable.  
- **Atomic write**: write to a temp file + `rename()`; **read lock** with `LOCK_SH`.  
- Recommended permissions: create the directory outside web root, owned by your app user, with **0770** (or stricter).

### PDO (MySQL/PostgreSQL/SQLite)
- `PdoStorage` stores payload and expiry in a single table; call `ensureTable()` once to create the schema.  
- `prune()` deletes expired rows.  
- Example DSNs:
  - SQLite: `sqlite:/path/to/cache.sqlite`
  - MySQL:  `mysql:host=127.0.0.1;dbname=cache;charset=utf8mb4`
  - PgSQL:  `pgsql:host=127.0.0.1;port=5432;dbname=cache`

---

## Laravel integration

1) Publish configuration:
```bash
php artisan vendor:publish --tag=easycache-config
```

2) Use the Facade:
```php
use EasyCache;

EasyCache::set('x', 'y', 120);
$val = EasyCache::getOrSetSWR('profile_42', fn() => fetchProfile(42), 300, 60, 300);
```

3) Example `config/easycache.php`:
```php
return [
  'drivers' => ['apcu', 'redis', 'file'],
  'default_ttl' => 600,
  'serializer' => ['driver' => 'php'],      // php|json
  'compressor' => ['driver' => 'gzip', 'level' => 3], // none|gzip|zstd
  // Redis/APCu/File/PDO options ...
];
```

> Auto‑discovery is enabled; the `EasyCacheServiceProvider` is registered automatically.

---

## Configuration reference

- **Drivers order** (`drivers`): read/write order. Typical high‑traffic order is `APCu → Redis → File` or `APCu → Redis → PDO`.
- **default_ttl**: default TTL in seconds for `set()`/`get()` operations (PSR‑16).  
- **lock_path**: directory for per‑key locks when needed.  
- **serializer**: `php` or `json`.  
- **compressor**: `none`, `gzip`, or `zstd` with a `level`.  
- Backend‑specific options exist under `apcu`, `redis`, `file`, and `pdo` sections.

---

## Backwards compatibility

For projects upgrading from v2, a BC wrapper class **`Iprodev\EasyCache\EasyCache`** is provided. It uses a single File storage tier and accepts the familiar constructor options (`cache_path`, `cache_extension`, `cache_time`, `directory_shards`).

---

## Key rules (PSR‑16)

- Allowed characters: `[A-Za-z0-9_.]`  
- Max length: **64**  
- Reserved characters (not allowed): `{ } ( ) / \ @ :`

---

## Testing & QA

- Unit tests: PHPUnit
- Static analysis: PHPStan
- Coding standards: PHPCS (PSR‑12)

Run:
```bash
composer test
composer stan
composer cs
```

---

## License

MIT © iprodev

# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.1.0] - Unreleased

Security fixes, bug fixes and new features. Cache entries written by 3.0.x
use an older format and are treated as misses after the upgrade (they expire
or are removed by `prune()`). See [MIGRATION.md](MIGRATION.md).

### Security
- 🔒 **No more `unserialize()` of entry headers** - entries use a binary header (`EC03`) parsed with `unpack()`. Previously the envelope was always unserialized with `allowed_classes => true`, so anyone able to write to a backend could trigger PHP object injection even with `JsonSerializer`.
- 🔒 **Signed entries** - optional HMAC-SHA256 signing (`signingKey`); unsigned or tampered entries are rejected before deserialization. The Laravel integration signs entries with a key derived from `APP_KEY` by default.
- 🔒 `NativeSerializer` accepts `allowedClasses` and `maxDepth`.
- 🔒 File locks moved to a private per-user directory (`0700`, ownership checked) instead of the shared `/tmp/ec-locks`; symlinked lock files are refused; locks have a timeout and are removed on release.
- 🔒 `clear()` can no longer delete unrelated data: Redis/APCu storages require a non-empty prefix and escape glob characters; `FileStorage` only deletes its own files and never follows symlinks.
- 🔒 Cache files are created with explicit permissions (default `0640`, configurable) regardless of umask; temporary files are created exclusively.
- 🔒 `PdoStorage` validates the table name.
- 🔒 Gzip/Zstd decompression is capped (64 MB by default).
- 🔒 Keys ending with a newline were accepted by the key validator.
- 🔒 Redis `clear()` uses `SCAN` for Predis too (no more blocking `KEYS`).

### Fixed
- 🐛 `RedisStorage` threw for every phpredis (`\Redis`) client because of an operator precedence bug.
- 🐛 Stale-if-error served stale data for the whole window without ever calling the producer. The value is now recomputed and the stale value is only returned when the producer throws.
- 🐛 SWR `sync` mode refreshed the entry but still returned the stale value.
- 🐛 `defaultTtl = 0` meant "never expire" for `set()` but "expire immediately" for `getOrSetSWR()`.
- 🐛 An expired entry in a fast tier hid a fresh entry in a slower tier (multi-server setups).
- 🐛 `FileStorage` never expired or pruned files (disk usage grew forever) and `bin/easycache-prune` was a no-op; reads created empty shard directories.
- 🐛 Custom compressors were silently ignored; switching compressor or serializer returned garbage (e.g. `false`) instead of a cache miss.
- 🐛 `delete()` returned `false` for missing keys on PDO/APCu/Redis (and so did `set()` with a TTL <= 0), violating PSR-16.
- 🐛 `null` results of `getOrSetSWR()` were never cached, causing a stampede on every request.
- 🐛 PostgreSQL: payloads were bound as text and truncated at the first NUL byte (every entry contains one), so the PDO backend never returned hits.
- 🐛 MySQL: payloads were limited to 64 KB (`BLOB`) and keys were case-insensitive; new tables use `LONGBLOB` and `VARBINARY(64)`, `upgradeSchema()` migrates old tables.
- 🐛 `PdoStorage` changed the error mode of the application's PDO connection.
- 🐛 When the lock could not be acquired, the computed value was not cached.
- 🐛 SWR revalidation did not re-check whether another process had already refreshed the entry; `defer` mode registered one shutdown function per call.
- 🐛 Laravel: the default PDO DSN (`''`) crashed, missing APCu/unreachable Redis broke the whole application, and the documented `easycache:prune` command did not exist.
- 🐛 `bin/easycache-prune` could not find the autoloader when the package was installed as a dependency.
- 🐛 The `EasyCache` wrapper stored its cache inside `vendor/` by default.

### Added
- ✨ `BatchStorageInterface` (`getMany`/`setMany`/`deleteMany`) implemented by all storages and used by `getMultiple()`/`setMultiple()`/`deleteMultiple()` (MGET/pipelines, `apcu_fetch([...])`, `IN (...)`).
- ✨ `RedisLockProvider` (distributed locks), `FileLockProvider`, `NullLockProvider` and `LockInterface`/`LockProviderInterface`; `lockProvider` and `lockTimeout` options.
- ✨ PSR-20 clock support (`SystemClock`, `FrozenClock`) in the cache and the file/PDO storages.
- ✨ Tags: `tags()`, `invalidateTags()` and `TaggedCache`.
- ✨ `remember()`, `forever()`, `getStats()`/`resetStats()`.
- ✨ `ApcuStorage` `maxTtl` option to cap the TTL of the server-local tier.
- ✨ `PdoStorage::upgradeSchema()`; long keys (> 64 bytes) are stored hashed.
- ✨ Laravel: `easycache:prune` and `easycache:clear` commands, `MultiTierCache` container alias, env-driven configuration, Redis lock driver, `skip_unavailable_drivers`.
- ✨ `bin/easycache-prune` options `--ext` and `--shards`.

### Changed
- ♻️ Keys may contain any printable ASCII character except whitespace and `{}()/\@:` (e.g. `user-123`, UUIDs), up to 255 bytes.
- ♻️ All storages log through PSR-3 (`setLogger()`; the cache hands its logger to its tiers) instead of `error_log()`.
- ♻️ `getOrSetSWR()` with an explicit TTL <= 0 computes the value without caching it.
- ♻️ `getMultiple()` returns an array.
- ♻️ `psr/simple-cache` `^2.0 || ^3.0` and `psr/log` `^1.1 || ^2.0 || ^3.0` are supported; the package provides `psr/simple-cache-implementation`.
- ♻️ Laravel config: drivers default to `file` (`EASYCACHE_DRIVERS`), APCu TTL capped at 60s.
- ♻️ File names use xxh128 instead of md5.
- ♻️ `phpunit.xml` and `phpstan.neon` renamed to `*.dist`; PHPStan 2.1 (level 8) for the library and Larastan for the Laravel integration (`composer stan:laravel`); PHPUnit 10.5 or 11.5.
- ♻️ Laravel is no longer a dev dependency: the Laravel tests run in their own suite (`composer test:laravel`) against Testbench 10/11.

### Tests & tooling
- ✅ Storage contract test-suite run against File, SQLite, MySQL, PostgreSQL, phpredis, Predis and APCu.
- ✅ SWR/stale-if-error, security, tags, PSR-16 compliance, concurrency (multi-process), Laravel and CLI tests.
- ✅ CI with Redis, MySQL and PostgreSQL services, APCu and zstd on PHP 8.1-8.4, a Laravel 12/13 job, code coverage, a lowest-dependencies run and `composer audit`.

### Docs
- 📝 README, API reference and examples updated in English, Persian and Kurdish; new MIGRATION guide and SECURITY policy.
- 📝 Fixed examples that used invalid keys (`:`/`/` from IPs, paths or `user:123`-style names), a racy hand-made lock and a hand-made tag implementation that deleted its own index (`set(..., 0)`).

---

## [3.0.2] - 2025-11-30

### Fixed
- 🐛 **Lock resource handling** - Fixed `TypeError` in `Lock::release()` when called multiple times (e.g., from `__destruct()` after manual release)
- 🔧 **PHPUnit compatibility** - Downgraded PHPUnit from ^11.0 to ^10.0 for PHP 8.1 compatibility
- 🔧 **PHPStan configuration** - Updated deprecated config options (`checkMissingIterableValueType`, `checkGenericClassInNonGenericObjectType`)
- 🔧 **PHPStan type errors** - Fixed comparison type errors in `MultiTierCache::readThrough()` with explicit int casts
- 🔧 **Code style violations** - Fixed PSR-12 violations (trailing whitespace, line length)

### Changed
- ♻️ **MultiTierCache** - Removed `final` keyword to allow extension by `EasyCache` wrapper class
- 📝 **PHPUnit config** - Updated schema to 10.5, disabled `failOnWarning` to prevent CI failures from optional extension warnings
- 📝 **PHPStan config** - Excluded `src/Laravel` folder (requires Laravel dependencies), added Predis ignore pattern for optional dependency

---

## [3.0.1] - 2025-10-18

### Added
- ✅ **Comprehensive test coverage** (80%+ coverage)
  - Unit tests for all storage backends
  - Multi-tier caching tests
  - SWR functionality tests
  - Serialization and compression tests
  - Key validation and lock mechanism tests
- 📚 **Enhanced documentation**
  - Complete API reference (API.md)
  - Practical examples guide (EXAMPLES.md)
  - Contributing guidelines (CONTRIBUTING.md)
  - Real-world use cases and patterns
- 🧪 **Quality assurance tools**
  - PHPUnit configuration
  - Composer scripts for testing and QA
  - PHPStan baseline support

### Improved
- 🛡️ **Error handling across all components**
  - Proper exception handling in storage operations
  - Detailed error logging with PSR-3 support
  - Graceful degradation on failures
  - Better error messages and diagnostics
- 🔒 **ApcuStorage improvements**
  - Safe clear() that only removes prefixed keys
  - Better APCu availability detection
  - Improved error logging
- 📁 **FileStorage improvements**
  - Better directory permission handling
  - Improved error messages
  - More robust file operations
  - Better handling of concurrent access
- 🔴 **RedisStorage improvements**
  - Type checking for Redis client
  - Better connection error handling
  - Improved SCAN implementation
- 💾 **PdoStorage improvements**
  - Better BLOB handling for PostgreSQL
  - Enhanced error messages
  - Improved has() method with expiration check
- 🎯 **MultiTierCache improvements**
  - Better lock directory management
  - Enhanced logging throughout
  - Improved error recovery
  - Better documentation in code

### Fixed
- 🐛 Bug fixes in various storage backends
- 🔧 Fixed potential race conditions
- ✅ Improved validation and error handling
- 📝 Fixed typos and improved code documentation

---

## [3.0.0] - 2025-10-09

### Added
- Full **PSR‑16** implementation with multi‑key operations
- Multi‑backend tiers: **APCu, Redis, File, PDO (MySQL/PostgreSQL/SQLite)**
- **Full SWR**: `getOrSetSWR()` with *stale‑while‑revalidate* and *stale‑if‑error*, non‑blocking per‑key locks, and `defer` mode
- **Pluggable Serializer/Compressor**: `NativeSerializer` & `JsonSerializer`; `Null/Gzip/Zstd`
- **Backfill**: hits from lower tiers are written back to faster tiers
- **Laravel Service Provider** with auto‑discovery, `EasyCache` Facade, and `config/easycache.php`
- Atomic file writes + read locks; directory sharding for file backend

### Changed
- Record header upgraded to **EC02**; serializer and compressor names are stored for forward compatibility
- Minimum PHP version: 8.1+
- PSR SimpleCache version: 3.0

### Fixed
- Eliminated race conditions on file writes with `tmp + rename` and read/write locks

---

## [2.x] - Previous Versions

See legacy documentation for v2.x changes.

---

## Upgrade Guide

### From v2.x to v3.0+

#### Breaking Changes

1. **PHP Version**: Now requires PHP 8.1+
2. **PSR-16**: Updated to PSR SimpleCache 3.0
3. **Constructor**: `EasyCache` constructor changed to accept configuration array
4. **Namespaces**: All classes now under `Iprodev\EasyCache` namespace

#### Migration Steps

**Option 1: Use BC Wrapper (Recommended for quick migration)**

```php
// Old v2 code
use Iprodev\EasyCache\EasyCache;

$cache = new EasyCache([
    'cache_path' => __DIR__ . '/cache',
    'cache_extension' => '.cache',
    'cache_time' => 3600,
    'directory_shards' => 2,
]);

// Works the same way!
$cache->set('key', 'value');
```

**Option 2: Migrate to MultiTierCache**

```php
// New v3 code
use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Storage\FileStorage;
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Compression\NullCompressor;

$storage = new FileStorage(__DIR__ . '/cache', '.cache', 2);
$cache = new MultiTierCache(
    [$storage],
    new NativeSerializer(),
    new NullCompressor(),
    3600
);

// Same API
$cache->set('key', 'value');
```

#### New Features You Can Use

1. **Multi-tier caching**:
```php
$cache = new MultiTierCache([
    new ApcuStorage(),
    new RedisStorage($redis),
    new FileStorage('/cache')
]);
```

2. **SWR pattern**:
```php
$data = $cache->getOrSetSWR(
    'key',
    fn() => expensiveOperation(),
    300,  // TTL
    60,   // SWR
    600   // Stale-if-error
);
```

3. **Compression**:
```php
$cache = new MultiTierCache(
    [$storage],
    new NativeSerializer(),
    new GzipCompressor(5)
);
```

---

## Contributors

Special thanks to all contributors who helped improve PHP EasyCache!

- Error handling improvements
- Test coverage additions
- Documentation enhancements
- Bug fixes and performance optimizations

See [CONTRIBUTORS.md](CONTRIBUTORS.md) for the full list.

---

## Support

- 📖 [Documentation](README.md)
- 📚 [API Reference](API.md)
- 💡 [Examples](EXAMPLES.md)
- 🐛 [Issue Tracker](https://github.com/iprodev/php-easycache/issues)
- 💬 [Discussions](https://github.com/iprodev/php-easycache/discussions)

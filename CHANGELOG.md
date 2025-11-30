# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

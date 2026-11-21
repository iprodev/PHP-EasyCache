# Migration Guide

## 3.x → 4.0

4.0 makes the safe configuration the default and cleans up the API. Cache
entries written by 3.1 stay readable (same entry format).

### Requirements

PHP 8.3+, `psr/simple-cache` 3, `psr/log` 2 or 3. Laravel integration: Laravel 12+.

### Caching objects

`NativeSerializer` no longer allows objects by default. If you cache objects:

```php
// 3.x: any class, unsigned
$cache = new MultiTierCache($tiers, new NativeSerializer());

// 4.0: list the classes and sign the entries
$cache = new MultiTierCache(
    $tiers,
    new NativeSerializer(allowedClasses: [UserDto::class, Money::class]), // or true for any class
    signingKey: $_ENV['EASYCACHE_SIGNING_KEY'], // >= 16 random bytes
);
```

- A serializer that can create objects without a `signingKey` throws an
  `InvalidArgumentException` when the cache is created. If (and only if)
  nobody but your application can write to any cache tier, pass
  `allowUnsignedObjects: true` instead.
- With the default serializer, `set()` of an object returns `false` (and logs
  why); entries containing objects of classes that are not allowed are cache misses.
- Laravel signs entries by default, so the default configuration keeps
  working. With `EASYCACHE_SIGN=false`, either set
  `serializer.allowed_classes` to `false` or `EASYCACHE_ALLOW_UNSIGNED_OBJECTS=true`.

### API changes

| 3.x | 4.0 |
|---|---|
| `getOrSetSWR($k, $fn, $ttl, $swr, $sei, ['mode' => 'defer'])` | `getOrSetSWR($k, $fn, $ttl, $swr, $sei, RefreshMode::Defer)` |
| `new MultiTierCache($tiers, ..., lockPath: '/dir')` | `new MultiTierCache($tiers, ..., lockProvider: new FileLockProvider('/dir'))` or `MultiTierCache::builder()->lockPath('/dir')` |
| Constructor arguments after `$logger` passed by position | Pass them by name (`clock:`, `signingKey:`, `lockProvider:`, `lockTimeout:`), or use `MultiTierCache::builder()` |
| `class MyCache extends MultiTierCache` | `MultiTierCache` is final: wrap it instead of extending it |
| `Iprodev\EasyCache\Util\Lock` | `Iprodev\EasyCache\Lock\FileLock` |
| `Iprodev\EasyCache\EasyCache` (v2 wrapper) | `MultiTierCache` with a `FileStorage` tier |
| `$redisStorage->prune()`, `$apcuStorage->prune()` | Removed (the backends expire entries themselves); `MultiTierCache::prune()` still works |

### Custom storages and serializers

- Storages implement `getMany()`, `setMany()` and `deleteMany()` (formerly the
  optional `BatchStorageInterface`, which no longer exists), and
  `PrunableStorageInterface` if expired entries must be removed explicitly.
- Serializers implement `allowsObjects(): bool`: return `true` if
  `deserialize()` can instantiate objects.


## 3.0.x → 3.1

3.1 fixes several security issues and bugs. Most applications upgrade without
code changes, but please check the points below.

### Cache contents

Entries use a new binary format (`EC03`) and file storage adds a small expiry
header. Entries written by 3.0.x are treated as **cache misses** and recomputed.
Old files are removed by `prune()` / `vendor/bin/easycache-prune`; Redis/APCu
entries expire through their TTL.

### Recommended: sign your entries

```php
$cache = new MultiTierCache($tiers, signingKey: $_ENV['EASYCACHE_SIGNING_KEY']); // >= 16 bytes
```

With a signing key, entries that are not signed with that key are rejected.
In Laravel, signing is enabled by default with a key derived from `APP_KEY`
(`EASYCACHE_SIGN=false` disables it, `EASYCACHE_SIGNING_KEY` sets your own).

If you use `NativeSerializer` and do not need objects (or only a few
classes), restrict it: `new NativeSerializer(allowedClasses: false)` or
`new NativeSerializer(allowedClasses: [MyDto::class])`.

### Behaviour changes

| Area | 3.0.x | 3.1 |
|---|---|---|
| `delete()` of a missing key | `false` on PDO/APCu/Redis | `true` (PSR-16) |
| `set($key, $value, 0)` | deleted the key, often returned `false` | deletes the key, returns `true` |
| `getOrSetSWR()` in the stale-if-error window | returned stale data without calling the producer | calls the producer, returns stale data only if it throws |
| `getOrSetSWR()` `sync` mode in the SWR window | refreshed, returned the **stale** value | returns the **fresh** value (other processes get the stale one) |
| `getOrSetSWR()` with `defaultTtl = 0` and no TTL | expired immediately | never expires (same as `set()`) |
| `getOrSetSWR()` with an explicit TTL `<= 0` | used the default TTL | computes without caching |
| `null` returned by a producer | recomputed every time | cached |
| Keys | `[A-Za-z0-9_.]`, max 64 | printable ASCII except whitespace and `{}()/\@:`, max 255 |
| `RedisStorage`/`ApcuStorage` with prefix `''` | allowed (`clear()` wiped everything) | `InvalidArgumentException` |
| Default lock directory | `/tmp/ec-locks` (shared) | per-user private dir in the temp dir; created lazily |
| `EasyCache` wrapper default path | `vendor/iprodev/php-easycache/cache` | per-user private dir in the temp dir |
| Storage errors | written to `error_log()` | sent to the PSR-3 logger (the cache's logger is handed to its tiers) |
| File permissions | depended on umask (usually `0644`) | `0640` files / `0770` directories, configurable |
| `getMultiple()` return type | `iterable` | `array` |

### PDO on MySQL

Tables created by 3.0.x use `BLOB` (max 64 KB) and a case-insensitive
`VARCHAR` key. Upgrade them once:

```php
$storage->upgradeSchema();
// or: ALTER TABLE easycache MODIFY k VARBINARY(64) NOT NULL, MODIFY payload LONGBLOB NOT NULL;
```

PostgreSQL tables need no change (the bug was in how payloads were bound).

### Laravel

- Drivers now come from `EASYCACHE_DRIVERS` (default `file`). Set
  `EASYCACHE_DRIVERS=apcu,redis,file` to keep the old default.
- If you published `config/easycache.php`, re-publish it or merge the new
  keys (`sign`, `signing_key`, `lock`, `skip_unavailable_drivers`, `apcu.max_ttl`).
- New commands: `php artisan easycache:prune`, `php artisan easycache:clear`.

### Custom storages, serializers and compressors

- `StorageInterface` is unchanged. `delete()` should return `true` for keys
  that do not exist. Implement `BatchStorageInterface` for batch operations.
- Custom compressors are now actually used; their `name()` is stored with each entry.
- `SerializerInterface::deserialize()` must throw on invalid input.

## 2.x → 3.x

Use the BC wrapper `Iprodev\EasyCache\EasyCache` or switch to `MultiTierCache` for multi-tier + SWR.

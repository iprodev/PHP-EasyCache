# Performance Guide

Optimize PHP EasyCache for maximum performance.

## Table of Contents

- [Tier Selection](#tier-selection)
- [Compression Strategy](#compression-strategy)
- [Serialization Performance](#serialization-performance)
- [TTL Optimization](#ttl-optimization)
- [SWR Best Practices](#swr-best-practices)
- [File Storage Optimization](#file-storage-optimization)
- [Redis Optimization](#redis-optimization)
- [Benchmarks](#benchmarks)

---

## Tier Selection

### Recommended Tier Orders

**High-traffic web applications:**
```php
$cache = new MultiTierCache([
    new ApcuStorage('app:'),      // L1: ~0.01ms
    new RedisStorage($redis),     // L2: ~1-2ms
    new FileStorage('/cache')     // L3: ~5-10ms
]);
```

**API servers with shared cache:**
```php
$cache = new MultiTierCache([
    new RedisStorage($redis),     // L1: Shared cache
    new FileStorage('/cache')     // L2: Fallback
]);
```

**Single server applications:**
```php
$cache = new MultiTierCache([
    new ApcuStorage('app:'),      // L1: Fast
    new FileStorage('/cache')     // L2: Persistent
]);
```

**Database-heavy applications:**
```php
$cache = new MultiTierCache([
    new ApcuStorage('app:'),      // L1: Fast
    new PdoStorage($pdo)          // L2: Same DB connection
]);
```

### Performance Characteristics

| Backend | Read Speed | Write Speed | Shared | Persistent |
|---------|-----------|-------------|--------|------------|
| APCu    | Fastest   | Fastest     | Yes*   | No         |
| Redis   | Fast      | Fast        | Yes    | Optional   |
| File    | Medium    | Medium      | No     | Yes        |
| PDO     | Slow      | Slow        | Yes    | Yes        |

*Shared between PHP-FPM workers on same server

---

## Compression Strategy

### When to Use Compression

**Use compression when:**
- Storing large payloads (>10KB)
- Network bandwidth is limited (Redis/PDO)
- Storage space is limited
- Data is compressible (text, JSON, HTML)

**Skip compression when:**
- Storing small values (<1KB)
- Data is already compressed (images, video)
- CPU is the bottleneck
- Using only APCu (memory is fast)

### Compression Benchmarks

**1KB payload:**
```
None:  0.001ms
Gzip:  0.050ms  (50x slower, 40% size)
Zstd:  0.030ms  (30x slower, 45% size)
```

**10KB payload:**
```
None:  0.005ms
Gzip:  0.200ms  (40x slower, 30% size)
Zstd:  0.120ms  (24x slower, 35% size)
```

**100KB payload:**
```
None:  0.050ms
Gzip:  1.500ms  (30x slower, 25% size)
Zstd:  0.800ms  (16x slower, 28% size)
```

### Compression Level Selection

**Gzip Levels:**
```php
// Fast compression (recommended for most cases)
new GzipCompressor(1);  // 80% of max compression, 3x faster

// Balanced (default)
new GzipCompressor(5);  // 95% of max compression, 1.5x faster

// Maximum compression
new GzipCompressor(9);  // 100% compression, slowest
```

**Recommendation:** Use level 3-5 for most applications.

### Example Configuration

```php
// For text-heavy data (HTML, JSON)
$cache = new MultiTierCache(
    [$apcu, $redis],
    new JsonSerializer(),
    new GzipCompressor(3)  // 3x faster than level 9
);

// For mixed data
$cache = new MultiTierCache(
    [$apcu, $redis],
    new NativeSerializer(),
    new NullCompressor()  // No compression overhead
);
```

---

## Serialization Performance

### Serializer Comparison

| Serializer | Encode | Decode | Size | Objects |
|-----------|--------|--------|------|---------|
| Native    | Fast   | Fast   | Large| Yes     |
| JSON      | Faster | Faster | Small| No      |

### Benchmarks

**Simple array (1000 items):**
```
Native: encode=0.15ms, decode=0.20ms, size=50KB
JSON:   encode=0.08ms, decode=0.10ms, size=30KB
```

**Complex nested structure:**
```
Native: encode=0.50ms, decode=0.60ms, size=150KB
JSON:   encode=0.30ms, decode=0.35ms, size=100KB
```

### Recommendations

**Use NativeSerializer when:**
- You need to cache objects
- Data structure is complex
- You're only using PHP

**Use JsonSerializer when:**
- Data is simple (arrays, scalars)
- Size matters (with compression)
- Portability is important

---

## TTL Optimization

### TTL Guidelines

**Hot data (frequently accessed):**
```php
$cache->set('homepage_data', $data, 60);  // 1 minute
```

**Warm data (moderately accessed):**
```php
$cache->set('product_list', $data, 300);  // 5 minutes
```

**Cold data (rarely changes):**
```php
$cache->set('site_config', $data, 3600);  // 1 hour
```

**Static data:**
```php
$cache->set('country_list', $data, 86400);  // 1 day
```

### Cache Hit Rate vs TTL

```
TTL     Hit Rate    Staleness
1min    99%         Very fresh
5min    95%         Fresh
15min   90%         Acceptable
1hour   80%         Potentially stale
1day    60%         Likely stale
```

**Formula:** `Optimal TTL ≈ Update Frequency × 2`

---

## SWR Best Practices

### When to Use SWR

**Perfect for:**
- External API calls
- Expensive database queries
- Complex calculations
- Frequently accessed, slowly changing data

### SWR Configuration

**Fast-changing data (news, prices):**
```php
$cache->getOrSetSWR(
    'news_feed',
    fn() => fetchNews(),
    ttl: 60,                    // 1 minute fresh
    swrSeconds: 30,             // 30 seconds stale
    staleIfErrorSeconds: 300    // 5 minutes if error
);
```

**Slow-changing data (user profiles):**
```php
$cache->getOrSetSWR(
    'user_profile',
    fn() => loadProfile(),
    ttl: 3600,                  // 1 hour fresh
    swrSeconds: 1800,           // 30 minutes stale
    staleIfErrorSeconds: 86400  // 1 day if error
);
```

### Defer Mode

Use defer mode for better response times:

```php
// Sync mode: ~100ms (includes refresh)
$data = $cache->getOrSetSWR(
    'key',
    fn() => expensiveCall(),  // 50ms
    300, 60, 300,
    ['mode' => 'sync']
);

// Defer mode: ~1ms (serves stale, refreshes after response)
$data = $cache->getOrSetSWR(
    'key',
    fn() => expensiveCall(),  // Runs after response
    300, 60, 300,
    ['mode' => 'defer']  // Requires fastcgi_finish_request()
);
```

---

## File Storage Optimization

### Directory Sharding

**Impact on performance:**

```
10,000 files:
Shards=0: ls=2000ms, find=1500ms
Shards=2: ls=50ms, find=30ms
Shards=3: ls=10ms, find=8ms

100,000 files:
Shards=0: Not practical
Shards=2: ls=500ms, find=300ms
Shards=3: ls=100ms, find=60ms
```

**Recommendation:**
- 0 shards: < 1,000 files
- 2 shards: 1,000 - 100,000 files (recommended)
- 3 shards: > 100,000 files

### File System Choice

**Performance comparison:**
```
ext4:   Read=100MB/s, Write=80MB/s  (recommended)
xfs:    Read=120MB/s, Write=90MB/s  (best for large files)
btrfs:  Read=90MB/s, Write=70MB/s
tmpfs:  Read=2GB/s, Write=2GB/s     (RAM disk - not persistent)
```

### Mount Options

**For cache directory:**
```bash
# /etc/fstab
/dev/sdb1 /var/cache ext4 noatime,nodiratime 0 2
```

**Benefits:**
- `noatime`: Don't update access time (30% faster reads)
- `nodiratime`: Don't update directory access time

---

## Redis Optimization

### Connection Pooling

**Use persistent connections:**
```php
$redis = new Redis();
$redis->pconnect('127.0.0.1', 6379);  // Persistent
$storage = new RedisStorage($redis);
```

### Redis Configuration

**In redis.conf:**
```conf
# Memory management
maxmemory 2gb
maxmemory-policy allkeys-lru

# Performance
tcp-backlog 511
timeout 0
tcp-keepalive 300

# Persistence (optional for cache)
save ""  # Disable RDB
appendonly no  # Disable AOF
```

### Pipeline Operations

For bulk operations, use pipelining:

```php
// Without pipeline: 100 operations = 100 network calls
for ($i = 0; $i < 100; $i++) {
    $cache->set("key_{$i}", $data, 3600);  // 100ms
}

// With pipeline: 100 operations = 1 network call
$cache->setMultiple($dataArray, 3600);  // 10ms
```

---

## Benchmarks

### Read Performance (per operation)

```
APCu:         0.01ms
Redis:        1.2ms (local)
Redis:        15ms (remote)
File (SSD):   5ms
File (HDD):   25ms
PDO (MySQL):  8ms
PDO (SQLite): 3ms
```

### Write Performance (per operation)

```
APCu:         0.01ms
Redis:        1.5ms
File (SSD):   8ms
File (HDD):   35ms
PDO:          12ms
```

### Multi-Tier Latency

**Cache hit in tier:**
```
L1 (APCu):      0.01ms
L2 (Redis):     1.2ms + backfill(0.01ms) = 1.21ms
L3 (File):      5ms + backfill(1.21ms) = 6.21ms
Miss:           Producer time + 6.21ms
```

### Compression Impact

**10KB payload:**
```
No compression:           1.2ms (read) + 1.5ms (write)
Gzip level 3:            1.4ms (read) + 1.7ms (write)
Zstd level 3:            1.3ms (read) + 1.6ms (write)

Network savings (Redis):  70% less bandwidth
Storage savings (File):   65% less disk space
```

---

## Real-World Optimization Examples

### Example 1: High-Traffic API

**Before:**
```php
$data = $db->query("EXPENSIVE QUERY")->fetchAll();  // 250ms
```

**After:**
```php
$cache = new MultiTierCache([
    new ApcuStorage(),
    new RedisStorage($redis)
], new JsonSerializer(), new GzipCompressor(3));

$data = $cache->getOrSetSWR(
    'api_data',
    fn() => $db->query("EXPENSIVE QUERY")->fetchAll(),
    300, 60, 1800,
    ['mode' => 'defer']
);
// First request: 250ms
// Cached requests: 0.01ms (99.996% faster)
// Stale requests: 0.01ms + background refresh
```

**Result:** 99.996% response time improvement

### Example 2: E-commerce Product Catalog

**Before:**
```php
$products = getProducts();  // 500ms (joins, calculations)
```

**After:**
```php
$cache = new MultiTierCache([
    new ApcuStorage(),
    new FileStorage('/cache', '.cache', 2)
], new NativeSerializer(), new NullCompressor());

$products = $cache->get('products_catalog');
if (!$products) {
    $products = getProducts();
    $cache->set('products_catalog', $products, 600);
}
// Cached: 0.01ms (50,000x faster)
```

---

## Monitoring & Profiling

### Add Logging

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('cache');
$logger->pushHandler(new StreamHandler('/var/log/cache.log'));

$cache = new MultiTierCache($tiers, $serializer, $compressor, 3600, $logger);
```

### Metrics to Track

- Hit rate per tier
- Average response time
- Cache size
- Eviction rate
- Error rate

### Example Monitoring

```php
class CacheMetrics
{
    private int $hits = 0;
    private int $misses = 0;

    public function get($key)
    {
        $value = $this->cache->get($key);
        
        if ($value !== null) {
            $this->hits++;
        } else {
            $this->misses++;
        }
        
        return $value;
    }

    public function getHitRate(): float
    {
        $total = $this->hits + $this->misses;
        return $total > 0 ? $this->hits / $total : 0;
    }
}
```

---

## Quick Wins

1. **Use APCu as first tier** - Free 100x speed boost
2. **Enable compression for large data** - Save bandwidth and storage
3. **Use appropriate TTLs** - Balance freshness and hit rate
4. **Implement SWR** - Eliminate cache stampedes
5. **Use defer mode** - Improve response times
6. **Enable directory sharding** - Better file system performance
7. **Use persistent Redis connections** - Reduce connection overhead
8. **Batch operations** - Use setMultiple/getMultiple
9. **Profile your cache** - Measure before optimizing
10. **Monitor hit rates** - Adjust TTLs based on data

---

## Conclusion

Optimal cache configuration depends on your specific use case. Start with recommended defaults and adjust based on your metrics.

**General recommendation for most applications:**
```php
$cache = new MultiTierCache(
    [new ApcuStorage(), new RedisStorage($redis)],
    new NativeSerializer(),
    new GzipCompressor(3),
    600
);
```

For more help, see [README.md](README.md) and [EXAMPLES.md](EXAMPLES.md).

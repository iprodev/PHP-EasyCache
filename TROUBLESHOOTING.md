# Troubleshooting Guide

Common issues and their solutions.

## Table of Contents

- [Installation Issues](#installation-issues)
- [Storage Backend Issues](#storage-backend-issues)
- [Performance Issues](#performance-issues)
- [Data Issues](#data-issues)
- [Laravel Integration Issues](#laravel-integration-issues)
- [Debugging Tips](#debugging-tips)

---

## Installation Issues

### Composer Install Fails

**Problem:**
```
Your requirements could not be resolved to an installable set of packages.
```

**Solution:**
```bash
# Update composer
composer self-update

# Clear cache
composer clear-cache

# Try again
composer require iprodev/php-easycache
```

### PHP Version Error

**Problem:**
```
php-easycache requires php ^8.1 but your PHP version (7.4) does not satisfy that requirement.
```

**Solution:**
Upgrade to PHP 8.1 or higher:
```bash
# Ubuntu/Debian
sudo apt-get install php8.1

# Check version
php -v
```

---

## Storage Backend Issues

### APCu: Extension Not Available

**Problem:**
```
RuntimeException: APCu extension is not available
```

**Solution:**
```bash
# Install APCu extension
sudo apt-get install php-apcu  # Ubuntu/Debian
sudo yum install php-apcu      # CentOS/RHEL

# Verify installation
php -m | grep apcu

# Enable for CLI (if needed)
# Edit php.ini and add:
apc.enable_cli=1
```

**Verify:**
```php
<?php
phpinfo();  // Look for APCu section
```

### APCu: Not Enabled

**Problem:**
```
RuntimeException: APCu is not enabled
```

**Solution:**
Edit php.ini:
```ini
[apcu]
extension=apcu.so
apc.enabled=1
apc.enable_cli=1  ; For CLI scripts
apc.shm_size=128M  ; Shared memory size
```

Restart web server:
```bash
sudo systemctl restart php8.1-fpm
sudo systemctl restart apache2  # or nginx
```

### Redis: Connection Failed

**Problem:**
```
RedisException: Connection refused
```

**Solution:**

1. **Check if Redis is running:**
```bash
redis-cli ping
# Should return: PONG
```

2. **Start Redis if not running:**
```bash
sudo systemctl start redis
sudo systemctl enable redis  # Start on boot
```

3. **Check Redis configuration:**
```bash
# Edit /etc/redis/redis.conf
bind 127.0.0.1
port 6379
protected-mode yes
```

4. **Test connection:**
```php
$redis = new Redis();
if ($redis->connect('127.0.0.1', 6379)) {
    echo "Connected!";
} else {
    echo "Failed to connect";
}
```

### Redis: Auth Failed

**Problem:**
```
RedisException: NOAUTH Authentication required
```

**Solution:**
```php
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->auth('your_password_here');

$storage = new RedisStorage($redis);
```

### File Storage: Permission Denied

**Problem:**
```
RuntimeException: Unable to create cache directory: /var/cache/app
```

**Solution:**

1. **Create directory with proper permissions:**
```bash
sudo mkdir -p /var/cache/app
sudo chown www-data:www-data /var/cache/app
sudo chmod 770 /var/cache/app
```

2. **Or use a writable directory:**
```php
$storage = new FileStorage(
    sys_get_temp_dir() . '/mycache'
);
```

3. **Check SELinux (if enabled):**
```bash
# Check SELinux status
getenforce

# Allow PHP to write to cache directory
sudo chcon -R -t httpd_cache_t /var/cache/app
```

### File Storage: Disk Full

**Problem:**
```
Warning: fwrite(): write of XXX bytes failed with errno=28 No space left on device
```

**Solution:**

1. **Check disk space:**
```bash
df -h
```

2. **Clear old cache:**
```php
$cache->clear();
```

3. **Set up automatic cleanup:**
```bash
# Add to crontab
0 2 * * * php /path/to/cleanup.php
```

cleanup.php:
```php
<?php
$cache->prune();  // For PDO
$cache->clear();  // Or clear all
```

### PDO: Table Not Found

**Problem:**
```
PDOException: SQLSTATE[42S02]: Base table or view not found
```

**Solution:**
```php
$storage = new PdoStorage($pdo, 'easycache');
$storage->ensureTable();  // Create table
```

### PDO: Connection Failed

**Problem:**
```
PDOException: SQLSTATE[HY000] [2002] Connection refused
```

**Solution:**

1. **Check database is running:**
```bash
# MySQL
sudo systemctl status mysql

# PostgreSQL
sudo systemctl status postgresql
```

2. **Check connection parameters:**
```php
try {
    $pdo = new PDO(
        'mysql:host=127.0.0.1;dbname=cache;charset=utf8mb4',
        'username',
        'password',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "Connected!";
} catch (PDOException $e) {
    echo "Failed: " . $e->getMessage();
}
```

---

## Performance Issues

### Slow Cache Reads

**Problem:**
Cache reads are slower than expected.

**Diagnosis:**
```php
$start = microtime(true);
$value = $cache->get('key');
$time = (microtime(true) - $start) * 1000;
echo "Read took: {$time}ms";
```

**Solutions:**

1. **Add APCu as first tier:**
```php
$cache = new MultiTierCache([
    new ApcuStorage(),  // Add this
    new RedisStorage($redis),
    new FileStorage('/cache')
]);
```

2. **Reduce payload size with compression:**
```php
$cache = new MultiTierCache(
    [$storage],
    new JsonSerializer(),     // Smaller than Native
    new GzipCompressor(3)     // Compress large data
);
```

3. **Use file sharding:**
```php
$storage = new FileStorage('/cache', '.cache', 2);  // Enable sharding
```

### Low Cache Hit Rate

**Problem:**
Too many cache misses.

**Diagnosis:**
```php
$hits = 0;
$misses = 0;

for ($i = 0; $i < 1000; $i++) {
    $value = $cache->get("key_{$i}");
    if ($value !== null) {
        $hits++;
    } else {
        $misses++;
    }
}

$hitRate = $hits / ($hits + $misses);
echo "Hit rate: " . ($hitRate * 100) . "%";
```

**Solutions:**

1. **Increase TTL:**
```php
$cache->set('key', $value, 3600);  // 1 hour instead of 5 minutes
```

2. **Use SWR for frequently accessed data:**
```php
$value = $cache->getOrSetSWR(
    'key',
    fn() => loadData(),
    300,   // TTL
    60,    // SWR
    1800   // Stale-if-error
);
```

3. **Warm up cache:**
```php
// During deployment or cron
$cache->set('popular_data', loadPopularData(), 3600);
```

### High Memory Usage (APCu)

**Problem:**
```
PHP Warning: apcu_store(): Unable to allocate memory for pool
```

**Solution:**

1. **Increase APCu memory:**
```ini
; php.ini
apc.shm_size=256M  ; Increase from default 32M
```

2. **Clear APCu:**
```bash
# CLI
php -r "apcu_clear_cache();"

# Or in code
$storage = new ApcuStorage();
$storage->clear();
```

3. **Use compression:**
```php
$cache = new MultiTierCache(
    [new ApcuStorage()],
    new JsonSerializer(),
    new GzipCompressor(5)  // Reduce memory usage
);
```

---

## Data Issues

### Data Corruption

**Problem:**
Cache returns corrupted or unexpected data.

**Diagnosis:**
```php
$raw = $storage->get('key');
echo "Raw data: " . bin2hex(substr($raw, 0, 20));
```

**Solutions:**

1. **Clear corrupted cache:**
```php
$cache->delete('corrupted_key');
// Or
$cache->clear();
```

2. **Check serializer compatibility:**
```php
// If you changed serializer, old data might be incompatible
$cache->clear();  // Clear old data
```

3. **Verify data before caching:**
```php
if (is_array($data) && isset($data['required_field'])) {
    $cache->set('key', $data, 3600);
}
```

### Stale Data

**Problem:**
Cache returns old data even though it should be updated.

**Solutions:**

1. **Invalidate on update:**
```php
function updateUser($id, $data) {
    $db->update('users', $data, ['id' => $id]);
    $cache->delete("user_{$id}");  // Invalidate
}
```

2. **Use shorter TTL:**
```php
$cache->set('key', $value, 300);  // 5 minutes instead of 1 hour
```

3. **Use version keys:**
```php
$version = $cache->get('data_version') ?? 1;
$key = "data_v{$version}";
$data = $cache->get($key);

// When data changes:
$cache->set('data_version', $version + 1);
```

### Serialization Errors

**Problem:**
```
JsonException: Malformed UTF-8 characters
```

**Solution:**

1. **Switch to NativeSerializer:**
```php
$cache = new MultiTierCache(
    [$storage],
    new NativeSerializer(),  // More forgiving
    $compressor
);
```

2. **Clean data before caching:**
```php
$cleanData = mb_convert_encoding($data, 'UTF-8', 'UTF-8');
$cache->set('key', $cleanData, 3600);
```

### Object Caching Issues

**Problem:**
Objects lose their class after retrieval.

**Solution:**

Use NativeSerializer (not JsonSerializer) for objects:
```php
$cache = new MultiTierCache(
    [$storage],
    new NativeSerializer(),  // Preserves objects
    $compressor
);

class User {
    public $id;
    public $name;
}

$user = new User();
$cache->set('user', $user, 3600);

$cached = $cache->get('user');
// $cached is still a User object
```

---

## Laravel Integration Issues

### Facade Not Found

**Problem:**
```
Facade [EasyCache] does not exist
```

**Solution:**

1. **Clear Laravel caches:**
```bash
php artisan config:clear
php artisan cache:clear
php artisan clear-compiled
```

2. **Check package discovery:**
```bash
composer dump-autoload
```

3. **Manual registration (if needed):**
```php
// config/app.php
'providers' => [
    Iprodev\EasyCache\Laravel\EasyCacheServiceProvider::class,
],

'aliases' => [
    'EasyCache' => Iprodev\EasyCache\Laravel\Facades\EasyCache::class,
],
```

### Configuration Not Published

**Problem:**
Config file missing after installation.

**Solution:**
```bash
php artisan vendor:publish --tag=easycache-config --force
```

### Conflicts with Laravel Cache

**Problem:**
EasyCache interferes with Laravel's built-in cache.

**Solution:**
Use different cache for EasyCache:
```php
// config/easycache.php
'redis' => [
    'database' => 1,  // Use DB 1 for EasyCache
],

// Laravel uses DB 0 by default
```

---

## Debugging Tips

### Enable Logging

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;

$logger = new Logger('cache');
$logger->pushHandler(new StreamHandler('/var/log/cache.log', Logger::DEBUG));
$logger->pushHandler(new FirePHPHandler());

$cache = new MultiTierCache($tiers, $serializer, $compressor, 3600, $logger);
```

### Debug Cache Operations

```php
class DebugCache
{
    private $cache;

    public function get($key)
    {
        $start = microtime(true);
        $value = $this->cache->get($key);
        $time = (microtime(true) - $start) * 1000;

        error_log(sprintf(
            "CACHE GET: key=%s, found=%s, time=%.2fms",
            $key,
            $value !== null ? 'yes' : 'no',
            $time
        ));

        return $value;
    }
}
```

### Check Cache Contents

```php
// List all keys (development only!)
function listCacheKeys(FileStorage $storage): array
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($storage->getPath())
    );

    $keys = [];
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'cache') {
            $keys[] = $file->getBasename('.cache');
        }
    }

    return $keys;
}
```

### Test Cache Backend

```php
function testCache($cache): void
{
    echo "Testing cache...\n";

    // Test set
    $ok = $cache->set('test_key', 'test_value', 60);
    echo "Set: " . ($ok ? 'OK' : 'FAIL') . "\n";

    // Test get
    $value = $cache->get('test_key');
    echo "Get: " . ($value === 'test_value' ? 'OK' : 'FAIL') . "\n";

    // Test has
    $exists = $cache->has('test_key');
    echo "Has: " . ($exists ? 'OK' : 'FAIL') . "\n";

    // Test delete
    $ok = $cache->delete('test_key');
    echo "Delete: " . ($ok ? 'OK' : 'FAIL') . "\n";

    // Test get after delete
    $value = $cache->get('test_key');
    echo "Get after delete: " . ($value === null ? 'OK' : 'FAIL') . "\n";
}
```

### Monitor Cache Size

```php
function getCacheSize(string $path): array
{
    $size = 0;
    $count = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path)
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
            $count++;
        }
    }

    return [
        'size' => $size,
        'size_mb' => round($size / 1024 / 1024, 2),
        'files' => $count
    ];
}
```

---

## Getting Help

If you're still having issues:

1. **Check the logs:**
   - `/var/log/cache.log` (if configured)
   - `/var/log/php-fpm/error.log`
   - `/var/log/apache2/error.log`

2. **Enable debug mode:**
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

3. **Create a minimal test case:**
```php
<?php
require 'vendor/autoload.php';

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Storage\FileStorage;

$cache = new MultiTierCache([
    new FileStorage('/tmp/test_cache')
]);

$cache->set('test', 'value', 60);
echo $cache->get('test');  // Should output: value
```

4. **Report the issue:**
   - [GitHub Issues](https://github.com/iprodev/php-easycache/issues)
   - Include: PHP version, OS, error messages, code example

---

## Common Error Messages

| Error | Cause | Solution |
|-------|-------|----------|
| `RuntimeException: APCu extension is not available` | APCu not installed | Install php-apcu |
| `RuntimeException: Unable to create cache directory` | Permission denied | Fix directory permissions |
| `RedisException: Connection refused` | Redis not running | Start Redis service |
| `InvalidArgument: Illegal cache key` | Invalid key format | Use only `A-Za-z0-9_.` |
| `JsonException: Malformed UTF-8` | Invalid JSON data | Use NativeSerializer |
| `PDOException: Table not found` | Table doesn't exist | Call `ensureTable()` |

---

For more information, see:
- [README.md](README.md)
- [API.md](API.md)
- [EXAMPLES.md](EXAMPLES.md)
- [PERFORMANCE.md](PERFORMANCE.md)

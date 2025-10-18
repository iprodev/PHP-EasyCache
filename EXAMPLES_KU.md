# نموونەی کاربەردیی PHP EasyCache

ئەم بەڵگەنامەیە نموونە کاربەردی و پراکتیکیی بەکارهێنانی PHP EasyCache لە سیناریۆی ڕاستەقینەدا دەگرێتەوە.

## پێرستی ناوەڕۆک

- [نموونە بنەڕەتییەکان](#نموونە-بنەڕەتییەکان)
- [Storage Backends](#storage-backends)
- [Serialization و Compression](#serialization-و-compression)
- [Stale-While-Revalidate (SWR)](#stale-while-revalidate-swr)
- [Multi-Tier Caching](#multi-tier-caching)
- [Laravel Integration](#laravel-integration)
- [شێوازە کاربەردییەکان](#شێوازە-کاربەردییەکان)
- [باشکردنی کارایی](#باشکردنی-کارایی)

---

## نموونە بنەڕەتییەکان

### نموونە 1: سادەترین بەکارهێنان

```php
<?php
require 'vendor/autoload.php';

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Storage\FileStorage;

$cache = new MultiTierCache([
    new FileStorage('/tmp/cache')
]);

// هەڵگرتن
$cache->set('user_123', [
    'name' => 'ئەحمەد حەمە',
    'email' => 'ahmad@example.com'
], 3600);

// گەڕاندنەوە
$user = $cache->get('user_123');
echo "ناو: {$user['name']}\n";
```

### نموونە 2: بەکارهێنانی بەهای بنەڕەتی

```php
// گەڕاندنەوە لەگەڵ بەهای بنەڕەتی
$config = $cache->get('app_config', [
    'theme' => 'light',
    'language' => 'ku'
]);

// پشکنینی بوون
if ($cache->has('user_settings')) {
    $settings = $cache->get('user_settings');
} else {
    $settings = loadDefaultSettings();
    $cache->set('user_settings', $settings, 7200);
}
```

### نموونە 3: کارپێکردنی چەندین

```php
// هەڵگرتنی چەندین بڕگە
$cache->setMultiple([
    'setting_1' => 'value1',
    'setting_2' => 'value2',
    'setting_3' => 'value3',
], 1800);

// گەڕاندنەوەی چەندین بڕگە
$keys = ['setting_1', 'setting_2', 'setting_missing'];
$results = $cache->getMultiple($keys, null);
// ['setting_1' => 'value1', 'setting_2' => 'value2', 'setting_missing' => null]

// لابردنی چەندین بڕگە
$cache->deleteMultiple(['old_key1', 'old_key2']);
```

---

## Storage Backends

### APCu Storage

```php
use Iprodev\EasyCache\Storage\ApcuStorage;

// لەگەڵ پێشگری تایبەت
$storage = new ApcuStorage('myapp:');

$cache = new MultiTierCache([$storage]);

// بەکارهێنان
$cache->set('user_session', $sessionData, 1800);
$session = $cache->get('user_session');
```

### Redis Storage

```php
use Iprodev\EasyCache\Storage\RedisStorage;

// لەگەڵ phpredis
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->auth('password'); // ئەگەر پێویست بێت
$redis->select(2); // هەڵبژاردنی database

$storage = new RedisStorage($redis, 'app:');

// یان لەگەڵ Predis
$client = new Predis\Client([
    'scheme' => 'tcp',
    'host'   => '127.0.0.1',
    'port'   => 6379,
]);
$storage = new RedisStorage($client, 'app:');

$cache = new MultiTierCache([$storage]);
```

### File Storage

```php
use Iprodev\EasyCache\Storage\FileStorage;

// لەگەڵ sharding بۆ کارایی باشتر
$storage = new FileStorage(
    path: '/var/cache/myapp',
    ext: '.cache',
    shards: 2  // 0-3 ئاستی sharding
);

$cache = new MultiTierCache([$storage]);

// نموونە لەگەڵ ڕێچکە جیاوازەکان
$cacheDir = sys_get_temp_dir() . '/easycache';
$storage = new FileStorage($cacheDir, '.dat', 3);
```

### PDO Storage

```php
use Iprodev\EasyCache\Storage\PdoStorage;

// MySQL
$pdo = new PDO(
    'mysql:host=localhost;dbname=mydb;charset=utf8mb4',
    'username',
    'password',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$storage = new PdoStorage($pdo, 'cache_table');
$storage->ensureTable(); // دروستکردنی خشتە

$cache = new MultiTierCache([$storage]);

// PostgreSQL
$pdo = new PDO(
    'pgsql:host=localhost;dbname=mydb',
    'username',
    'password'
);
$storage = new PdoStorage($pdo, 'app_cache');
$storage->ensureTable();

// SQLite
$pdo = new PDO('sqlite:/path/to/cache.db');
$storage = new PdoStorage($pdo);
$storage->ensureTable();
```

---

## Serialization و Compression

### Native Serializer (پشتگیری لە Objects)

```php
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Compression\GzipCompressor;

class User {
    public $id;
    public $name;
    public $email;
}

$user = new User();
$user->id = 123;
$user->name = 'ئەحمەد';
$user->email = 'ahmad@example.com';

$cache = new MultiTierCache(
    [$storage],
    new NativeSerializer(),
    new GzipCompressor(5)
);

$cache->set('user_object', $user, 3600);
$retrievedUser = $cache->get('user_object');
echo $retrievedUser->name; // ئەحمەد
```

### JSON Serializer (خێراتر)

```php
use Iprodev\EasyCache\Serialization\JsonSerializer;
use Iprodev\EasyCache\Compression\ZstdCompressor;

$cache = new MultiTierCache(
    [$storage],
    new JsonSerializer(),
    new ZstdCompressor(3)
);

// تەنها بۆ داتا سادەکان
$data = [
    'products' => [
        ['id' => 1, 'name' => 'بەرهەمی 1', 'price' => 100],
        ['id' => 2, 'name' => 'بەرهەمی 2', 'price' => 200],
    ]
];

$cache->set('products_list', $data, 600);
```

### بێ پەستاندن

```php
use Iprodev\EasyCache\Compression\NullCompressor;

$cache = new MultiTierCache(
    [$storage],
    new JsonSerializer(),
    new NullCompressor() // بێ پەستاندن
);
```

---

## Stale-While-Revalidate (SWR)

### نموونە 1: SWR سادە

```php
$posts = $cache->getOrSetSWR(
    key: 'blog_posts',
    producer: function() {
        // کارپێکردنی گران
        return fetchPostsFromDatabase();
    },
    ttl: 300,          // 5 خولەک تازە
    swrSeconds: 60,    // 1 خولەک stale
    staleIfErrorSeconds: 600  // 10 خولەک ئەگەر هەڵە
);
```

### نموونە 2: SWR لەگەڵ دۆخی defer

```php
// لە دۆخی defer، داتای stale دەستبەجێ دەگەڕێتەوە
// و refresh لە پاشبنەمادا ئەنجام دەدرێت
$data = $cache->getOrSetSWR(
    key: 'dashboard_stats',
    producer: function() {
        return [
            'users' => getUserCount(),
            'orders' => getOrderCount(),
            'revenue' => getRevenue(),
        ];
    },
    ttl: 300,
    swrSeconds: 120,
    staleIfErrorSeconds: 1800,
    options: ['mode' => 'defer']
);
```

### نموونە 3: بانگکردنی API لەگەڵ SWR

```php
function getWeatherData($city, $cache) {
    return $cache->getOrSetSWR(
        key: "weather_{$city}",
        producer: function() use ($city) {
            // بانگکردنی API ی ڕاستەقینە
            $response = file_get_contents(
                "https://api.weather.com/data?city={$city}"
            );
            return json_decode($response, true);
        },
        ttl: 1800,      // 30 خولەک تازە
        swrSeconds: 300, // 5 خولەک stale
        staleIfErrorSeconds: 3600,  // 1 کاتژمێر ئەگەر API down بێت
        options: ['mode' => 'defer']
    );
}

$weather = getWeatherData('Sulaymaniyah', $cache);
echo "پلەی گەرمی: {$weather['temp']}°C\n";
```

### نموونە 4: Query ی بنکەی دراوە لەگەڵ SWR

```php
function getPopularProducts(PDO $db, MultiTierCache $cache): array {
    return $cache->getOrSetSWR(
        key: 'popular_products',
        producer: function() use ($db) {
            $stmt = $db->prepare("
                SELECT p.*, COUNT(o.id) as sales
                FROM products p
                JOIN orders o ON p.id = o.product_id
                WHERE o.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY p.id
                ORDER BY sales DESC
                LIMIT 10
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        },
        ttl: 900,       // 15 خولەک
        swrSeconds: 180, // 3 خولەک
        staleIfErrorSeconds: 3600,
        options: ['mode' => 'defer']
    );
}
```

---

## Multi-Tier Caching

### نموونە 1: سێ ڕیز لەگەڵ Backfill

```php
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$cache = new MultiTierCache([
    new ApcuStorage('app:'),           // L1: خێرا (یادگەی ناوخۆیی)
    new RedisStorage($redis, 'app:'),  // L2: ناوەند (تۆڕ)
    new FileStorage('/var/cache/app')  // L3: هێواش (دیسک)
]);

// یەکەم جار: لە File دەخوێنێتەوە، بۆ Redis و APCu backfill دەکات
$data = $cache->get('expensive_data');

// جارەکانی دواتر: ڕاستەوخۆ لە APCu دەخوێنێتەوە (زۆر خێرا)
$data = $cache->get('expensive_data');
```

### نموونە 2: پێکهاتەی ڕیزەیی پشتبەست بە بەکارهێنان

```php
// بۆ داتای زۆر بەکارهاتوو
$hotCache = new MultiTierCache([
    new ApcuStorage('hot:'),
]);

// بۆ داتای ناوەند
$warmCache = new MultiTierCache([
    new ApcuStorage('warm:'),
    new RedisStorage($redis, 'warm:'),
]);

// بۆ داتای کەم بەکارهاتوو
$coldCache = new MultiTierCache([
    new RedisStorage($redis, 'cold:'),
    new FileStorage('/cache/cold'),
]);

// بەکارهێنان
$hotCache->set('session_' . $userId, $session, 300);
$warmCache->set('user_' . $userId, $userData, 3600);
$coldCache->set('report_' . $date, $report, 86400);
```

---

## Laravel Integration

### دامەزراندن و ڕێکخستن

```bash
composer require iprodev/php-easycache
php artisan vendor:publish --tag=easycache-config
```

### بەکارهێنان لە Controller

```php
<?php

namespace App\Http\Controllers;

use EasyCache;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = EasyCache::getOrSetSWR(
            'dashboard_stats',
            fn() => $this->loadStats(),
            300, 60, 600,
            ['mode' => 'defer']
        );

        return view('dashboard', compact('stats'));
    }

    private function loadStats()
    {
        return [
            'users' => \App\Models\User::count(),
            'orders' => \App\Models\Order::whereDate('created_at', today())->count(),
            'revenue' => \App\Models\Order::whereDate('created_at', today())->sum('total'),
        ];
    }
}
```

### بەکارهێنان لە Service

```php
<?php

namespace App\Services;

use EasyCache;

class ProductService
{
    public function getFeatured()
    {
        return EasyCache::getOrSetSWR(
            'featured_products',
            function() {
                return \App\Models\Product::where('featured', true)
                    ->with('category', 'images')
                    ->get()
                    ->toArray();
            },
            600,  // 10 خولەک
            120,  // 2 خولەک stale
            1800  // 30 خولەک ئەگەر هەڵە
        );
    }

    public function clearCache()
    {
        EasyCache::delete('featured_products');
    }
}
```

### Middleware بۆ Cache

```php
<?php

namespace App\Http\Middleware;

use Closure;
use EasyCache;

class CacheResponse
{
    public function handle($request, Closure $next, $ttl = 300)
    {
        $key = 'route:' . $request->path();

        // پشکنینی cache
        if ($cached = EasyCache::get($key)) {
            return response($cached);
        }

        // بەردەوامبوونی داواکاری
        $response = $next($request);

        // هەڵگرتن لە cache
        if ($response->isSuccessful()) {
            EasyCache::set($key, $response->getContent(), $ttl);
        }

        return $response;
    }
}
```

---

## شێوازە کاربەردییەکان

### Rate Limiting

```php
class RateLimiter
{
    private $cache;
    private $maxAttempts;
    private $decayMinutes;

    public function __construct(MultiTierCache $cache, int $maxAttempts = 60, int $decayMinutes = 1)
    {
        $this->cache = $cache;
        $this->maxAttempts = $maxAttempts;
        $this->decayMinutes = $decayMinutes;
    }

    public function tooManyAttempts(string $key): bool
    {
        $attempts = $this->cache->get($key, 0);
        return $attempts >= $this->maxAttempts;
    }

    public function hit(string $key): int
    {
        $attempts = $this->cache->get($key, 0);
        $attempts++;
        
        $this->cache->set(
            $key,
            $attempts,
            $this->decayMinutes * 60
        );

        return $attempts;
    }

    public function remaining(string $key): int
    {
        $attempts = $this->cache->get($key, 0);
        return max(0, $this->maxAttempts - $attempts);
    }

    public function clear(string $key): void
    {
        $this->cache->delete($key);
    }
}

// بەکارهێنان
$limiter = new RateLimiter($cache, 100, 1);
$key = 'api:' . $request->ip();

if ($limiter->tooManyAttempts($key)) {
    die('زۆر داواکاری. تکایە دواتر هەوڵبدەرەوە.');
}

$limiter->hit($key);
// بەردەوامبوونی داواکاری...
```

### Session Management

```php
class CacheSessionHandler implements SessionHandlerInterface
{
    private $cache;
    private $minutes;

    public function __construct(MultiTierCache $cache, int $minutes = 120)
    {
        $this->cache = $cache;
        $this->minutes = $minutes;
    }

    public function read($id): string
    {
        return $this->cache->get("session:{$id}", '');
    }

    public function write($id, $data): bool
    {
        return $this->cache->set(
            "session:{$id}",
            $data,
            $this->minutes * 60
        );
    }

    public function destroy($id): bool
    {
        return $this->cache->delete("session:{$id}");
    }

    public function gc($max_lifetime): int|false
    {
        return $this->cache->prune();
    }

    public function open($path, $name): bool { return true; }
    public function close(): bool { return true; }
}

// تۆمارکردنی handler
$handler = new CacheSessionHandler($cache, 120);
session_set_save_handler($handler, true);
session_start();
```

### Fragment Caching

```php
class FragmentCache
{
    private $cache;

    public function __construct(MultiTierCache $cache)
    {
        $this->cache = $cache;
    }

    public function remember(string $key, int $ttl, callable $callback): string
    {
        if ($cached = $this->cache->get($key)) {
            return $cached;
        }

        ob_start();
        $callback();
        $content = ob_get_clean();

        $this->cache->set($key, $content, $ttl);

        return $content;
    }
}

// بەکارهێنان لە template
$fragment = new FragmentCache($cache);

echo $fragment->remember('sidebar_menu', 3600, function() {
    ?>
    <nav class="sidebar">
        <?php foreach (getMenuItems() as $item): ?>
            <a href="<?= $item['url'] ?>"><?= $item['title'] ?></a>
        <?php endforeach; ?>
    </nav>
    <?php
});
```

### Cache Tags (Grouped Cache)

```php
class TaggedCache
{
    private $cache;

    public function __construct(MultiTierCache $cache)
    {
        $this->cache = $cache;
    }

    public function tags(array $tags): self
    {
        $this->currentTags = $tags;
        return $this;
    }

    public function put(string $key, $value, int $ttl): bool
    {
        $taggedKey = $this->taggedKey($key);
        
        // هەڵگرتنی کلیل لە لیستی هەر tag
        foreach ($this->currentTags as $tag) {
            $tagKeys = $this->cache->get("tag:{$tag}", []);
            $tagKeys[] = $key;
            $this->cache->set("tag:{$tag}", array_unique($tagKeys), 0);
        }

        return $this->cache->set($taggedKey, $value, $ttl);
    }

    public function get(string $key)
    {
        return $this->cache->get($this->taggedKey($key));
    }

    public function flush(): void
    {
        foreach ($this->currentTags as $tag) {
            $keys = $this->cache->get("tag:{$tag}", []);
            foreach ($keys as $key) {
                $this->cache->delete($this->taggedKey($key));
            }
            $this->cache->delete("tag:{$tag}");
        }
    }

    private function taggedKey(string $key): string
    {
        $tagString = implode('|', $this->currentTags);
        return "tagged:{$tagString}:{$key}";
    }

    private $currentTags = [];
}

// بەکارهێنان
$taggedCache = new TaggedCache($cache);

// هەڵگرتن لەگەڵ tag
$taggedCache->tags(['users', 'profiles'])->put('user_1', $userData, 3600);
$taggedCache->tags(['users', 'profiles'])->put('user_2', $userData2, 3600);

// گەڕاندنەوە
$user = $taggedCache->tags(['users', 'profiles'])->get('user_1');

// پاککردنەوەی هەموو بڕگەکانی یەک tag
$taggedCache->tags(['users'])->flush();
```

---

## باشکردنی کارایی

### Benchmarking

```php
class CacheBenchmark
{
    private $cache;

    public function __construct(MultiTierCache $cache)
    {
        $this->cache = $cache;
    }

    public function benchmarkWrites(int $iterations = 1000): float
    {
        $start = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $this->cache->set("bench_key_{$i}", "value_{$i}", 3600);
        }

        return microtime(true) - $start;
    }

    public function benchmarkReads(int $iterations = 1000): float
    {
        // ئامادەکاری
        for ($i = 0; $i < $iterations; $i++) {
            $this->cache->set("bench_key_{$i}", "value_{$i}", 3600);
        }

        $start = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $this->cache->get("bench_key_{$i}");
        }

        return microtime(true) - $start;
    }

    public function report(): array
    {
        return [
            'writes' => [
                'time' => $this->benchmarkWrites(),
                'ops' => 1000 / $this->benchmarkWrites(),
            ],
            'reads' => [
                'time' => $this->benchmarkReads(),
                'ops' => 1000 / $this->benchmarkReads(),
            ],
        ];
    }
}

$benchmark = new CacheBenchmark($cache);
$results = $benchmark->report();

echo "نووسین: {$results['writes']['time']} چرکە ({$results['writes']['ops']} ops/s)\n";
echo "خوێندنەوە: {$results['reads']['time']} چرکە ({$results['reads']['ops']} ops/s)\n";
```

### Hit Rate Monitoring

```php
class CacheMonitor
{
    private $cache;
    private $hits = 0;
    private $misses = 0;

    public function __construct(MultiTierCache $cache)
    {
        $this->cache = $cache;
    }

    public function get(string $key, $default = null)
    {
        $value = $this->cache->get($key, $default);

        if ($value !== $default) {
            $this->hits++;
        } else {
            $this->misses++;
        }

        return $value;
    }

    public function set(string $key, $value, $ttl = null): bool
    {
        return $this->cache->set($key, $value, $ttl);
    }

    public function getStats(): array
    {
        $total = $this->hits + $this->misses;
        $hitRate = $total > 0 ? ($this->hits / $total) * 100 : 0;

        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'total' => $total,
            'hit_rate' => round($hitRate, 2) . '%',
        ];
    }

    public function reset(): void
    {
        $this->hits = 0;
        $this->misses = 0;
    }
}

// بەکارهێنان
$monitor = new CacheMonitor($cache);

for ($i = 0; $i < 100; $i++) {
    $monitor->get("key_{$i}");
}

print_r($monitor->getStats());
// ['hits' => 50, 'misses' => 50, 'total' => 100, 'hit_rate' => '50.00%']
```

---

## خاڵ و فێڵەکان

### 1. بەکارهێنانی TTL گونجاو

```php
// داتای گۆڕانی خێرا
$cache->set('stock_price', $price, 60);  // 1 خولەک

// داتای ناوەند
$cache->set('user_profile', $user, 1800);  // 30 خولەک

// داتای جێگیر
$cache->set('countries', $countries, 86400);  // 1 ڕۆژ

// داتای هەمیشەیی (تا کاتی سڕینەوەی دەستی)
$cache->set('app_config', $config, 0);  // بێسنوور
```

### 2. Namespace کردنی کلیلەکان

```php
// خراپ
$cache->set('user', $data);
$cache->set('product', $data);

// باش
$cache->set('user:profile:123', $data);
$cache->set('product:details:456', $data);
$cache->set('api:weather:sulaymaniyah', $data);
```

### 3. بەکارهێنانی SWR بۆ کارپێکردنە گرانەکان

```php
// خراپ: هەموو جارێک query دەکات
$products = getProductsFromDatabase();

// باش: لە cache بەکاردەهێنێت
$products = $cache->getOrSetSWR(
    'products',
    fn() => getProductsFromDatabase(),
    300, 60, 600
);
```

### 4. پاککردنەوەی بەردەوام

```php
// دروستکردنی cron job
// 0 3 * * * php /path/to/cleanup.php

<?php
require 'vendor/autoload.php';

$cache = // ... initialize cache

// پاککردنەوەی بڕگە بەسەرچووەکان
$pruned = $cache->prune();
echo "ژمارەی {$pruned} بڕگە پاک کرایەوە\n";

// یان پاککردنەوەی تەواو
$cache->clear();
```

---

بۆ نموونەی زیاتر، [API_KU.md](API_KU.md) و [PERFORMANCE.md](PERFORMANCE.md) ببینە.

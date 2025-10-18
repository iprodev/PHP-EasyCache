# نمونه‌های کاربردی PHP EasyCache

این سند شامل نمونه‌های عملی و کاربردی برای استفاده از PHP EasyCache در سناریوهای واقعی است.

## فهرست مطالب

- [نمونه‌های پایه](#نمونه‌های-پایه)
- [Storage Backends](#storage-backends)
- [Serialization و Compression](#serialization-و-compression)
- [Stale-While-Revalidate (SWR)](#stale-while-revalidate-swr)
- [Multi-Tier Caching](#multi-tier-caching)
- [Laravel Integration](#laravel-integration)
- [الگوهای کاربردی](#الگوهای-کاربردی)
- [بهینه‌سازی عملکرد](#بهینه‌سازی-عملکرد)

---

## نمونه‌های پایه

### نمونه 1: ساده‌ترین استفاده

```php
<?php
require 'vendor/autoload.php';

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Storage\FileStorage;

$cache = new MultiTierCache([
    new FileStorage('/tmp/cache')
]);

// ذخیره
$cache->set('user_123', [
    'name' => 'علی احمدی',
    'email' => 'ali@example.com'
], 3600);

// بازیابی
$user = $cache->get('user_123');
echo "نام: {$user['name']}\n";
```

### نمونه 2: استفاده از مقدار پیش‌فرض

```php
// بازیابی با مقدار پیش‌فرض
$config = $cache->get('app_config', [
    'theme' => 'light',
    'language' => 'fa'
]);

// بررسی وجود
if ($cache->has('user_settings')) {
    $settings = $cache->get('user_settings');
} else {
    $settings = loadDefaultSettings();
    $cache->set('user_settings', $settings, 7200);
}
```

### نمونه 3: عملیات چندتایی

```php
// ذخیره چندین آیتم
$cache->setMultiple([
    'setting_1' => 'value1',
    'setting_2' => 'value2',
    'setting_3' => 'value3',
], 1800);

// بازیابی چندین آیتم
$keys = ['setting_1', 'setting_2', 'setting_missing'];
$results = $cache->getMultiple($keys, null);
// ['setting_1' => 'value1', 'setting_2' => 'value2', 'setting_missing' => null]

// حذف چندین آیتم
$cache->deleteMultiple(['old_key1', 'old_key2']);
```

---

## Storage Backends

### APCu Storage

```php
use Iprodev\EasyCache\Storage\ApcuStorage;

// با پیشوند سفارشی
$storage = new ApcuStorage('myapp:');

$cache = new MultiTierCache([$storage]);

// استفاده
$cache->set('user_session', $sessionData, 1800);
$session = $cache->get('user_session');
```

### Redis Storage

```php
use Iprodev\EasyCache\Storage\RedisStorage;

// با phpredis
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->auth('password'); // اگر نیاز باشد
$redis->select(2); // انتخاب database

$storage = new RedisStorage($redis, 'app:');

// یا با Predis
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

// با sharding برای عملکرد بهتر
$storage = new FileStorage(
    path: '/var/cache/myapp',
    ext: '.cache',
    shards: 2  // 0-3 سطح sharding
);

$cache = new MultiTierCache([$storage]);

// نمونه با مسیرهای مختلف
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
$storage->ensureTable(); // ایجاد جدول

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

### Native Serializer (پشتیبانی از Objects)

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
$user->name = 'علی';
$user->email = 'ali@example.com';

$cache = new MultiTierCache(
    [$storage],
    new NativeSerializer(),
    new GzipCompressor(5)
);

$cache->set('user_object', $user, 3600);
$retrievedUser = $cache->get('user_object');
echo $retrievedUser->name; // علی
```

### JSON Serializer (سریع‌تر)

```php
use Iprodev\EasyCache\Serialization\JsonSerializer;
use Iprodev\EasyCache\Compression\ZstdCompressor;

$cache = new MultiTierCache(
    [$storage],
    new JsonSerializer(),
    new ZstdCompressor(3)
);

// فقط برای داده‌های ساده
$data = [
    'products' => [
        ['id' => 1, 'name' => 'محصول 1', 'price' => 100],
        ['id' => 2, 'name' => 'محصول 2', 'price' => 200],
    ]
];

$cache->set('products_list', $data, 600);
```

### بدون فشرده‌سازی

```php
use Iprodev\EasyCache\Compression\NullCompressor;

$cache = new MultiTierCache(
    [$storage],
    new JsonSerializer(),
    new NullCompressor() // بدون فشرده‌سازی
);
```

---

## Stale-While-Revalidate (SWR)

### نمونه 1: SWR ساده

```php
$posts = $cache->getOrSetSWR(
    key: 'blog_posts',
    producer: function() {
        // عملیات گران
        return fetchPostsFromDatabase();
    },
    ttl: 300,          // 5 دقیقه تازه
    swrSeconds: 60,    // 1 دقیقه stale
    staleIfErrorSeconds: 600  // 10 دقیقه اگر خطا
);
```

### نمونه 2: SWR با حالت defer

```php
// در حالت defer، داده stale فوراً برگردانده می‌شود
// و refresh در پس‌زمینه انجام می‌شود
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

### نمونه 3: API Call با SWR

```php
function getWeatherData($city, $cache) {
    return $cache->getOrSetSWR(
        key: "weather_{$city}",
        producer: function() use ($city) {
            // فراخوانی API واقعی
            $response = file_get_contents(
                "https://api.weather.com/data?city={$city}"
            );
            return json_decode($response, true);
        },
        ttl: 1800,      // 30 دقیقه تازه
        swrSeconds: 300, // 5 دقیقه stale
        staleIfErrorSeconds: 3600,  // 1 ساعت اگر API down باشد
        options: ['mode' => 'defer']
    );
}

$weather = getWeatherData('Tehran', $cache);
echo "دما: {$weather['temp']}°C\n";
```

### نمونه 4: Database Query با SWR

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
        ttl: 900,       // 15 دقیقه
        swrSeconds: 180, // 3 دقیقه
        staleIfErrorSeconds: 3600,
        options: ['mode' => 'defer']
    );
}
```

---

## Multi-Tier Caching

### نمونه 1: سه لایه با Backfill

```php
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$cache = new MultiTierCache([
    new ApcuStorage('app:'),           // L1: سریع (حافظه محلی)
    new RedisStorage($redis, 'app:'),  // L2: متوسط (شبکه)
    new FileStorage('/var/cache/app')  // L3: کند (دیسک)
]);

// اولین بار: از File می‌خواند، به Redis و APCu backfill می‌کند
$data = $cache->get('expensive_data');

// دفعات بعد: مستقیماً از APCu می‌خواند (خیلی سریع)
$data = $cache->get('expensive_data');
```

### نمونه 2: ساختار لایه‌ای مبتنی بر کاربرد

```php
// برای داده‌های پرتکرار
$hotCache = new MultiTierCache([
    new ApcuStorage('hot:'),
]);

// برای داده‌های متوسط
$warmCache = new MultiTierCache([
    new ApcuStorage('warm:'),
    new RedisStorage($redis, 'warm:'),
]);

// برای داده‌های کم‌تکرار
$coldCache = new MultiTierCache([
    new RedisStorage($redis, 'cold:'),
    new FileStorage('/cache/cold'),
]);

// استفاده
$hotCache->set('session_' . $userId, $session, 300);
$warmCache->set('user_' . $userId, $userData, 3600);
$coldCache->set('report_' . $date, $report, 86400);
```

---

## Laravel Integration

### نصب و پیکربندی

```bash
composer require iprodev/php-easycache
php artisan vendor:publish --tag=easycache-config
```

### استفاده در Controller

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

### استفاده در Service

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
            600,  // 10 دقیقه
            120,  // 2 دقیقه stale
            1800  // 30 دقیقه اگر خطا
        );
    }

    public function clearCache()
    {
        EasyCache::delete('featured_products');
    }
}
```

### Middleware برای Cache

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

        // بررسی cache
        if ($cached = EasyCache::get($key)) {
            return response($cached);
        }

        // ادامه درخواست
        $response = $next($request);

        // ذخیره در cache
        if ($response->isSuccessful()) {
            EasyCache::set($key, $response->getContent(), $ttl);
        }

        return $response;
    }
}
```

---

## الگوهای کاربردی

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

// استفاده
$limiter = new RateLimiter($cache, 100, 1);
$key = 'api:' . $request->ip();

if ($limiter->tooManyAttempts($key)) {
    die('Too many requests. Please try again later.');
}

$limiter->hit($key);
// ادامه درخواست...
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

// ثبت handler
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

// استفاده در template
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
        
        // ذخیره کلید در لیست هر تگ
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

// استفاده
$taggedCache = new TaggedCache($cache);

// ذخیره با تگ
$taggedCache->tags(['users', 'profiles'])->put('user_1', $userData, 3600);
$taggedCache->tags(['users', 'profiles'])->put('user_2', $userData2, 3600);

// بازیابی
$user = $taggedCache->tags(['users', 'profiles'])->get('user_1');

// پاک کردن همه آیتم‌های یک تگ
$taggedCache->tags(['users'])->flush();
```

---

## بهینه‌سازی عملکرد

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
        // آماده‌سازی
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

echo "نوشتن: {$results['writes']['time']} ثانیه ({$results['writes']['ops']} ops/s)\n";
echo "خواندن: {$results['reads']['time']} ثانیه ({$results['reads']['ops']} ops/s)\n";
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

// استفاده
$monitor = new CacheMonitor($cache);

for ($i = 0; $i < 100; $i++) {
    $monitor->get("key_{$i}");
}

print_r($monitor->getStats());
// ['hits' => 50, 'misses' => 50, 'total' => 100, 'hit_rate' => '50.00%']
```

---

## نکات و ترفندها

### 1. استفاده از TTL مناسب

```php
// داده‌های تغییر سریع
$cache->set('stock_price', $price, 60);  // 1 دقیقه

// داده‌های متوسط
$cache->set('user_profile', $user, 1800);  // 30 دقیقه

// داده‌های ثابت
$cache->set('countries', $countries, 86400);  // 1 روز

// داده‌های دائمی (تا زمان پاک شدن دستی)
$cache->set('app_config', $config, 0);  // بی‌نهایت
```

### 2. Namespace کردن کلیدها

```php
// بد
$cache->set('user', $data);
$cache->set('product', $data);

// خوب
$cache->set('user:profile:123', $data);
$cache->set('product:details:456', $data);
$cache->set('api:weather:tehran', $data);
```

### 3. استفاده از SWR برای عملیات گران

```php
// بد: هر بار query می‌زند
$products = getProductsFromDatabase();

// خوب: از cache استفاده می‌کند
$products = $cache->getOrSetSWR(
    'products',
    fn() => getProductsFromDatabase(),
    300, 60, 600
);
```

### 4. پاکسازی منظم

```php
// ایجاد یک cron job
// 0 3 * * * php /path/to/cleanup.php

<?php
require 'vendor/autoload.php';

$cache = // ... initialize cache

// پاکسازی آیتم‌های منقضی شده
$pruned = $cache->prune();
echo "تعداد {$pruned} آیتم پاک شد\n";

// یا پاک کردن کامل
$cache->clear();
```

---

برای مثال‌های بیشتر، [API.md](API.md) و [PERFORMANCE.md](PERFORMANCE.md) را ببینید.

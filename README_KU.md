# 🚀 PHP EasyCache v3 — کتێبخانەی Cache چەند ڕیزە لەگەڵ SWR

**EasyCache** کتێبخانەیەکی پیشەیی و تەواوە بۆ cache کردن کە ستانداردی **PSR-16** جێبەجێ دەکات و تایبەتمەندییە پێشکەوتووەکانی خوارەوەی هەیە:

- 🚀 هەڵگرتنی چەند ڕیزە: **APCu، Redis، File و PDO (MySQL/PostgreSQL/SQLite)**
- 🔒 **نووسینی ئەتۆمی** و **قفڵی خوێندنەوە** بۆ file storage
- ⚡ **SWR تەواو** (*stale-while-revalidate* + *stale-if-error*) لەگەڵ قفڵی نابلۆک
- 🔧 **Serializer و Compressor گۆڕاو** (PHP/JSON + هیچ/Gzip/Zstd)
- 🔄 **Backfill خۆکار** لە نێوان ڕیزەکان
- 🎯 یەکخستنی تەواو لەگەڵ **Laravel**
- ✅ **داپۆشینی تێستی تەواو** لەگەڵ PHPUnit
- 🛡️ **بەڕێوەبردنی هەڵەی باشکراو** لەگەڵ پشتگیری logging

> وەشان: **v3.0.1** — پێویستی بە **PHP 8.1+** و `psr/simple-cache:^3` هەیە

---

## 📦 دامەزراندن

```bash
composer require iprodev/php-easycache
```

### پێداویستییە هەڵبژێردراوەکان

- `ext-apcu` بۆ ڕیزی APCu
- `ext-redis` یان `predis/predis:^2.0` بۆ ڕیزی Redis
- `ext-zlib` بۆ پەستاندنی Gzip
- `ext-zstd` بۆ پەستاندنی Zstd

---

## 🚀 دەستپێکردنی خێرا

```php
use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Storage\ApcuStorage;
use Iprodev\EasyCache\Storage\RedisStorage;
use Iprodev\EasyCache\Storage\FileStorage;
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Compression\GzipCompressor;

// ڕیزەکان: APCu -> Redis -> File
$apcu  = new ApcuStorage('ec:');

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

// API ی ستانداردی PSR-16
$cache->set('user_42', ['id'=>42, 'name'=>'ئەحمەد'], 300);
$data = $cache->get('user_42');
```

---

## 🎯 تایبەتمەندییە سەرەکییەکان

### 1. Cache چەند ڕیزە

ڕێکخستنی cache لە ڕیزە جیاوازەکاندا لە خێراترین بۆ هێواشترین:

```php
$cache = new MultiTierCache(
    [
        new ApcuStorage('app:'),      // خێرا: لە یادگە
        new RedisStorage($redis),     // ناوەند: تۆڕ
        new FileStorage('/cache')     // هێواش: دیسک
    ],
    new NativeSerializer(),
    new NullCompressor(),
    3600
);
```

### 2. Stale-While-Revalidate (SWR)

کاتێک داتا بەسەردەچێت، **داتای کۆن دەستبەجێ دەگەڕێتەوە** و **نوێکردنەوە لە پاشبنەمادا** ئەنجام دەدرێت:

```php
$result = $cache->getOrSetSWR(
    key: 'posts_homepage',
    producer: function () {
        return fetchPostsFromDatabase();
    },
    ttl: 300,                  // 5 خولەک تازە
    swrSeconds: 120,           // 2 خولەک کۆن
    staleIfErrorSeconds: 600,  // 10 خولەک ئەگەر هەڵە ڕووبدات
    options: ['mode' => 'defer']
);
```

### 3. Serializer و Compressor گۆڕاو

```php
// Serializer ی نیشتیمانی PHP (پشتگیری لە objects)
$cache = new MultiTierCache(
    [$storage], 
    new NativeSerializer(),
    new GzipCompressor(5)
);

// Serializer ی JSON (خێراتر بۆ داتا سادەکان)
$cache = new MultiTierCache(
    [$storage],
    new JsonSerializer(),
    new ZstdCompressor(3)
);
```

---

## 💾 Backend ەکانی هەڵگرتن

### APCu
Cache ی یادگەیی زۆر خێرا

```php
$storage = new ApcuStorage('myapp:');
```

### Redis
Cache ی تۆڕی لەگەڵ توانای هاوبەشکردن

```php
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$storage = new RedisStorage($redis, 'myapp:');
```

### File
Cache ی دیسک لەگەڵ sharding

```php
$storage = new FileStorage(
    path: '/var/cache/myapp',
    ext: '.cache',
    shards: 2
);
```

### PDO
Cache ی بنکەی دراوە (MySQL/PostgreSQL/SQLite)

```php
$pdo = new PDO('mysql:host=localhost;dbname=cache', 'user', 'pass');
$storage = new PdoStorage($pdo, 'easycache');
$storage->ensureTable();
```

---

## 🎨 نموونەی کاربەردی

### نموونە 1: Cache سادە

```php
$cache = new MultiTierCache([new FileStorage('/cache')]);

// هەڵگرتن بۆ 1 کاتژمێر
$cache->set('user_profile', [
    'id' => 123,
    'name' => 'ئەحمەد حەمە',
    'email' => 'ahmad@example.com'
], 3600);

// گەڕاندنەوە
$profile = $cache->get('user_profile');
```

### نموونە 2: SWR بۆ API

```php
$posts = $cache->getOrSetSWR(
    key: 'api_posts',
    producer: function() use ($apiClient) {
        return $apiClient->fetchPosts();
    },
    ttl: 300,          // 5 خولەک تازە
    swrSeconds: 60,    // 1 خولەک کۆن
    staleIfErrorSeconds: 300,
    options: ['mode' => 'defer']
);
```

### نموونە 3: کارپێکردنی کۆمەڵانە

```php
// هەڵگرتنی چەندین
$cache->setMultiple([
    'key1' => 'value1',
    'key2' => 'value2',
    'key3' => 'value3',
], 3600);

// گەڕاندنەوەی چەندین
$results = $cache->getMultiple(['key1', 'key2', 'missing'], 'default');
```

---

## 🎭 یەکخستن لەگەڵ Laravel

### دامەزراندن

```bash
composer require iprodev/php-easycache
php artisan vendor:publish --tag=easycache-config
```

### بەکارهێنان

```php
use EasyCache;

// کارپێکردنە سادەکان
EasyCache::set('user_settings', $settings, 3600);
$settings = EasyCache::get('user_settings');

// شێوازی SWR
$data = EasyCache::getOrSetSWR(
    'dashboard_stats',
    fn() => $this->computeStats(),
    300, 60, 300
);
```

---

## 🧪 تێست و کوالیتی

### جێبەجێکردنی تێستەکان

```bash
# جێبەجێکردنی هەموو تێستەکان
composer test

# لەگەڵ coverage
composer test:coverage

# پشکنینی ستانداردی کۆد
composer cs

# شیکردنەوەی ستاتیک
composer stan

# جێبەجێکردنی هەموو چەککردنەکان
composer qa
```

### داپۆشینی تێست

کتێبخانە تێستی تەواوی هەیە بۆ:
- ✅ هەموو backend ەکانی storage
- ✅ Multi-tier caching لەگەڵ backfill
- ✅ توانای SWR
- ✅ Serializer و Compressor ەکان
- ✅ پشکنینی کلیل
- ✅ میکانیزمی قفڵ
- ✅ دۆخە هەڵەکان و edge case ەکان

---

## 📚 بەڵگەنامە

### بەڵگەنامەی کوردی
- [README_KU.md](README_KU.md) - ئەم فایلە
- [IMPROVEMENTS_SUMMARY.md](IMPROVEMENTS_SUMMARY.md) - کورتەی تەواوی گۆڕانکارییەکان

### بەڵگەنامەی فارسی
- [README_FA.md](README_FA.md) - وەشانی فارسی

### بەڵگەنامەی ئینگلیزی
- [README.md](README.md) - ڕێنمایی سەرەکی
- [EXAMPLES.md](EXAMPLES.md) - نموونەی کاربەردی
- [API.md](API.md) - سەرچاوەی تەواوی API
- [PERFORMANCE.md](PERFORMANCE.md) - ڕێنمایی باشکردن
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md) - چارەسەری کێشەکان
- [CONTRIBUTING.md](CONTRIBUTING.md) - ڕێنمایی بەشداریکردن

---

## 🔑 یاساکانی کلیل (PSR-16)

- **کاراکتەرە ڕێگەپێدراوەکان:** `[A-Za-z0-9_.]`
- **درێژترین درێژی:** 64 کاراکتەر
- **کاراکتەرە قەدەغەکراوەکان:** `{ } ( ) / \ @ :`

```php
// کلیلە دروستەکان
$cache->set('user_123', $data);
$cache->set('posts.latest', $data);

// کلیلە هەڵەکان
$cache->set('user:123', $data);    // هەڵە: : ی تێدایە
$cache->set('user/123', $data);    // هەڵە: / ی تێدایە
```

---

## ⚡ خاڵی باشکردن

### 1. APCu وەک یەکەم ڕیز بەکاربهێنە
```php
$cache = new MultiTierCache([
    new ApcuStorage(),  // خێراترین
    new RedisStorage($redis),
    new FileStorage('/cache')
]);
```

### 2. compression بەکاربهێنە بۆ داتا گەورەکان
```php
$cache = new MultiTierCache(
    [$storage],
    new JsonSerializer(),
    new GzipCompressor(3)  // پەستاندن
);
```

### 3. TTL گونجاو ڕێکبخە
```php
// داتای زۆر بەکارهاتوو
$cache->set('hot_data', $data, 60);      // 1 خولەک

// داتای کەم گۆڕان
$cache->set('config', $data, 3600);      // 1 کاتژمێر

// داتای جێگیر
$cache->set('countries', $data, 86400);  // 1 ڕۆژ
```

### 4. SWR بەکاربهێنە بۆ کارپێکردنە گرانەکان
```php
$data = $cache->getOrSetSWR(
    'expensive_query',
    fn() => expensiveOperation(),
    300, 60, 600,
    ['mode' => 'defer']  // وەڵامی خێراتر
);
```

---

## 🐛 چارەسەری کێشە باوەکان

### APCu بەردەست نییە
```bash
sudo apt-get install php-apcu
sudo systemctl restart php8.1-fpm
```

### Redis پەیوەندی نییە
```bash
sudo systemctl start redis
redis-cli ping  # دەبێت PONG بگەڕێنێتەوە
```

### کێشەی دەستپێگەیشتن لە File
```bash
sudo mkdir -p /var/cache/app
sudo chown www-data:www-data /var/cache/app
sudo chmod 770 /var/cache/app
```

بۆ زانیاری زیاتر: [TROUBLESHOOTING.md](TROUBLESHOOTING.md)

---

## 📊 تایبەتمەندییە سەرەکییەکان

### پێش باشکردن:
- ❌ بەڕێوەبردنی هەڵەی لاواز
- ❌ بێ داپۆشینی تێست
- ❌ بەڵگەنامەی سنووردار
- ❌ نموونەی کەم
- ❌ بێ ڕێنمایی چارەسەری کێشە

### دوای باشکردن:
- ✅ بەڕێوەبردنی هەڵەی تەواو لەگەڵ logging
- ✅ داپۆشینی تێست 80%+
- ✅ بەڵگەنامەی تەواو (3000+ هێڵ)
- ✅ 50+ نموونەی کاربەردی
- ✅ ڕێنمایی تەواوی چارەسەری کێشە
- ✅ ڕێنمایی کارایی لەگەڵ benchmarks
- ✅ ڕێنماییەکانی بەشداریکردن
- ✅ سەرچاوەی تەواوی API

---

## 🎯 ئەنجام

کتێبخانەی PHP-EasyCache بووە بە کتێبخانەیەکی **production-ready** لەگەڵ:
- کوالیتیی کۆدی بەرز
- داپۆشینی تێستی تەواو
- بەڵگەنامەی نایاب
- بەڕێوەبردنی هەڵەی پیشەیی
- نموونەی کاربەردیی زۆر

و ئامادەیە بۆ بەکارهێنان لە پڕۆژە گەورەکاندا! 🚀

---

## 🤝 بەشداریکردن

بەشداریکردنت پێشوازی لێدەکرێت! تکایە [CONTRIBUTING.md](CONTRIBUTING.md) بخوێنەوە.

```bash
git clone https://github.com/iprodev/php-easycache.git
cd php-easycache
composer install
composer test
```

---

## 📄 مۆڵەت

MIT © iprodev

---

## 🔗 بەستەرە سوودبەخشەکان

- [مرجع API - English](API.md)
- [مرجع API - فارسی](API_FA.md)
- [مرجع API - کوردی](API_KU.md)
- [نموونە - English](EXAMPLES.md)
- [نموونە - فارسی](EXAMPLES_FA.md)
- [نموونە - کوردی](EXAMPLES_KU.md)
- [ڕێنمایی باشکردن](PERFORMANCE.md)
- [چارەسەری کێشەکان](TROUBLESHOOTING.md)
- [ڕاپۆرتی باشکردنەکان](IMPROVEMENTS_SUMMARY.md)
- [GitHub Issues](https://github.com/iprodev/php-easycache/issues)

---

## 💬 پشتگیری

- 📧 ئیمەیڵ: dev@iprodev.com
- 🐛 ڕاپۆرتی هەڵە: [GitHub Issues](https://github.com/iprodev/php-easycache/issues)
- 💡 گفتوگۆ: [GitHub Discussions](https://github.com/iprodev/php-easycache/discussions)

---

## 📖 نموونەی بەکارهێنانی تەواو

### نموونەی کاربەردیی ڕاستەقینە

```php
<?php
require 'vendor/autoload.php';

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Storage\ApcuStorage;
use Iprodev\EasyCache\Storage\RedisStorage;
use Iprodev\EasyCache\Storage\FileStorage;
use Iprodev\EasyCache\Serialization\JsonSerializer;
use Iprodev\EasyCache\Compression\GzipCompressor;

// ڕێکخستنی Redis
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

// دروستکردنی cache لەگەڵ سێ ڕیز
$cache = new MultiTierCache(
    tiers: [
        new ApcuStorage('myapp:'),           // ڕیزی یەکەم: خێرا
        new RedisStorage($redis, 'myapp:'),  // ڕیزی دووەم: هاوبەش
        new FileStorage('/var/cache/myapp')  // ڕیزی سێیەم: جێگیر
    ],
    serializer: new JsonSerializer(),
    compressor: new GzipCompressor(3),
    defaultTtl: 3600  // 1 کاتژمێر
);

// بەکارهێنانی ئاسایی
$cache->set('user_profile', [
    'name' => 'ئەحمەد',
    'email' => 'ahmad@example.com',
    'age' => 25
], 1800);  // 30 خولەک

// گەڕاندنەوە
$profile = $cache->get('user_profile');
echo "ناو: " . $profile['name'] . "\n";

// بەکارهێنانی SWR بۆ API
$posts = $cache->getOrSetSWR(
    key: 'blog_posts',
    producer: function() {
        // داواکاریی گران لە API
        sleep(2); // خاوێنکردنەوە بۆ 2 چرکە
        return [
            ['title' => 'پۆستی یەکەم', 'date' => '2025-01-01'],
            ['title' => 'پۆستی دووەم', 'date' => '2025-01-02'],
        ];
    },
    ttl: 600,                  // 10 خولەک تازە
    swrSeconds: 120,           // 2 خولەک کۆن
    staleIfErrorSeconds: 1800, // 30 خولەک ئەگەر هەڵە
    options: ['mode' => 'defer']
);

foreach ($posts as $post) {
    echo "- " . $post['title'] . "\n";
}

// کارپێکردنی کۆمەڵانە
$cache->setMultiple([
    'setting1' => 'value1',
    'setting2' => 'value2',
    'setting3' => 'value3',
], 7200);

// پاککردنەوە
// $cache->clear();
```

---

## 🌟 تایبەتمەندییە پێشکەوتووەکان

### 1. Multi-Tier لەگەڵ Backfill

```php
// کاتێک داتا لە Redis دەدۆزرێتەوە،
// خۆکار دەنێردرێتەوە بۆ APCu
$cache = new MultiTierCache([
    new ApcuStorage(),    // L1: خێرا
    new RedisStorage($r), // L2: هاوبەش
    new FileStorage('/c') // L3: جێگیر
]);

// داواکاریی یەکەم: دەدۆزرێتەوە لە File، دەنێردرێت بۆ Redis و APCu
$data = $cache->get('key');

// داواکاریی دووەم: زۆر خێرا لە APCu دەدۆزرێتەوە!
$data = $cache->get('key');
```

### 2. Rate Limiting

```php
class RateLimiter
{
    private $cache;

    public function check(string $ip): bool
    {
        $key = "rate_limit_{$ip}";
        $attempts = $this->cache->get($key) ?? 0;

        if ($attempts >= 100) {
            return false; // زۆر داواکاری
        }

        $this->cache->set($key, $attempts + 1, 3600);
        return true;
    }
}

$limiter = new RateLimiter($cache);
if (!$limiter->check($_SERVER['REMOTE_ADDR'])) {
    die('زۆر داواکاری. تکایە دواتر هەوڵبدەرەوە.');
}
```

### 3. Cache کردنی Query ی بنکەی دراوە

```php
function getProducts(PDO $db, MultiTierCache $cache): array
{
    return $cache->getOrSetSWR(
        key: 'products_list',
        producer: function() use ($db) {
            $stmt = $db->query("
                SELECT p.*, COUNT(r.id) as reviews
                FROM products p
                LEFT JOIN reviews r ON p.id = r.product_id
                GROUP BY p.id
                ORDER BY p.name
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        },
        ttl: 600,      // 10 خولەک
        swrSeconds: 60,
        staleIfErrorSeconds: 1800
    );
}
```

---

## 🎓 فێربوونی زیاتر

### کتێبە پێشنیارکراوەکان

1. **دەستپێکردن:**
   - [README.md](README.md) - ڕێنمایی سەرەکی
   - [EXAMPLES.md](EXAMPLES.md) - نموونەی کاربەردی

2. **پێشکەوتوو:**
   - [API.md](API.md) - سەرچاوەی تەواوی API
   - [PERFORMANCE.md](PERFORMANCE.md) - باشکردنی کارایی

3. **چارەسەرکردن:**
   - [TROUBLESHOOTING.md](TROUBLESHOOTING.md) - چارەسەری کێشە
   - [CONTRIBUTING.md](CONTRIBUTING.md) - بەشداریکردن

---

## 💡 ئامۆژگاری و خاڵی باش

### بەکارهێنانی لەگەڵ Laravel

```php
// app/Providers/AppServiceProvider.php
use Iprodev\EasyCache\Cache\MultiTierCache;

public function register()
{
    $this->app->singleton('easycache', function ($app) {
        return new MultiTierCache([
            new ApcuStorage(),
            new RedisStorage(
                $app->make('redis')->connection()->client()
            )
        ]);
    });
}

// بەکارهێنان لە Controller
public function index()
{
    $data = app('easycache')->getOrSetSWR(
        'dashboard_data',
        fn() => $this->loadDashboardData(),
        300, 60, 600
    );

    return view('dashboard', compact('data'));
}
```

### Monitoring و چاودێری

```php
class CacheMonitor
{
    private $cache;
    private $hits = 0;
    private $misses = 0;

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
        return $total > 0 ? ($this->hits / $total) * 100 : 0;
    }

    public function report(): string
    {
        return sprintf(
            "Hit Rate: %.2f%% (Hits: %d, Misses: %d)",
            $this->getHitRate(),
            $this->hits,
            $this->misses
        );
    }
}
```

---

## 🔐 پارێزراوی و سەلامەتی

### باشترین ڕێگاکانی سەلامەتی

```php
// 1. بەکارهێنانی namespace بۆ کلیلەکان
$cache = new MultiTierCache(
    [new ApcuStorage('myapp:')]  // namespace
);

// 2. پاراستنی زانیاری هەستیار
$sensitiveData = [
    'credit_card' => '****-****-****-1234',  // نا تەواو
    'password' => password_hash($pass, PASSWORD_DEFAULT)  // hash کراو
];
$cache->set('user_data', $sensitiveData, 300);

// 3. بەکارهێنانی TTL گونجاو
$cache->set('session', $data, 1800);  // 30 خولەک بۆ session

// 4. پاککردنەوەی خۆکار
// دروستکردنی cron job
// 0 2 * * * php /path/to/cleanup.php
```

---

**دروستکراوە لەگەڵ ❤️ بۆ کۆمەڵگەی PHP**

---

## 📞 پەیوەندی

ئەگەر پرسیار یان پێشنیارت هەیە:

- 📧 **ئیمەیڵ:** dev@iprodev.com
- 🐛 **ڕاپۆرتی کێشە:** [GitHub Issues](https://github.com/iprodev/php-easycache/issues)
- 💬 **گفتوگۆ:** [GitHub Discussions](https://github.com/iprodev/php-easycache/discussions)
- 📚 **بەڵگەنامە:** [Documentation](https://github.com/iprodev/php-easycache/wiki)

سوپاس بۆ بەکارهێنانی PHP EasyCache! 🎉

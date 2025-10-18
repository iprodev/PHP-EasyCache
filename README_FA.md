# 🚀 PHP EasyCache v3 — کتابخانه Cache چند لایه با SWR

**EasyCache** یک کتابخانه cache حرفه‌ای و کامل است که استاندارد **PSR-16** را پیاده‌سازی می‌کند و قابلیت‌های پیشرفته زیر را دارد:

- 🚀 ذخیره‌سازی چند لایه: **APCu، Redis، File و PDO (MySQL/PostgreSQL/SQLite)**
- 🔒 **نوشتن اتمی** و **قفل خواندن** برای file storage
- ⚡ **SWR کامل** (*stale-while-revalidate* + *stale-if-error*) با قفل‌های غیربلاکه
- 🔧 **Serializer و Compressor قابل تعویض** (PHP/JSON + هیچ/Gzip/Zstd)
- 🔄 **Backfill خودکار** بین لایه‌ها
- 🎯 یکپارچه‌سازی کامل با **Laravel**
- ✅ **پوشش تست جامع** با PHPUnit
- 🛡️ **مدیریت خطای بهبود یافته** با پشتیبانی logging

> نسخه: **v3.0.1** — نیازمند **PHP 8.1+** و `psr/simple-cache:^3`

---

## 📦 نصب

```bash
composer require iprodev/php-easycache
```

### وابستگی‌های اختیاری

- `ext-apcu` برای لایه APCu
- `ext-redis` یا `predis/predis:^2.0` برای لایه Redis
- `ext-zlib` برای فشرده‌سازی Gzip
- `ext-zstd` برای فشرده‌سازی Zstd

---

## 🚀 شروع سریع

```php
use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Storage\ApcuStorage;
use Iprodev\EasyCache\Storage\RedisStorage;
use Iprodev\EasyCache\Storage\FileStorage;
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Compression\GzipCompressor;

// لایه‌ها: APCu -> Redis -> File
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

// API استاندارد PSR-16
$cache->set('user_42', ['id'=>42, 'name'=>'علی'], 300);
$data = $cache->get('user_42');
```

---

## 🎯 ویژگی‌های اصلی

### 1. Cache چند لایه

سازماندهی cache در لایه‌های مختلف از سریع‌ترین به کندترین:

```php
$cache = new MultiTierCache(
    [
        new ApcuStorage('app:'),      // سریع: در حافظه
        new RedisStorage($redis),     // متوسط: شبکه
        new FileStorage('/cache')     // کند: دیسک
    ],
    new NativeSerializer(),
    new NullCompressor(),
    3600
);
```

### 2. Stale-While-Revalidate (SWR)

وقتی داده منقضی می‌شود، **داده قدیمی فوراً برگردانده** و **به‌روزرسانی در پس‌زمینه** انجام می‌شود:

```php
$result = $cache->getOrSetSWR(
    key: 'posts_homepage',
    producer: function () {
        return fetchPostsFromDatabase();
    },
    ttl: 300,                  // 5 دقیقه تازه
    swrSeconds: 120,           // 2 دقیقه قدیمی
    staleIfErrorSeconds: 600,  // 10 دقیقه اگر خطا رخ داد
    options: ['mode' => 'defer']
);
```

### 3. Serializer و Compressor قابل تعویض

```php
// Serializer نیتیو PHP (پشتیبانی از objects)
$cache = new MultiTierCache(
    [$storage], 
    new NativeSerializer(),
    new GzipCompressor(5)
);

// Serializer JSON (سریع‌تر برای داده‌های ساده)
$cache = new MultiTierCache(
    [$storage],
    new JsonSerializer(),
    new ZstdCompressor(3)
);
```

---

## 💾 Backend های ذخیره‌سازی

### APCu
Cache حافظه‌ای فوق‌سریع

```php
$storage = new ApcuStorage('myapp:');
```

### Redis
Cache شبکه‌ای با قابلیت اشتراک‌گذاری

```php
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$storage = new RedisStorage($redis, 'myapp:');
```

### File
Cache دیسکی با sharding

```php
$storage = new FileStorage(
    path: '/var/cache/myapp',
    ext: '.cache',
    shards: 2
);
```

### PDO
Cache پایگاه داده (MySQL/PostgreSQL/SQLite)

```php
$pdo = new PDO('mysql:host=localhost;dbname=cache', 'user', 'pass');
$storage = new PdoStorage($pdo, 'easycache');
$storage->ensureTable();
```

---

## 🎨 مثال‌های کاربردی

### مثال 1: Cache ساده

```php
$cache = new MultiTierCache([new FileStorage('/cache')]);

// ذخیره برای 1 ساعت
$cache->set('user_profile', [
    'id' => 123,
    'name' => 'علی احمدی',
    'email' => 'ali@example.com'
], 3600);

// بازیابی
$profile = $cache->get('user_profile');
```

### مثال 2: SWR برای API

```php
$posts = $cache->getOrSetSWR(
    key: 'api_posts',
    producer: function() use ($apiClient) {
        return $apiClient->fetchPosts();
    },
    ttl: 300,          // 5 دقیقه تازه
    swrSeconds: 60,    // 1 دقیقه قدیمی
    staleIfErrorSeconds: 300,
    options: ['mode' => 'defer']
);
```

### مثال 3: عملیات دسته‌ای

```php
// ذخیره چندتایی
$cache->setMultiple([
    'key1' => 'value1',
    'key2' => 'value2',
    'key3' => 'value3',
], 3600);

// بازیابی چندتایی
$results = $cache->getMultiple(['key1', 'key2', 'missing'], 'default');
```

---

## 🎭 یکپارچه‌سازی با Laravel

### نصب

```bash
composer require iprodev/php-easycache
php artisan vendor:publish --tag=easycache-config
```

### استفاده

```php
use EasyCache;

// عملیات ساده
EasyCache::set('user_settings', $settings, 3600);
$settings = EasyCache::get('user_settings');

// الگوی SWR
$data = EasyCache::getOrSetSWR(
    'dashboard_stats',
    fn() => $this->computeStats(),
    300, 60, 300
);
```

---

## 🧪 تست و کیفیت

### اجرای تست‌ها

```bash
# اجرای همه تست‌ها
composer test

# با coverage
composer test:coverage

# بررسی استاندارد کد
composer cs

# تحلیل استاتیک
composer stan

# اجرای همه چک‌ها
composer qa
```

### پوشش تست

کتابخانه شامل تست‌های جامع برای:
- ✅ همه backend های storage
- ✅ Multi-tier caching با backfill
- ✅ قابلیت SWR
- ✅ Serializer ها و Compressor ها
- ✅ اعتبارسنجی کلید
- ✅ مکانیزم قفل
- ✅ حالت‌های خطا و edge case ها

---

## 📚 مستندات

### مستندات فارسی
- [گزارش بهبودها](IMPROVEMENTS_SUMMARY.md) - خلاصه کامل تغییرات

### مستندات انگلیسی
- [README.md](README.md) - راهنمای اصلی
- [EXAMPLES.md](EXAMPLES.md) - مثال‌های کاربردی
- [API.md](API.md) - مرجع کامل API
- [PERFORMANCE.md](PERFORMANCE.md) - راهنمای بهینه‌سازی
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md) - حل مشکلات
- [CONTRIBUTING.md](CONTRIBUTING.md) - راهنمای مشارکت

---

## 🔑 قوانین کلید (PSR-16)

- **کاراکترهای مجاز:** `[A-Za-z0-9_.]`
- **حداکثر طول:** 64 کاراکتر
- **کاراکترهای ممنوع:** `{ } ( ) / \ @ :`

```php
// کلیدهای معتبر
$cache->set('user_123', $data);
$cache->set('posts.latest', $data);

// کلیدهای نامعتبر
$cache->set('user:123', $data);    // خطا: دارای :
$cache->set('user/123', $data);    // خطا: دارای /
```

---

## ⚡ نکات بهینه‌سازی

### 1. از APCu به عنوان لایه اول استفاده کنید
```php
$cache = new MultiTierCache([
    new ApcuStorage(),  // سریع‌ترین
    new RedisStorage($redis),
    new FileStorage('/cache')
]);
```

### 2. از compression برای داده‌های بزرگ استفاده کنید
```php
$cache = new MultiTierCache(
    [$storage],
    new JsonSerializer(),
    new GzipCompressor(3)  // فشرده‌سازی
);
```

### 3. TTL مناسب تنظیم کنید
```php
// داده پرتکرار
$cache->set('hot_data', $data, 60);      // 1 دقیقه

// داده کم‌تغییر
$cache->set('config', $data, 3600);      // 1 ساعت

// داده ثابت
$cache->set('countries', $data, 86400);  // 1 روز
```

### 4. از SWR برای عملیات گران استفاده کنید
```php
$data = $cache->getOrSetSWR(
    'expensive_query',
    fn() => expensiveOperation(),
    300, 60, 600,
    ['mode' => 'defer']  // پاسخ سریع‌تر
);
```

---

## 🐛 حل مشکلات رایج

### APCu موجود نیست
```bash
sudo apt-get install php-apcu
sudo systemctl restart php8.1-fpm
```

### Redis به دست نمی‌آید
```bash
sudo systemctl start redis
redis-cli ping  # باید PONG برگرداند
```

### مشکل دسترسی File
```bash
sudo mkdir -p /var/cache/app
sudo chown www-data:www-data /var/cache/app
sudo chmod 770 /var/cache/app
```

برای اطلاعات بیشتر: [TROUBLESHOOTING.md](TROUBLESHOOTING.md)

---

## 🤝 مشارکت

مشارکت شما استقبال می‌شود! لطفاً [CONTRIBUTING.md](CONTRIBUTING.md) را بخوانید.

```bash
git clone https://github.com/iprodev/php-easycache.git
cd php-easycache
composer install
composer test
```

---

## 📄 مجوز

MIT © iprodev

---

## 🔗 لینک‌های مفید

- [مرجع API - English](API.md)
- [مرجع API - فارسی](API_FA.md)
- [مرجع API - کوردی](API_KU.md)
- [مثال‌ها - English](EXAMPLES.md)
- [مثال‌ها - فارسی](EXAMPLES_FA.md)
- [مثال‌ها - کوردی](EXAMPLES_KU.md)
- [راهنمای بهینه‌سازی](PERFORMANCE.md)
- [حل مشکلات](TROUBLESHOOTING.md)
- [گزارش بهبودها](IMPROVEMENTS_SUMMARY.md)
- [GitHub Issues](https://github.com/iprodev/php-easycache/issues)

---

## 💬 پشتیبانی

- 📧 ایمیل: dev@iprodev.com
- 🐛 گزارش باگ: [GitHub Issues](https://github.com/iprodev/php-easycache/issues)
- 💡 بحث و گفتگو: [GitHub Discussions](https://github.com/iprodev/php-easycache/discussions)

---

**ساخته شده با ❤️ برای جامعه PHP**

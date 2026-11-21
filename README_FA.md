# 🚀 PHP EasyCache v4 — کتابخانه Cache چند لایه با SWR

**EasyCache** یک کتابخانه cache حرفه‌ای و کامل است که استاندارد **PSR-16** را پیاده‌سازی می‌کند و قابلیت‌های پیشرفته زیر را دارد:

- 🚀 ذخیره‌سازی چند لایه: **APCu، Redis، File و PDO (MySQL/PostgreSQL/SQLite)**
- 🔒 **نوشتن اتمی** برای file storage و **جلوگیری از cache stampede** با قفل مخصوص هر کلید (فایلی یا Redis)
- ⚡ **SWR کامل** (*stale-while-revalidate* + *stale-if-error*)
- 🛡️ **امن به‌طور پیش‌فرض**: هیچ شیئی unserialize نمی‌شود مگر خودتان اجازه دهید، و آن هم فقط از رکوردهای امضاشده با HMAC
- 🔧 **Serializer و Compressor قابل تعویض** (PHP/JSON + هیچ/Gzip/Zstd یا پیاده‌سازی خودتان)
- 🔄 **Backfill خودکار** بین لایه‌ها
- 📦 **عملیات دسته‌ای** (`MGET`، `IN (...)`، `apcu_fetch([...])`)، **tag**، `remember()`، `forever()` و آمار
- 🎯 یکپارچه‌سازی کامل با **Laravel**: Service Provider، Facade و دستورات artisan
- ✅ تست‌شده روی File، SQLite، MySQL، PostgreSQL، Redis (phpredis و Predis) و APCu

> نسخه: **v4.0.0** (در حال توسعه) — نیازمند **PHP 8.3+** و `psr/simple-cache` نسخهٔ `^3`
> از 3.x ارتقا می‌دهید؟ [MIGRATION.md](MIGRATION.md) را بخوانید.

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

$file  = new FileStorage('/var/cache/myapp');

$cache = new MultiTierCache(
    [$apcu, $redisStore, $file],
    new NativeSerializer(),
    new GzipCompressor(3),
    defaultTtl: 600,
    signingKey: getenv('EASYCACHE_SIGNING_KEY') ?: null, // کلید مخفی حداقل ۱۶ بایتی (توصیه می‌شود)
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

اگر نسخهٔ منقضی‌شده‌ای در لایهٔ سریع باشد و لایهٔ کندتر نسخهٔ تازه‌تری داشته باشد، نسخهٔ تازه برگردانده و در لایه‌های سریع‌تر نوشته می‌شود.

> ⚠️ **چند سرور دارید؟** APCu بین سرورها مشترک نیست: `set()` یا `delete()` روی سرور A روی APCu سرور B اثری ندارد. TTL لایهٔ محلی را محدود کنید (`new ApcuStorage('app:', maxTtl: 60)`) و برای جلوگیری از stampede بین سرورها از `RedisLockProvider` استفاده کنید.

### 2. Stale-While-Revalidate (SWR)

وقتی داده منقضی می‌شود ولی هنوز داخل پنجرهٔ SWR است، **دادهٔ قدیمی سرو می‌شود** و فقط یک پروسه آن را تازه می‌کند:

```php
$result = $cache->getOrSetSWR(
    key: 'posts_homepage',
    producer: function () {
        return fetchPostsFromDatabase();
    },
    ttl: 300,                  // 5 دقیقه تازه
    swrSeconds: 120,           // 2 دقیقه قدیمی
    staleIfErrorSeconds: 600,  // 10 دقیقه اگر خطا رخ داد
    mode: RefreshMode::Defer
);
```

**نحوهٔ کار:**
1. رکورد **تازه** → فوراً برگردانده می‌شود.
2. **منقضی، داخل `swrSeconds`** → یک پروسه رکورد را تازه می‌کند و بقیه بدون انتظار مقدار قدیمی را می‌گیرند.
   - `RefreshMode::Sync` (پیش‌فرض): پروسهٔ تازه‌کننده مقدار **جدید** را برمی‌گرداند.
   - `RefreshMode::Defer`: مقدار قدیمی برگردانده می‌شود و تازه‌سازی بعد از ارسال پاسخ انجام می‌شود (`fastcgi_finish_request()` یا LiteSpeed؛ در غیر این صورت مثل `Sync`).

`RefreshMode` همان `Iprodev\EasyCache\Cache\RefreshMode` است.
3. **منقضی، فقط داخل `staleIfErrorSeconds`** → مقدار دوباره محاسبه می‌شود و فقط **اگر producer خطا بدهد** مقدار قدیمی برگردانده می‌شود.
4. **ناموجود** → زیر قفل مخصوص همان کلید محاسبه می‌شود تا درخواست‌های همزمان producer را فقط یک بار اجرا کنند.

TTL برابر `null` یعنی TTL پیش‌فرض (`0` = بدون انقضا) و TTL کوچک‌تر یا مساوی صفر یعنی محاسبه بدون کش. نتیجهٔ `null` هم کش می‌شود.

### 3. امنیت: پیش‌فرض‌های امن و رکوردهای امضاشده

هر کسی که بتواند در backend کش بنویسد (Redis بدون رمز، پوشهٔ کش مشترک، جدول مشترک دیتابیس و...) در غیر این صورت می‌تواند رکورد جعلی بکارد، و unserialize کردن یک شیء جعلی می‌تواند به اجرای کد برسد. EasyCache 4 به‌طور پیش‌فرض امن است:

- `NativeSerializer` فقط مقادیر ساده و آرایه را کش می‌کند. شیئی از کلاسی که اجازه نداده‌اید هنگام نوشتن رد و هنگام خواندن نادیده گرفته می‌شود.
- برای کش کردن شیء، کلاس‌هایش را در `allowedClasses` فهرست کنید. serializerی که می‌تواند شیء بسازد **کلید امضا لازم دارد**: هر رکورد امضای HMAC-SHA256 دارد و رکوردهای بدون امضا یا دستکاری‌شده **پیش از** deserialize شدن رد می‌شوند.

```php
$cache = new MultiTierCache(
    [$redisStore],
    new NativeSerializer(allowedClasses: [UserDto::class]), // true = هر کلاسی
    signingKey: $_ENV['EASYCACHE_SIGNING_KEY'],             // حداقل ۱۶ بایت تصادفی
);
```

اگر هیچ کس جز برنامهٔ شما نمی‌تواند در هیچ لایه‌ای بنویسد، می‌توانید به‌جای کلید امضا `allowUnsignedObjects: true` بدهید.

ساختن کش با builder هم ممکن است:

```php
$cache = MultiTierCache::builder()
    ->tier(new ApcuStorage('app:', maxTtl: 60))
    ->tier(new RedisStorage($redis, 'app:'))
    ->serializer(new NativeSerializer([UserDto::class]))
    ->signingKey($_ENV['EASYCACHE_SIGNING_KEY'])
    ->defaultTtl(600)
    ->build();
```

### 4. متدهای کمکی

```php
// یک بار محاسبه و ۱۰ دقیقه کش
$user = $cache->remember('user-42', 600, fn () => $repo->find(42));

// بدون انقضا
$cache->forever('feature-flags', $flags);

// tag: باطل کردن گروهی رکوردها
$cache->tags(['users'])->set('user-42', $user);
$cache->invalidateTags('users');

// شمارندهٔ hit/miss همین نمونه
$cache->getStats(); // ['hits' => .., 'misses' => .., 'hit_ratio' => 0.93, ...]
```

### 5. Serializer و Compressor قابل تعویض

```php
// Serializer نیتیو PHP: به‌طور پیش‌فرض فقط مقادیر ساده و آرایه؛
// شیء فقط برای کلاس‌های فهرست‌شده و همراه با کلید امضا
$cache = new MultiTierCache(
    [$storage],
    new NativeSerializer([Money::class]),
    new GzipCompressor(5),
    signingKey: $key
);

// Serializer JSON (هیچ‌وقت شیء نمی‌سازد؛ اشیاء به آرایه تبدیل می‌شوند)
$cache = new MultiTierCache(
    [$storage],
    new JsonSerializer(),
    new ZstdCompressor(3)
);
```

نام compressor همراه هر رکورد ذخیره می‌شود، پس با تعویض compressor رکوردهای gzip/zstd قبلی همچنان قابل خواندن‌اند. از حالت فشرده خارج کردن به ۶۴ مگابایت محدود است تا در برابر decompression bomb محافظت شود.

---

## 💾 Backend های ذخیره‌سازی

### APCu
Cache حافظه‌ای فوق‌سریع

```php
$storage = new ApcuStorage(prefix: 'myapp:', maxTtl: 60); // prefix اجباری است و نباید خالی باشد
```

در CLI به `apc.enable_cli=1` نیاز است.

### Redis
Cache شبکه‌ای با قابلیت اشتراک‌گذاری

```php
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$storage = new RedisStorage($redis, 'myapp:'); // phpredis یا Predis؛ prefix اجباری است
```

`clear()` فقط کلیدهای همین prefix را با `SCAN` غیرمسدودکننده حذف می‌کند.

### File
Cache دیسکی با sharding

```php
$storage = new FileStorage(
    path: '/var/cache/myapp',  // بیرون از web root
    ext: '.cache',
    shards: 2,
    fileMode: 0640,            // مجوزها مستقل از umask
    dirMode: 0770
);
```

- زمان انقضا داخل هر فایل ذخیره می‌شود؛ فایل‌های منقضی نادیده گرفته می‌شوند و `prune()` آن‌ها را حذف می‌کند (`vendor/bin/easycache-prune /var/cache/myapp` یا `php artisan easycache:prune` را زمان‌بندی کنید).
- `clear()` فقط فایل‌های خود کش را حذف می‌کند و هرگز symlink را دنبال نمی‌کند.

### PDO
Cache پایگاه داده (MySQL/PostgreSQL/SQLite)

```php
$pdo = new PDO('mysql:host=localhost;dbname=cache', 'user', 'pass');
$storage = new PdoStorage($pdo, 'easycache');
$storage->ensureTable();

// جدول‌های MySQL ساخته‌شده با نسخه‌های قبل از 3.1 (محدودیت ۶۴ کیلوبایت و کلید غیرحساس به حروف)
$storage->upgradeSchema();
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
    mode: RefreshMode::Defer
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

پیکربندی از طریق `.env`:

```env
EASYCACHE_DRIVERS=apcu,redis,file   # پیش‌فرض: file
EASYCACHE_DEFAULT_TTL=600
EASYCACHE_REDIS_HOST=127.0.0.1
EASYCACHE_LOCK_DRIVER=redis         # file|redis|none
EASYCACHE_COMPRESSOR=gzip           # none|gzip|zstd
```

- رکوردها به‌طور پیش‌فرض با کلیدی مشتق از `APP_KEY` **امضا** می‌شوند (`EASYCACHE_SIGNING_KEY` برای کلید دلخواه، `EASYCACHE_SIGN=false` برای غیرفعال کردن). با امضای خاموش، کش کردن شیء فقط با `EASYCACHE_ALLOW_UNSIGNED_OBJECTS=true` یا `serializer.allowed_classes = false` ممکن است.
- درایوری که راه‌اندازی نشود (افزونهٔ ناموجود، Redis خاموش و...) با یک warning در لاگ کنار گذاشته می‌شود.
- کش از طریق `app('easycache')`، Facade یا type-hint کردن `MultiTierCache` در دسترس است.

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

### دستورات Artisan

```bash
php artisan easycache:prune   # حذف رکوردهای منقضی
php artisan easycache:clear   # حذف همهٔ رکوردها
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

- ✅ یک مجموعه تست مشترک برای همهٔ storageها که روی File، SQLite، MySQL، PostgreSQL، phpredis، Predis و APCu اجرا می‌شود
- ✅ Multi-tier caching، backfill و رجوع به لایه‌های تازه‌تر
- ✅ رفتار SWR و stale-if-error (با ساعت ثابت PSR-20 و بدون `sleep()`)
- ✅ امنیت: رکوردهای امضاشده، دستکاری‌شده و بدون امضا، تلاش برای object injection
- ✅ انطباق با PSR-16 و جلوگیری از stampede با پروسه‌های همزمان
- ✅ Serializer، Compressor، اعتبارسنجی کلید، قفل‌های فایلی و Redis
- ✅ Service Provider، Facade و دستورات Laravel و ابزار CLI

تست‌های Redis، MySQL و PostgreSQL با متغیرهای محیطی `EASYCACHE_TEST_*` فعال می‌شوند ([CONTRIBUTING.md](CONTRIBUTING.md)).

---

## 📚 مستندات

### مستندات فارسی
- [API_FA.md](API_FA.md) - مرجع کامل API
- [EXAMPLES_FA.md](EXAMPLES_FA.md) - مثال‌های کاربردی

### مستندات انگلیسی
- [README.md](README.md) - راهنمای اصلی
- [EXAMPLES.md](EXAMPLES.md) - مثال‌های کاربردی
- [API.md](API.md) - مرجع کامل API
- [PERFORMANCE.md](PERFORMANCE.md) - راهنمای بهینه‌سازی
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md) - حل مشکلات
- [CONTRIBUTING.md](CONTRIBUTING.md) - راهنمای مشارکت
- [MIGRATION.md](MIGRATION.md) - راهنمای ارتقا
- [SECURITY.md](SECURITY.md) - سیاست امنیتی و توصیه‌های امن‌سازی

---

## 🔑 قوانین کلید (PSR-16)

- **کاراکترهای مجاز:** همهٔ کاراکترهای ASCII قابل چاپ (`!` تا `~`) به جز کاراکترهای رزروشده
- **کاراکترهای ممنوع:** `{ } ( ) / \ @ :`، فاصله، کاراکترهای کنترلی (از جمله newline) و بایت‌های غیر ASCII
- **حداکثر طول:** ۲۵۵ بایت

```php
// کلیدهای معتبر
$cache->set('user_123', $data);
$cache->set('posts.latest', $data);
$cache->set('user-3f2a9c1e-77b2-4f3b-9a51-1c2d3e4f5a6b', $data);

// کلیدهای نامعتبر (Psr\SimpleCache\InvalidArgumentException)
$cache->set('user:123', $data);    // خطا: دارای :
$cache->set('user/123', $data);    // خطا: دارای /
$cache->set('user 123', $data);    // خطا: دارای فاصله
```

---

## ⚡ نکات بهینه‌سازی

### 1. از APCu به عنوان لایه اول استفاده کنید
```php
$cache = new MultiTierCache([
    new ApcuStorage('app:', maxTtl: 60),  // سریع‌ترین (TTL محدود برای چند سرور)
    new RedisStorage($redis, 'app:'),
    new FileStorage('/var/cache/app')
]);
```

### در محیط production رکوردها را امضا کنید
```php
$cache = new MultiTierCache($tiers, signingKey: $_ENV['EASYCACHE_SIGNING_KEY']);
```

### به‌جای حلقهٔ `get()` از عملیات دسته‌ای استفاده کنید
```php
$users = $cache->getMultiple(['user_1', 'user_2', 'user_3']); // در هر لایه فقط یک درخواست
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
    RefreshMode::Defer  // پاسخ سریع‌تر
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
- [راهنمای ارتقا](MIGRATION.md)
- [تغییرات](CHANGELOG.md)
- [سیاست امنیتی](SECURITY.md)
- [GitHub Issues](https://github.com/iprodev/php-easycache/issues)

---

## 💬 پشتیبانی

- 📧 ایمیل: dev@iprodev.com
- 🔐 مشکلات امنیتی: [SECURITY.md](SECURITY.md) را ببینید (لطفاً issue عمومی باز نکنید)
- 🐛 گزارش باگ: [GitHub Issues](https://github.com/iprodev/php-easycache/issues)
- 💡 بحث و گفتگو: [GitHub Discussions](https://github.com/iprodev/php-easycache/discussions)

---

**ساخته شده با ❤️ برای جامعه PHP**

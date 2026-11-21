# 🚀 PHP EasyCache v4 — کتێبخانەی Cache چەند ڕیزە لەگەڵ SWR

**EasyCache** کتێبخانەیەکی پیشەیی و تەواوە بۆ cache کردن کە ستانداردی **PSR-16** جێبەجێ دەکات و تایبەتمەندییە پێشکەوتووەکانی خوارەوەی هەیە:

- 🚀 هەڵگرتنی چەند ڕیزە: **APCu، Redis، File و PDO (MySQL/PostgreSQL/SQLite)**
- 🔒 **نووسینی ئەتۆمی** بۆ file storage و **ڕێگری لە cache stampede** بە قفڵی تایبەت بە هەر کلیلێک (فایل یان Redis)
- ⚡ **SWR تەواو** (*stale-while-revalidate* + *stale-if-error*)
- 🛡️ **سەلامەت بە بنەڕەت**: هیچ ئۆبجێکتێک unserialize ناکرێت مەگەر خۆت ڕێگە بدەیت، و ئەویش تەنها لە تۆماری واژۆکراو بە HMAC
- 🔧 **Serializer و Compressor گۆڕاو** (PHP/JSON + هیچ/Gzip/Zstd یان هی خۆت)
- 🔄 **Backfill خۆکار** لە نێوان ڕیزەکان
- 📦 **کارپێکردنی کۆمەڵ** (`MGET`، `IN (...)`، `apcu_fetch([...])`)، **tag**، `remember()`، `forever()` و ئامار
- 🎯 یەکخستنی تەواو لەگەڵ **Laravel**: Service Provider، Facade و فەرمانەکانی artisan
- ✅ تاقیکراوەتەوە لەسەر File، SQLite، MySQL، PostgreSQL، Redis (phpredis و Predis) و APCu

> وەشان: **v4.0.0** (لە گەشەپێداندا) — پێویستی بە **PHP 8.3+** و `psr/simple-cache` ی `^3` هەیە
> لە 3.x بەرز دەکەیتەوە؟ [MIGRATION.md](MIGRATION.md) بخوێنەوە.

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

$file  = new FileStorage('/var/cache/myapp');

$cache = new MultiTierCache(
    [$apcu, $redisStore, $file],
    new NativeSerializer(),
    new GzipCompressor(3),
    defaultTtl: 600,
    signingKey: getenv('EASYCACHE_SIGNING_KEY') ?: null, // کلیلی نهێنی، لایەنی کەم 16 بایت (پێشنیار دەکرێت)
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

ئەگەر وەشانێکی بەسەرچوو لە ڕیزێکی خێرادا هەبێت و ڕیزێکی هێواشتر وەشانێکی تازەتری هەبێت، وەشانە تازەکە دەگەڕێتەوە و لە ڕیزە خێراترەکاندا دەنووسرێتەوە.

> ⚠️ **چەند ڕاژەکارت هەیە؟** APCu لە نێوان ڕاژەکارەکاندا هاوبەش نییە: `set()` یان `delete()` لەسەر ڕاژەکاری A کاریگەری لەسەر APCu ی ڕاژەکاری B نییە. TTL ی ڕیزی ناوخۆیی سنووردار بکە (`new ApcuStorage('app:', maxTtl: 60)`) و بۆ ڕێگری لە stampede لە نێوان ڕاژەکارەکاندا `RedisLockProvider` بەکاربهێنە.

### 2. Stale-While-Revalidate (SWR)

کاتێک داتا بەسەردەچێت بەڵام هێشتا لەناو پەنجەرەی SWR دایە، **داتای کۆن پێشکەش دەکرێت** و تەنها یەک پرۆسە نوێی دەکاتەوە:

```php
$result = $cache->getOrSetSWR(
    key: 'posts_homepage',
    producer: function () {
        return fetchPostsFromDatabase();
    },
    ttl: 300,                  // 5 خولەک تازە
    swrSeconds: 120,           // 2 خولەک کۆن
    staleIfErrorSeconds: 600,  // 10 خولەک ئەگەر هەڵە ڕووبدات
    mode: RefreshMode::Defer
);
```

**چۆن کار دەکات:**
1. تۆماری **تازە** ← دەستبەجێ دەگەڕێتەوە.
2. **بەسەرچوو، لەناو `swrSeconds`** ← یەک پرۆسە تۆمارەکە نوێ دەکاتەوە و ئەوانی تر بەبێ چاوەڕوانی بەهای کۆن وەردەگرن.
   - `RefreshMode::Sync` (بنەڕەت): پرۆسەی نوێکەرەوە بەهای **نوێ** دەگەڕێنێتەوە.
   - `RefreshMode::Defer`: بەهای کۆن دەگەڕێتەوە و نوێکردنەوە دوای ناردنی وەڵام ئەنجام دەدرێت (`fastcgi_finish_request()` یان LiteSpeed؛ ئەگەرنا وەک `Sync`).

`RefreshMode` واتە `Iprodev\EasyCache\Cache\RefreshMode`.
3. **بەسەرچوو، تەنها لەناو `staleIfErrorSeconds`** ← بەها دووبارە هەژمار دەکرێت و تەنها **ئەگەر producer هەڵە بدات** بەهای کۆن دەگەڕێتەوە.
4. **بوونی نییە** ← لەژێر قفڵی تایبەت بەو کلیلە هەژمار دەکرێت تاکو داواکارییە هاوکاتەکان producer تەنها یەک جار جێبەجێ بکەن.

TTL ی `null` واتە TTL ی بنەڕەت (`0` = هەرگیز بەسەرناچێت) و TTL ی بچووکتر یان یەکسان بە سفر واتە هەژمارکردن بەبێ cache. ئەنجامی `null` یش cache دەکرێت.

### 3. سەلامەتی: بنەڕەتی سەلامەت و تۆماری واژۆکراو

هەر کەسێک بتوانێت لە backend ی cache بنووسێت (Redis ی بێ وشەی نهێنی، بوخچەی cache ی هاوبەش، خشتەی هاوبەشی بنکەی دراوە...) ئەگەرنا دەتوانێت تۆماری ساختە دابنێت، و unserialize کردنی ئۆبجێکتێکی ساختە دەتوانێت ببێتە هۆی جێبەجێکردنی کۆد. EasyCache 4 بە بنەڕەت سەلامەتە:

- `NativeSerializer` تەنها بەهای سادە و array cache دەکات. ئۆبجێکتی پۆلێک کە ڕێگەت پێنەداوە لە کاتی نووسیندا ڕەت دەکرێتەوە و لە کاتی خوێندنەوەدا پشتگوێ دەخرێت.
- بۆ cache کردنی ئۆبجێکت، پۆلەکانی لە `allowedClasses` دا ڕیز بکە. serializer ێک کە دەتوانێت ئۆبجێکت دروست بکات **پێویستی بە کلیلی واژۆ هەیە**: هەر تۆمارێک واژۆی HMAC-SHA256 ی هەیە و تۆماری بێ واژۆ یان دەستکاریکراو **پێش** deserialize کردن ڕەت دەکرێتەوە.

```php
$cache = new MultiTierCache(
    [$redisStore],
    new NativeSerializer(allowedClasses: [UserDto::class]), // true = هەر پۆلێک
    signingKey: $_ENV['EASYCACHE_SIGNING_KEY'],             // لایەنی کەم 16 بایتی هەڕەمەکی
);
```

ئەگەر جگە لە بەرنامەکەت هیچ کەسێک نەتوانێت لە هیچ چینێکدا بنووسێت، دەتوانیت لە جیاتی کلیلی واژۆ `allowUnsignedObjects: true` بدەیت.

دروستکردنی cache بە builder یش دەکرێت:

```php
$cache = MultiTierCache::builder()
    ->tier(new ApcuStorage('app:', maxTtl: 60))
    ->tier(new RedisStorage($redis, 'app:'))
    ->serializer(new NativeSerializer([UserDto::class]))
    ->signingKey($_ENV['EASYCACHE_SIGNING_KEY'])
    ->defaultTtl(600)
    ->build();
```

### 4. میتۆدە یارمەتیدەرەکان

```php
// یەک جار هەژمارکردن و 10 خولەک cache
$user = $cache->remember('user-42', 600, fn () => $repo->find(42));

// بەبێ بەسەرچوون
$cache->forever('feature-flags', $flags);

// tag: بەتاڵکردنەوەی کۆمەڵێک تۆمار پێکەوە
$cache->tags(['users'])->set('user-42', $user);
$cache->invalidateTags('users');

// ژمێرەری hit/miss ی ئەم نموونەیە
$cache->getStats(); // ['hits' => .., 'misses' => .., 'hit_ratio' => 0.93, ...]
```

### 5. Serializer و Compressor گۆڕاو

```php
// Serializer ی نیشتیمانی PHP: بە بنەڕەت تەنها بەهای سادە و array؛
// ئۆبجێکت تەنها بۆ پۆلە ڕیزکراوەکان و لەگەڵ کلیلی واژۆ
$cache = new MultiTierCache(
    [$storage],
    new NativeSerializer([Money::class]),
    new GzipCompressor(5),
    signingKey: $key
);

// Serializer ی JSON (هەرگیز ئۆبجێکت دروست ناکات؛ ئۆبجێکتەکان دەبنە array)
$cache = new MultiTierCache(
    [$storage],
    new JsonSerializer(),
    new ZstdCompressor(3)
);
```

ناوی compressor لەگەڵ هەر تۆمارێک هەڵدەگیرێت، بۆیە دوای گۆڕینی compressor تۆمارە gzip/zstd ەکانی پێشوو هەر دەخوێندرێنەوە. کردنەوەی پەستاندن بە 64 مێگابایت سنووردارە بۆ پاراستن لە decompression bomb.

---

## 💾 Backend ەکانی هەڵگرتن

### APCu
Cache ی یادگەیی زۆر خێرا

```php
$storage = new ApcuStorage(prefix: 'myapp:', maxTtl: 60); // prefix پێویستە و نابێت بەتاڵ بێت
```

لە CLI دا `apc.enable_cli=1` پێویستە.

### Redis
Cache ی تۆڕی لەگەڵ توانای هاوبەشکردن

```php
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$storage = new RedisStorage($redis, 'myapp:'); // phpredis یان Predis؛ prefix پێویستە
```

`clear()` تەنها کلیلەکانی ئەم prefix ە بە `SCAN` ی نابلۆککەر دەسڕێتەوە.

### File
Cache ی دیسک لەگەڵ sharding

```php
$storage = new FileStorage(
    path: '/var/cache/myapp',  // لە دەرەوەی web root
    ext: '.cache',
    shards: 2,
    fileMode: 0640,            // مۆڵەتەکان سەربەخۆ لە umask
    dirMode: 0770
);
```

- کاتی بەسەرچوون لەناو هەر فایلێکدا هەڵدەگیرێت؛ فایلە بەسەرچووەکان پشتگوێ دەخرێن و `prune()` دەیانسڕێتەوە (`vendor/bin/easycache-prune /var/cache/myapp` یان `php artisan easycache:prune` خشتەبەندی بکە).
- `clear()` تەنها فایلەکانی cache خۆی دەسڕێتەوە و هەرگیز دوای symlink ناکەوێت.

### PDO
Cache ی بنکەی دراوە (MySQL/PostgreSQL/SQLite)

```php
$pdo = new PDO('mysql:host=localhost;dbname=cache', 'user', 'pass');
$storage = new PdoStorage($pdo, 'easycache');
$storage->ensureTable();

// خشتەکانی MySQL کە بە وەشانەکانی پێش 3.1 دروست کراون (سنووری 64KB و کلیلی ناهەستیار)
$storage->upgradeSchema();
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
    mode: RefreshMode::Defer
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

ڕێکخستن لە ڕێگەی `.env`:

```env
EASYCACHE_DRIVERS=apcu,redis,file   # بنەڕەت: file
EASYCACHE_DEFAULT_TTL=600
EASYCACHE_REDIS_HOST=127.0.0.1
EASYCACHE_LOCK_DRIVER=redis         # file|redis|none
EASYCACHE_COMPRESSOR=gzip           # none|gzip|zstd
```

- تۆمارەکان بە بنەڕەت بە کلیلێک کە لە `APP_KEY` وەرگیراوە **واژۆ** دەکرێن (`EASYCACHE_SIGNING_KEY` بۆ کلیلی خۆت، `EASYCACHE_SIGN=false` بۆ ناچالاککردن). لەگەڵ واژۆی ناچالاک، cache کردنی ئۆبجێکت تەنها بە `EASYCACHE_ALLOW_UNSIGNED_OBJECTS=true` یان `serializer.allowed_classes = false` دەکرێت.
- درایڤەرێک کە دەست پێنەکات (پاشکۆی نەبوو، Redis ی کوژاوە...) بە warning ێک لە log دا تێدەپەڕێنرێت.
- cache لە ڕێگەی `app('easycache')`، Facade یان type-hint کردنی `MultiTierCache` بەردەستە.

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

### فەرمانەکانی Artisan

```bash
php artisan easycache:prune   # سڕینەوەی تۆمارە بەسەرچووەکان
php artisan easycache:clear   # سڕینەوەی هەموو تۆمارەکان
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

- ✅ کۆمەڵە تێستێکی هاوبەش بۆ هەموو storage ەکان کە لەسەر File، SQLite، MySQL، PostgreSQL، phpredis، Predis و APCu جێبەجێ دەکرێت
- ✅ Multi-tier caching، backfill و گەڕانەوە بۆ ڕیزە تازەترەکان
- ✅ ڕەفتاری SWR و stale-if-error (بە کاتژمێری چەسپاوی PSR-20 و بەبێ `sleep()`)
- ✅ سەلامەتی: تۆماری واژۆکراو، دەستکاریکراو و بێ واژۆ، هەوڵی object injection
- ✅ گونجان لەگەڵ PSR-16 و ڕێگری لە stampede بە پرۆسەی هاوکات
- ✅ Serializer، Compressor، پشکنینی کلیل، قفڵی فایل و Redis
- ✅ Service Provider، Facade و فەرمانەکانی Laravel و ئامرازی CLI

تێستەکانی Redis، MySQL و PostgreSQL بە گۆڕاوەکانی ژینگەی `EASYCACHE_TEST_*` چالاک دەبن ([CONTRIBUTING.md](CONTRIBUTING.md)).

---

## 📚 بەڵگەنامە

### بەڵگەنامەی کوردی
- [README_KU.md](README_KU.md) - ئەم فایلە
- [API_KU.md](API_KU.md) - سەرچاوەی تەواوی API
- [EXAMPLES_KU.md](EXAMPLES_KU.md) - نموونەی کاربەردی

### بەڵگەنامەی فارسی
- [README_FA.md](README_FA.md) - وەشانی فارسی

### بەڵگەنامەی ئینگلیزی
- [README.md](README.md) - ڕێنمایی سەرەکی
- [EXAMPLES.md](EXAMPLES.md) - نموونەی کاربەردی
- [API.md](API.md) - سەرچاوەی تەواوی API
- [PERFORMANCE.md](PERFORMANCE.md) - ڕێنمایی باشکردن
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md) - چارەسەری کێشەکان
- [CONTRIBUTING.md](CONTRIBUTING.md) - ڕێنمایی بەشداریکردن
- [MIGRATION.md](MIGRATION.md) - ڕێنمایی بەرزکردنەوە
- [SECURITY.md](SECURITY.md) - سیاسەتی سەلامەتی

---

## 🔑 یاساکانی کلیل (PSR-16)

- **کاراکتەرە ڕێگەپێدراوەکان:** هەموو کاراکتەرە چاپکراوەکانی ASCII (`!` تا `~`) جگە لە کاراکتەرە پارێزراوەکان
- **کاراکتەرە قەدەغەکراوەکان:** `{ } ( ) / \ @ :`، بۆشایی، کاراکتەری کۆنترۆڵ (لەوانە newline) و بایتی نا-ASCII
- **درێژترین درێژی:** 255 بایت

```php
// کلیلە دروستەکان
$cache->set('user_123', $data);
$cache->set('posts.latest', $data);
$cache->set('user-3f2a9c1e-77b2-4f3b-9a51-1c2d3e4f5a6b', $data);

// کلیلە هەڵەکان (Psr\SimpleCache\InvalidArgumentException)
$cache->set('user:123', $data);    // هەڵە: : ی تێدایە
$cache->set('user/123', $data);    // هەڵە: / ی تێدایە
$cache->set('user 123', $data);    // هەڵە: بۆشایی تێدایە
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
    RefreshMode::Defer  // وەڵامی خێراتر
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
- ✅ بەڕێوەبردنی هەڵەی تەواو لەگەڵ logging ی PSR-3
- ✅ تێستی هەموو backend ەکان لە CI (MySQL، PostgreSQL، Redis، APCu)
- ✅ واژۆی HMAC و پاراستن لە object injection
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
- [ڕێنمایی بەرزکردنەوە](MIGRATION.md)
- [گۆڕانکارییەکان](CHANGELOG.md)
- [سیاسەتی سەلامەتی](SECURITY.md)
- [GitHub Issues](https://github.com/iprodev/php-easycache/issues)

---

## 💬 پشتگیری

- 📧 ئیمەیڵ: dev@iprodev.com
- 🔐 کێشەی سەلامەتی: [SECURITY.md](SECURITY.md) ببینە (تکایە issue ی گشتی مەکەرەوە)
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
    mode: RefreshMode::Defer
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

// 4. واژۆکردنی تۆمارەکان و سنووردارکردنی پۆلەکان
$cache = new MultiTierCache(
    [$storage],
    new NativeSerializer(allowedClasses: false),
    signingKey: $_ENV['EASYCACHE_SIGNING_KEY']
);

// 5. پاککردنەوەی خۆکار (cron)
// 0 * * * * /path/to/vendor/bin/easycache-prune /var/cache/myapp
```

- Redis بە وشەی نهێنی بپارێزە و بوخچەی cache لە دەرەوەی web root دابنێ.
- بۆ زانیاری زیاتر [SECURITY.md](SECURITY.md) ببینە.

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

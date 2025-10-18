# مرجع API

مستندات کامل API برای PHP EasyCache v3.

## فهرست مطالب

- [MultiTierCache](#multitiercache)
- [Storage Backends](#storage-backends)
- [Serializers](#serializers)
- [Compressors](#compressors)
- [ابزارها](#ابزارها)
- [استثناها](#استثناها)

---

## MultiTierCache

کلاس اصلی cache که رابط PSR-16 CacheInterface را پیاده‌سازی می‌کند.

### سازنده (Constructor)

```php
public function __construct(
    array $tiers,
    ?SerializerInterface $serializer = null,
    ?CompressorInterface $compressor = null,
    int $defaultTtl = 3600,
    ?LoggerInterface $logger = null,
    ?string $lockPath = null
)
```

**پارامترها:**
- `$tiers` (array): آرایه‌ای از نمونه‌های StorageInterface، مرتب شده از سریع‌ترین به کندترین
- `$serializer` (SerializerInterface|null): نمونه Serializer (پیش‌فرض: NativeSerializer)
- `$compressor` (CompressorInterface|null): نمونه Compressor (پیش‌فرض: NullCompressor)
- `$defaultTtl` (int): TTL پیش‌فرض به ثانیه (پیش‌فرض: 3600)
- `$logger` (LoggerInterface|null): نمونه logger سازگار با PSR-3
- `$lockPath` (string|null): مسیر دایرکتوری برای فایل‌های قفل (پیش‌فرض: sys_get_temp_dir()/ec-locks)

**استثنا:**
- `\InvalidArgumentException` اگر `$tiers` خالی باشد

**مثال:**
```php
$cache = new MultiTierCache(
    [new ApcuStorage(), new FileStorage('/cache')],
    new NativeSerializer(),
    new GzipCompressor(5),
    3600,
    $logger,
    '/var/lock/cache'
);
```

---

### ()get

بازیابی یک آیتم از cache.

```php
public function get(string $key, mixed $default = null): mixed
```

**پارامترها:**
- `$key` (string): کلید cache (حداکثر 64 کاراکتر، فقط حروف و اعداد + `_` `.`)
- `$default` (mixed): مقدار پیش‌فرض اگر کلید وجود نداشته باشد

**برمی‌گرداند:** مقدار cache شده یا `$default`

**استثنا:**
- `InvalidArgument` اگر کلید نامعتبر باشد

**مثال:**
```php
$value = $cache->get('user_123', ['name' => 'ناشناس']);
```

---

### ()set

ذخیره یک آیتم در cache.

```php
public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
```

**پارامترها:**
- `$key` (string): کلید cache
- `$value` (mixed): مقدار برای cache کردن (هر نوع قابل serialize)
- `$ttl` (int|DateInterval|null): مدت زمان زنده ماندن. null = استفاده از پیش‌فرض، 0 = برای همیشه

**برمی‌گرداند:** `true` در صورت موفقیت، `false` در صورت شکست

**استثنا:**
- `InvalidArgument` اگر کلید نامعتبر باشد

**مثال:**
```php
$cache->set('user_123', $user, 3600);
$cache->set('config', $config, new DateInterval('P1D'));
$cache->set('permanent', $data, 0);
```

---

### ()delete

حذف یک آیتم از cache.

```php
public function delete(string $key): bool
```

**پارامترها:**
- `$key` (string): کلید cache

**برمی‌گرداند:** `true` در صورت موفقیت

**استثنا:**
- `InvalidArgument` اگر کلید نامعتبر باشد

**مثال:**
```php
$cache->delete('user_123');
```

---

### ()clear

پاک کردن تمام آیتم‌ها از cache.

```php
public function clear(): bool
```

**برمی‌گرداند:** `true` در صورت موفقیت

**مثال:**
```php
$cache->clear();
```

---

### ()has

بررسی وجود یک آیتم در cache.

```php
public function has(string $key): bool
```

**پارامترها:**
- `$key` (string): کلید cache

**برمی‌گرداند:** `true` اگر وجود داشته باشد و منقضی نشده باشد

**استثنا:**
- `InvalidArgument` اگر کلید نامعتبر باشد

**مثال:**
```php
if ($cache->has('user_123')) {
    echo "کاربر cache شده است";
}
```

---

### ()getMultiple

بازیابی چندین آیتم از cache.

```php
public function getMultiple(iterable $keys, mixed $default = null): iterable
```

**پارامترها:**
- `$keys` (iterable): لیست کلیدهای cache
- `$default` (mixed): مقدار پیش‌فرض برای کلیدهای موجود نیست

**برمی‌گرداند:** آرایه associative از key => value

**استثنا:**
- `InvalidArgument` اگر keys قابل تکرار نباشد

**مثال:**
```php
$results = $cache->getMultiple(['key1', 'key2', 'key3'], null);
// ['key1' => 'value1', 'key2' => 'value2', 'key3' => null]
```

---

### ()setMultiple

ذخیره چندین آیتم در cache.

```php
public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
```

**پارامترها:**
- `$values` (iterable): آرایه associative از key => value
- `$ttl` (int|DateInterval|null): مدت زمان زنده ماندن

**برمی‌گرداند:** `true` اگر همه موفق شوند

**استثنا:**
- `InvalidArgument` اگر values قابل تکرار نباشد

**مثال:**
```php
$cache->setMultiple([
    'key1' => 'value1',
    'key2' => 'value2',
], 3600);
```

---

### ()deleteMultiple

حذف چندین آیتم از cache.

```php
public function deleteMultiple(iterable $keys): bool
```

**پارامترها:**
- `$keys` (iterable): لیست کلیدهای cache

**برمی‌گرداند:** `true` اگر همه موفق شوند

**استثنا:**
- `InvalidArgument` اگر keys قابل تکرار نباشد

**مثال:**
```php
$cache->deleteMultiple(['key1', 'key2', 'key3']);
```

---

### ()getOrSetSWR

دریافت یا تنظیم با پشتیبانی Stale-While-Revalidate.

```php
public function getOrSetSWR(
    string $key,
    callable $producer,
    null|int|DateInterval $ttl = null,
    int $swrSeconds = 0,
    int $staleIfErrorSeconds = 0,
    array $options = []
): mixed
```

**پارامترها:**
- `$key` (string): کلید cache
- `$producer` (callable): تابع برای تولید مقدار تازه: `function(): mixed`
- `$ttl` (int|DateInterval|null): TTL داده تازه
- `$swrSeconds` (int): پنجره stale-while-revalidate به ثانیه
- `$staleIfErrorSeconds` (int): پنجره stale-if-error به ثانیه
- `$options` (array): آرایه گزینه‌ها
  - `mode` (string): 'sync' یا 'defer' (پیش‌فرض: 'sync')

**برمی‌گرداند:** مقدار cache شده یا تازه

**استثنا:**
- `InvalidArgument` اگر کلید نامعتبر باشد
- استثناهای `$producer` را مجدداً پرتاب می‌کند در صورت cache miss

**مثال:**
```php
$data = $cache->getOrSetSWR(
    'expensive_data',
    fn() => computeExpensiveData(),
    300,     // تازه برای 5 دقیقه
    60,      // کهنه برای 1 دقیقه
    600,     // کهنه برای 10 دقیقه در صورت خطا
    ['mode' => 'defer']
);
```

**رفتار:**
1. **Cache hit (تازه):** مقدار cache شده را فوراً برمی‌گرداند
2. **Cache hit (کهنه، در SWR):** مقدار کهنه را برمی‌گرداند، refresh پس‌زمینه را فعال می‌کند
3. **Cache miss:** قفل را به دست می‌آورد، producer را فراخوانی می‌کند، نتیجه را cache می‌کند
4. **خطای Producer با داده کهنه:** کهنه را برمی‌گرداند اگر در پنجره staleIfError باشد

---

### ()prune

حذف آیتم‌های منقضی شده از storage backends.

```php
public function prune(): int
```

**برمی‌گرداند:** تعداد آیتم‌های حذف شده

**توجه:** فقط با backend هایی که از pruning پشتیبانی می‌کنند کار می‌کند (PdoStorage). File/APCu/Redis انقضا را به صورت خودکار مدیریت می‌کنند.

**مثال:**
```php
$pruned = $cache->prune();
echo "تعداد {$pruned} آیتم منقضی شده حذف شد";
```

---

## Storage Backends

### StorageInterface

تمام storage backend ها باید این interface را پیاده‌سازی کنند.

```php
interface StorageInterface
{
    public function get(string $key): ?string;
    public function set(string $key, string $payload, int $ttl): bool;
    public function delete(string $key): bool;
    public function has(string $key): bool;
    public function clear(): bool;
    public function prune(): int;
}
```

---

### FileStorage

ذخیره‌سازی cache مبتنی بر فایل با directory sharding.

#### سازنده

```php
public function __construct(
    string $path,
    string $ext = '.cache',
    int $shards = 2
)
```

**پارامترها:**
- `$path` (string): مسیر دایرکتوری cache
- `$ext` (string): پسوند فایل (پیش‌فرض: '.cache')
- `$shards` (int): سطح sharding از 0 تا 3 (پیش‌فرض: 2)

**استثنا:**
- `\RuntimeException` اگر دایرکتوری ایجاد نشود یا قابل نوشتن نباشد

**مثال:**
```php
$storage = new FileStorage('/var/cache/app', '.cache', 2);
```

#### متدها

تمام متدهای `StorageInterface`.

**یادداشت‌ها:**
- از نوشتن اتمی استفاده می‌کند (فایل موقت + rename)
- از flock() برای قفل خواندن استفاده می‌کند
- از 0 تا 3 سطح directory sharding پشتیبانی می‌کند
- `prune()` عدد 0 برمی‌گرداند (انقضا توسط MultiTierCache مدیریت می‌شود)

---

### ApcuStorage

ذخیره‌سازی cache حافظه APCu.

#### سازنده

```php
public function __construct(string $prefix = 'ec:')
```

**پارامترها:**
- `$prefix` (string): پیشوند کلید برای namespace کردن (پیش‌فرض: 'ec:')

**استثنا:**
- `\RuntimeException` اگر افزونه APCu در دسترس نباشد یا فعال نباشد

**مثال:**
```php
$storage = new ApcuStorage('myapp:');
```

#### متدها

تمام متدهای `StorageInterface`.

**یادداشت‌ها:**
- `clear()` فقط کلیدهای با prefix پیکربندی شده را حذف می‌کند
- بسیار سریع (در حافظه)
- بین worker های PHP-FPM مشترک است
- `prune()` عدد 0 برمی‌گرداند (APCu انقضا را مدیریت می‌کند)

---

### RedisStorage

ذخیره‌سازی cache Redis با پشتیبانی از phpredis و predis.

#### سازنده

```php
public function __construct($redisClient, string $prefix = 'ec:')
```

**پارامترها:**
- `$redisClient` (Redis|Predis\ClientInterface): نمونه client Redis
- `$prefix` (string): پیشوند کلید (پیش‌فرض: 'ec:')

**استثنا:**
- `\InvalidArgumentException` اگر client نوع نامعتبری داشته باشد

**مثال:**
```php
// phpredis
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$storage = new RedisStorage($redis, 'app:');

// predis
$redis = new Predis\Client('tcp://127.0.0.1:6379');
$storage = new RedisStorage($redis, 'app:');
```

#### متدها

تمام متدهای `StorageInterface`.

**یادداشت‌ها:**
- از SETEX برای پشتیبانی TTL استفاده می‌کند
- `clear()` از SCAN برای حذف فقط کلیدهای با prefix استفاده می‌کند
- `prune()` عدد 0 برمی‌گرداند (Redis انقضا را مدیریت می‌کند)

---

### PdoStorage

ذخیره‌سازی cache بانک اطلاعاتی PDO.

#### سازنده

```php
public function __construct(PDO $pdo, string $table = 'easycache')
```

**پارامترها:**
- `$pdo` (PDO): نمونه PDO
- `$table` (string): نام جدول (پیش‌فرض: 'easycache')

**مثال:**
```php
$pdo = new PDO('mysql:host=localhost;dbname=cache', 'user', 'pass');
$storage = new PdoStorage($pdo, 'cache_items');
```

#### ()ensureTable

ایجاد جدول cache اگر وجود نداشته باشد.

```php
public function ensureTable(): void
```

**استثنا:**
- `\RuntimeException` اگر ایجاد جدول شکست بخورد

**مثال:**
```php
$storage->ensureTable();
```

**طرح جدول:**
- `k` (VARCHAR(64) PRIMARY KEY): کلید cache
- `payload` (BLOB): داده serialize و فشرده شده
- `expires_at` (BIGINT): timestamp انقضا

#### متدها

تمام متدهای `StorageInterface`.

**یادداشت‌ها:**
- `prune()` سطرهای منقضی شده را حذف می‌کند و تعداد را برمی‌گرداند
- از MySQL، PostgreSQL، SQLite پشتیبانی می‌کند
- از UPSERT برای عملیات set اتمی استفاده می‌کند

---

## Serializers

### SerializerInterface

```php
interface SerializerInterface
{
    public function serialize(mixed $value): string;
    public function deserialize(string $payload): mixed;
    public function name(): string;
}
```

---

### NativeSerializer

سریال‌سازی بومی PHP.

```php
$serializer = new NativeSerializer();
```

**ویژگی‌ها:**
- از اشیاء و انواع پیچیده پشتیبانی می‌کند
- نام: 'php'
- از `serialize()` و `unserialize()` استفاده می‌کند

---

### JsonSerializer

سریال‌سازی JSON.

```php
$serializer = new JsonSerializer();
```

**ویژگی‌ها:**
- قابل حمل بین زبان‌ها
- نام: 'json'
- از flag `JSON_THROW_ON_ERROR` استفاده می‌کند
- کاراکترهای Unicode را حفظ می‌کند

**استثنا:**
- `\JsonException` در صورت داده نامعتبر

---

## Compressors

### CompressorInterface

```php
interface CompressorInterface
{
    public function compress(string $data): string;
    public function decompress(string $data): string;
    public function name(): string;
}
```

---

### NullCompressor

بدون فشرده‌سازی.

```php
$compressor = new NullCompressor();
```

**ویژگی‌ها:**
- عبوری (بدون فشرده‌سازی)
- نام: 'none'

---

### GzipCompressor

فشرده‌سازی Gzip.

```php
public function __construct(int $level = 3)
```

**پارامترها:**
- `$level` (int): سطح فشرده‌سازی 0-9 (پیش‌فرض: 3)

**مثال:**
```php
$compressor = new GzipCompressor(5);
```

**ویژگی‌ها:**
- نام: 'gzip'
- نیاز به ext-zlib دارد
- سطح 0 = بدون فشرده‌سازی، 9 = حداکثر

**استثنا:**
- `\RuntimeException` اگر zlib در دسترس نباشد یا فشرده‌سازی شکست بخورد

---

### ZstdCompressor

فشرده‌سازی Zstandard.

```php
public function __construct(int $level = 3)
```

**پارامترها:**
- `$level` (int): سطح فشرده‌سازی (پیش‌فرض: 3)

**مثال:**
```php
$compressor = new ZstdCompressor(3);
```

**ویژگی‌ها:**
- نام: 'zstd'
- نیاز به ext-zstd دارد
- سریع‌تر از Gzip

**استثنا:**
- `\RuntimeException` اگر zstd در دسترس نباشد یا فشرده‌سازی شکست بخورد

---

## ابزارها

### KeyValidator

اعتبارسنجی کلیدهای cache طبق PSR-16.

#### ()assert

```php
public static function assert(string $key): void
```

**پارامترها:**
- `$key` (string): کلید برای اعتبارسنجی

**استثنا:**
- `InvalidArgument` اگر کلید نامعتبر باشد

**قوانین:**
- حداکثر 64 کاراکتر
- فقط: `A-Za-z0-9_.`
- ممنوع: `{}()/\@:`

**مثال:**
```php
KeyValidator::assert('user_123');     // موفق
KeyValidator::assert('user:123');     // استثنا
KeyValidator::assert('user/profile'); // استثنا
```

---

### Lock

مکانیزم قفل مبتنی بر فایل.

#### سازنده

```php
public function __construct(string $path)
```

**پارامترها:**
- `$path` (string): مسیر فایل قفل

---

#### ()acquire

```php
public function acquire(bool $blocking = true): bool
```

**پارامترها:**
- `$blocking` (bool): منتظر قفل بماند (true) یا فوراً شکست بخورد (false)

**برمی‌گرداند:** `true` اگر قفل به دست آمد

**مثال:**
```php
$lock = new Lock('/tmp/my.lock');

// حالت مسدود کننده
if ($lock->acquire(true)) {
    // کار را انجام دهید
    $lock->release();
}

// حالت غیر مسدود کننده
if ($lock->acquire(false)) {
    // کار را انجام دهید
} else {
    echo "نتوانست قفل را به دست آورد";
}
```

---

#### ()release

```php
public function release(): void
```

**توجه:** به صورت خودکار در destructor فراخوانی می‌شود.

---

## استثناها

### InvalidArgument

برای کلیدهای cache یا آرگومان‌های نامعتبر پرتاب می‌شود.

```php
class InvalidArgument extends \InvalidArgumentException 
    implements Psr\SimpleCache\InvalidArgumentException
```

**مثال:**
```php
try {
    $cache->set('invalid:key', 'value');
} catch (InvalidArgument $e) {
    echo "کلید نامعتبر: " . $e->getMessage();
}
```

---

## یکپارچه‌سازی Laravel

### Facade

```php
use EasyCache;

EasyCache::set('key', 'value', 3600);
$value = EasyCache::get('key');
```

تمام متدهای `MultiTierCache` در دسترس هستند.

### پیکربندی

منتشر شده در `config/easycache.php`:

```php
return [
    'drivers' => ['apcu', 'redis', 'file'],
    'default_ttl' => 600,
    'lock_path' => storage_path('framework/cache/easycache-locks'),
    
    'serializer' => [
        'driver' => 'php', // php|json
    ],
    
    'compressor' => [
        'driver' => 'gzip', // none|gzip|zstd
        'level' => 3,
    ],
    
    // گزینه‌های خاص backend
];
```

### Service Provider

به صورت خودکار کشف می‌شود. ثبت می‌کند:
- Facade `EasyCache`
- Singleton `easycache` در container

---

## مرجع انواع

### انواع رایج

```php
// انواع TTL
int           // ثانیه
DateInterval  // مثلاً new DateInterval('PT1H')
null          // استفاده از TTL پیش‌فرض

// انواع مقدار پشتیبانی شده
string
int
float
bool
null
array
object (با NativeSerializer)
```

---

## مدیریت خطا

تمام عملیات به صورت داخلی در try-catch قرار دارند. شکست‌ها اگر logger فراهم شده باشد ثبت می‌شوند و به جای پرتاب کردن false/null برمی‌گردانند.

**استثناهایی که پرتاب می‌شوند:**
- `InvalidArgument`: کلیدها یا پارامترهای نامعتبر
- `\RuntimeException`: خطاهای ساخت (افزونه‌های گمشده، مسیرهای نامعتبر)
- `\JsonException`: خطاهای سریال‌سازی JSON (JsonSerializer)

**استثناهایی که پرتاب نمی‌شوند (به جای آن ثبت می‌شوند):**
- شکست‌های storage (خطاهای خواندن/نوشتن)
- خطاهای فشرده‌سازی/رفع فشرده‌سازی
- شکست‌های به دست آوردن قفل

---

برای مثال‌های بیشتر، [EXAMPLES.md](EXAMPLES.md) را ببینید.

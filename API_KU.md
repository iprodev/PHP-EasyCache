# سەرچاوەی API

بەڵگەنامەی تەواوی API بۆ PHP EasyCache v3.

## پێرستی ناوەڕۆک

- [MultiTierCache](#multitiercache)
- [Storage Backends](#storage-backends)
- [Serializers](#serializers)
- [Compressors](#compressors)
- [ئامرازەکان](#ئامرازەکان)
- [هەڵەکان](#هەڵەکان)

---

## MultiTierCache

پۆلی سەرەکی cache کە ڕووکاری PSR-16 CacheInterface جێبەجێ دەکات.

### دروستکەر (Constructor)

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

**پارامەترەکان:**
- `$tiers` (array): ڕیزبەندی نموونەکانی StorageInterface، لە خێراترین بۆ هێواشترین
- `$serializer` (SerializerInterface|null): نموونەی Serializer (بنەڕەت: NativeSerializer)
- `$compressor` (CompressorInterface|null): نموونەی Compressor (بنەڕەت: NullCompressor)
- `$defaultTtl` (int): TTL ی بنەڕەتی بە چرکە (بنەڕەت: 3600)
- `$logger` (LoggerInterface|null): نموونەی logger کە لەگەڵ PSR-3 گونجاوە
- `$lockPath` (string|null): ڕێچکەی بوخچە بۆ فایلەکانی قفڵ (بنەڕەت: sys_get_temp_dir()/ec-locks)

**هەڵە:**
- `\InvalidArgumentException` ئەگەر `$tiers` بەتاڵ بێت

**نموونە:**
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

گەڕاندنەوەی بڕگەیەک لە cache.

```php
public function get(string $key, mixed $default = null): mixed
```

**پارامەترەکان:**
- `$key` (string): کلیلی cache (زۆرترین 64 پیت، تەنها پیت و ژمارە + `_` `.`)
- `$default` (mixed): بەهای بنەڕەتی ئەگەر کلیل بوونی نەبێت

**دەگەڕێنێتەوە:** بەهای cache کراو یان `$default`

**هەڵە:**
- `InvalidArgument` ئەگەر کلیل نادروست بێت

**نموونە:**
```php
$value = $cache->get('user_123', ['name' => 'نەزانراو']);
```

---

### ()set

هەڵگرتنی بڕگەیەک لە cache.

```php
public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
```

**پارامەترەکان:**
- `$key` (string): کلیلی cache
- `$value` (mixed): بەها بۆ cache کردن (هەر جۆرێک کە دەتوانرێت serialize بکرێت)
- `$ttl` (int|DateInterval|null): ماوەی ژیان. null = بەکارهێنانی بنەڕەت، 0 = بۆ هەمیشە

**دەگەڕێنێتەوە:** `true` لە سەرکەوتندا، `false` لە شکستدا

**هەڵە:**
- `InvalidArgument` ئەگەر کلیل نادروست بێت

**نموونە:**
```php
$cache->set('user_123', $user, 3600);
$cache->set('config', $config, new DateInterval('P1D'));
$cache->set('permanent', $data, 0);
```

---

### ()delete

سڕینەوەی بڕگەیەک لە cache.

```php
public function delete(string $key): bool
```

**پارامەترەکان:**
- `$key` (string): کلیلی cache

**دەگەڕێنێتەوە:** `true` لە سەرکەوتندا

**هەڵە:**
- `InvalidArgument` ئەگەر کلیل نادروست بێت

**نموونە:**
```php
$cache->delete('user_123');
```

---

### ()clear

پاککردنەوەی هەموو بڕگەکان لە cache.

```php
public function clear(): bool
```

**دەگەڕێنێتەوە:** `true` لە سەرکەوتندا

**نموونە:**
```php
$cache->clear();
```

---

### ()has

پشکنینی بوونی بڕگەیەک لە cache.

```php
public function has(string $key): bool
```

**پارامەترەکان:**
- `$key` (string): کلیلی cache

**دەگەڕێنێتەوە:** `true` ئەگەر بوونی هەبێت و بەسەر نەچووبێت

**هەڵە:**
- `InvalidArgument` ئەگەر کلیل نادروست بێت

**نموونە:**
```php
if ($cache->has('user_123')) {
    echo "بەکارهێنەر cache کراوە";
}
```

---

### ()getMultiple

گەڕاندنەوەی چەند بڕگەیەک لە cache.

```php
public function getMultiple(iterable $keys, mixed $default = null): iterable
```

**پارامەترەکان:**
- `$keys` (iterable): لیستی کلیلەکانی cache
- `$default` (mixed): بەهای بنەڕەتی بۆ کلیلە نەبوونەکان

**دەگەڕێنێتەوە:** ڕیزبەندی associative لە key => value

**هەڵە:**
- `InvalidArgument` ئەگەر keys دووبارە نەبێت

**نموونە:**
```php
$results = $cache->getMultiple(['key1', 'key2', 'key3'], null);
// ['key1' => 'value1', 'key2' => 'value2', 'key3' => null]
```

---

### ()setMultiple

هەڵگرتنی چەند بڕگەیەک لە cache.

```php
public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
```

**پارامەترەکان:**
- `$values` (iterable): ڕیزبەندی associative لە key => value
- `$ttl` (int|DateInterval|null): ماوەی ژیان

**دەگەڕێنێتەوە:** `true` ئەگەر هەموو سەرکەوتوو بن

**هەڵە:**
- `InvalidArgument` ئەگەر values دووبارە نەبێت

**نموونە:**
```php
$cache->setMultiple([
    'key1' => 'value1',
    'key2' => 'value2',
], 3600);
```

---

### ()deleteMultiple

سڕینەوەی چەند بڕگەیەک لە cache.

```php
public function deleteMultiple(iterable $keys): bool
```

**پارامەترەکان:**
- `$keys` (iterable): لیستی کلیلەکانی cache

**دەگەڕێنێتەوە:** `true` ئەگەر هەموو سەرکەوتوو بن

**هەڵە:**
- `InvalidArgument` ئەگەر keys دووبارە نەبێت

**نموونە:**
```php
$cache->deleteMultiple(['key1', 'key2', 'key3']);
```

---

### ()getOrSetSWR

وەرگرتن یان دانان لەگەڵ پشتگیری Stale-While-Revalidate.

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

**پارامەترەکان:**
- `$key` (string): کلیلی cache
- `$producer` (callable): فەنکشن بۆ بەرهەمهێنانی بەهای تازە: `function(): mixed`
- `$ttl` (int|DateInterval|null): TTL ی داتا تازەکان
- `$swrSeconds` (int): پەنجەرەی stale-while-revalidate بە چرکە
- `$staleIfErrorSeconds` (int): پەنجەرەی stale-if-error بە چرکە
- `$options` (array): ڕیزبەندی هەڵبژاردنەکان
  - `mode` (string): 'sync' یان 'defer' (بنەڕەت: 'sync')

**دەگەڕێنێتەوە:** بەهای cache کراو یان تازە

**هەڵە:**
- `InvalidArgument` ئەگەر کلیل نادروست بێت
- هەڵەکانی `$producer` دووبارە فڕێدەدرێت لە کاتی cache miss

**نموونە:**
```php
$data = $cache->getOrSetSWR(
    'expensive_data',
    fn() => computeExpensiveData(),
    300,     // تازە بۆ 5 خولەک
    60,      // کۆن بۆ 1 خولەک
    600,     // کۆن بۆ 10 خولەک ئەگەر هەڵە
    ['mode' => 'defer']
);
```

**ڕەفتار:**
1. **Cache hit (تازە):** بەهای cache کراو دەستبەجێ دەگەڕێنێتەوە
2. **Cache hit (کۆن، لە SWR):** بەهای کۆن دەگەڕێنێتەوە، refresh ی پاشبنەما چالاک دەکات
3. **Cache miss:** قفڵ دەگرێت، producer بانگ دەکات، ئەنجام cache دەکات
4. **هەڵەی Producer لەگەڵ داتا کۆن:** کۆن دەگەڕێنێتەوە ئەگەر لە پەنجەرەی staleIfError بێت

---

### ()prune

لابردنی بڕگە بەسەرچووەکان لە storage backends.

```php
public function prune(): int
```

**دەگەڕێنێتەوە:** ژمارەی بڕگە لابراوەکان

**تێبینی:** تەنها لەگەڵ backend ەکان کار دەکات کە پشتگیری pruning دەکەن (PdoStorage). File/APCu/Redis بەسەرچوون بە خۆکاری بەڕێوە دەبەن.

**نموونە:**
```php
$pruned = $cache->prune();
echo "ژمارەی {$pruned} بڕگەی بەسەرچوو لابرا";
```

---

## Storage Backends

### StorageInterface

هەموو storage backend ەکان دەبێت ئەم interface جێبەجێ بکەن.

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

هەڵگرتنی cache پشتبەست بە فایل لەگەڵ directory sharding.

#### دروستکەر

```php
public function __construct(
    string $path,
    string $ext = '.cache',
    int $shards = 2
)
```

**پارامەترەکان:**
- `$path` (string): ڕێچکەی بوخچەی cache
- `$ext` (string): پاشگری فایل (بنەڕەت: '.cache')
- `$shards` (int): ئاستی sharding لە 0 بۆ 3 (بنەڕەت: 2)

**هەڵە:**
- `\RuntimeException` ئەگەر بوخچە دروست نەبێت یان نووسینی بۆ مومکین نەبێت

**نموونە:**
```php
$storage = new FileStorage('/var/cache/app', '.cache', 2);
```

#### میتۆدەکان

هەموو میتۆدەکانی `StorageInterface`.

**تێبینیەکان:**
- نووسینی ئەتۆمی بەکاردەهێنێت (فایلی کاتی + rename)
- flock() بەکاردەهێنێت بۆ قفڵی خوێندنەوە
- لە 0 بۆ 3 ئاستی directory sharding پشتگیری دەکات
- `prune()` ژمارەی 0 دەگەڕێنێتەوە (بەسەرچوون لەلایەن MultiTierCache بەڕێوە دەبرێت)

---

### ApcuStorage

هەڵگرتنی cache یادگەی APCu.

#### دروستکەر

```php
public function __construct(string $prefix = 'ec:')
```

**پارامەترەکان:**
- `$prefix` (string): پێشگری کلیل بۆ namespace کردن (بنەڕەت: 'ec:')

**هەڵە:**
- `\RuntimeException` ئەگەر زیادکراوی APCu بەردەست نەبێت یان چالاک نەبێت

**نموونە:**
```php
$storage = new ApcuStorage('myapp:');
```

#### میتۆدەکان

هەموو میتۆدەکانی `StorageInterface`.

**تێبینیەکان:**
- `clear()` تەنها کلیلەکان لەگەڵ prefix ی ڕێکخراو دەسڕێتەوە
- زۆر خێرایە (لە یادگە)
- لە نێوان worker ەکانی PHP-FPM هاوبەشە
- `prune()` ژمارەی 0 دەگەڕێنێتەوە (APCu بەسەرچوون بەڕێوە دەبات)

---

### RedisStorage

هەڵگرتنی cache ی Redis لەگەڵ پشتگیری لە phpredis و predis.

#### دروستکەر

```php
public function __construct($redisClient, string $prefix = 'ec:')
```

**پارامەترەکان:**
- `$redisClient` (Redis|Predis\ClientInterface): نموونەی client ی Redis
- `$prefix` (string): پێشگری کلیل (بنەڕەت: 'ec:')

**هەڵە:**
- `\InvalidArgumentException` ئەگەر client جۆرێکی نادروست بێت

**نموونە:**
```php
// phpredis
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$storage = new RedisStorage($redis, 'app:');

// predis
$redis = new Predis\Client('tcp://127.0.0.1:6379');
$storage = new RedisStorage($redis, 'app:');
```

#### میتۆدەکان

هەموو میتۆدەکانی `StorageInterface`.

**تێبینیەکان:**
- SETEX بەکاردەهێنێت بۆ پشتگیری TTL
- `clear()` SCAN بەکاردەهێنێت بۆ لابردنی تەنها کلیلەکانی پێشگردار
- `prune()` ژمارەی 0 دەگەڕێنێتەوە (Redis بەسەرچوون بەڕێوە دەبات)

---

### PdoStorage

هەڵگرتنی cache ی بنکەی دراوە PDO.

#### دروستکەر

```php
public function __construct(PDO $pdo, string $table = 'easycache')
```

**پارامەترەکان:**
- `$pdo` (PDO): نموونەی PDO
- `$table` (string): ناوی خشتە (بنەڕەت: 'easycache')

**نموونە:**
```php
$pdo = new PDO('mysql:host=localhost;dbname=cache', 'user', 'pass');
$storage = new PdoStorage($pdo, 'cache_items');
```

#### ()ensureTable

دروستکردنی خشتەی cache ئەگەر بوونی نەبێت.

```php
public function ensureTable(): void
```

**هەڵە:**
- `\RuntimeException` ئەگەر دروستکردنی خشتە شکستی بهێنێت

**نموونە:**
```php
$storage->ensureTable();
```

**شێوازی خشتە:**
- `k` (VARCHAR(64) PRIMARY KEY): کلیلی cache
- `payload` (BLOB): داتا serialize و پەستراو
- `expires_at` (BIGINT): timestamp ی بەسەرچوون

#### میتۆدەکان

هەموو میتۆدەکانی `StorageInterface`.

**تێبینیەکان:**
- `prune()` ڕیزە بەسەرچووەکان دەسڕێتەوە و ژمارە دەگەڕێنێتەوە
- لە MySQL، PostgreSQL، SQLite پشتگیری دەکات
- UPSERT بەکاردەهێنێت بۆ کارپێکردنی set ی ئەتۆمی

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

serialization ی بنەماڵەیی PHP.

```php
$serializer = new NativeSerializer();
```

**تایبەتمەندیەکان:**
- لە objects و جۆرە ئاڵۆزەکان پشتگیری دەکات
- ناو: 'php'
- `serialize()` و `unserialize()` بەکاردەهێنێت

---

### JsonSerializer

serialization ی JSON.

```php
$serializer = new JsonSerializer();
```

**تایبەتمەندیەکان:**
- بارگاوییە لە نێوان زمانەکان
- ناو: 'json'
- flag ی `JSON_THROW_ON_ERROR` بەکاردەهێنێت
- پیتەکانی Unicode پارێزراو دەکات

**هەڵە:**
- `\JsonException` لە کاتی داتا نادروست

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

بێ پەستاندن.

```php
$compressor = new NullCompressor();
```

**تایبەتمەندیەکان:**
- تێپەڕ (بێ پەستاندن)
- ناو: 'none'

---

### GzipCompressor

پەستاندنی Gzip.

```php
public function __construct(int $level = 3)
```

**پارامەترەکان:**
- `$level` (int): ئاستی پەستاندن 0-9 (بنەڕەت: 3)

**نموونە:**
```php
$compressor = new GzipCompressor(5);
```

**تایبەتمەندیەکان:**
- ناو: 'gzip'
- پێویستی بە ext-zlib هەیە
- ئاست 0 = بێ پەستاندن، 9 = زۆرترین

**هەڵە:**
- `\RuntimeException` ئەگەر zlib بەردەست نەبێت یان پەستاندن شکستی بهێنێت

---

### ZstdCompressor

پەستاندنی Zstandard.

```php
public function __construct(int $level = 3)
```

**پارامەترەکان:**
- `$level` (int): ئاستی پەستاندن (بنەڕەت: 3)

**نموونە:**
```php
$compressor = new ZstdCompressor(3);
```

**تایبەتمەندیەکان:**
- ناو: 'zstd'
- پێویستی بە ext-zstd هەیە
- خێراترە لە Gzip

**هەڵە:**
- `\RuntimeException` ئەگەر zstd بەردەست نەبێت یان پەستاندن شکستی بهێنێت

---

## ئامرازەکان

### KeyValidator

پشکنینی دروستی کلیلەکانی cache بەپێی PSR-16.

#### ()assert

```php
public static function assert(string $key): void
```

**پارامەترەکان:**
- `$key` (string): کلیل بۆ پشکنین

**هەڵە:**
- `InvalidArgument` ئەگەر کلیل نادروست بێت

**یاساکان:**
- زۆرترین 64 پیت
- تەنها: `A-Za-z0-9_.`
- قەدەغە: `{}()/\@:`

**نموونە:**
```php
KeyValidator::assert('user_123');     // سەرکەوتوو
KeyValidator::assert('user:123');     // هەڵە
KeyValidator::assert('user/profile'); // هەڵە
```

---

### Lock

میکانیزمی قفڵی پشتبەست بە فایل.

#### دروستکەر

```php
public function __construct(string $path)
```

**پارامەترەکان:**
- `$path` (string): ڕێچکەی فایلی قفڵ

---

#### ()acquire

```php
public function acquire(bool $blocking = true): bool
```

**پارامەترەکان:**
- `$blocking` (bool): چاوەڕوانی قفڵ بکە (true) یان دەستبەجێ شکست بهێنە (false)

**دەگەڕێنێتەوە:** `true` ئەگەر قفڵ بەدەستهات

**نموونە:**
```php
$lock = new Lock('/tmp/my.lock');

// دۆخی بلۆککەر
if ($lock->acquire(true)) {
    // کار بکە
    $lock->release();
}

// دۆخی نابلۆککەر
if ($lock->acquire(false)) {
    // کار بکە
} else {
    echo "نەیتوانی قفڵ بەدەستبهێنێت";
}
```

---

#### ()release

```php
public function release(): void
```

**تێبینی:** بە خۆکاری لە destructor بانگ دەکرێت.

---

## هەڵەکان

### InvalidArgument

بۆ کلیلەکانی cache یان ئارگومێنتە نادروستەکان فڕێدەدرێت.

```php
class InvalidArgument extends \InvalidArgumentException 
    implements Psr\SimpleCache\InvalidArgumentException
```

**نموونە:**
```php
try {
    $cache->set('invalid:key', 'value');
} catch (InvalidArgument $e) {
    echo "کلیلی نادروست: " . $e->getMessage();
}
```

---

## یەکخستن لەگەڵ Laravel

### Facade

```php
use EasyCache;

EasyCache::set('key', 'value', 3600);
$value = EasyCache::get('key');
```

هەموو میتۆدەکانی `MultiTierCache` بەردەستن.

### ڕێکخستن

بڵاودەکرێتەوە لە `config/easycache.php`:

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
    
    // هەڵبژاردنەکانی تایبەت بە backend
];
```

### Service Provider

بە خۆکاری دەدۆزرێتەوە. تۆمار دەکات:
- Facade ی `EasyCache`
- Singleton ی `easycache` لە container

---

## سەرچاوەی جۆرەکان

### جۆرە باوەکان

```php
// جۆرەکانی TTL
int           // چرکە
DateInterval  // بۆ نموونە new DateInterval('PT1H')
null          // بەکارهێنانی TTL ی بنەڕەتی

// جۆرە پشتگیریکراوەکانی بەها
string
int
float
bool
null
array
object (لەگەڵ NativeSerializer)
```

---

## بەڕێوەبردنی هەڵە

هەموو کارپێکردنەکان لە ناوخۆ لە try-catch دان. شکستەکان ئەگەر logger دابینکرابێت تۆمار دەکرێن و لە جیاتی فڕێدان false/null دەگەڕێنەوە.

**هەڵە فڕێدراوەکان:**
- `InvalidArgument`: کلیل یان پارامەترە نادروستەکان
- `\RuntimeException`: هەڵەکانی دروستکردن (زیادکراوە ونبووەکان، ڕێچکە نادروستەکان)
- `\JsonException`: هەڵەکانی serialization ی JSON (JsonSerializer)

**هەڵە فڕێنەدراوەکان (لە جیاتی تۆمار دەکرێن):**
- شکستەکانی storage (هەڵەکانی خوێندنەوە/نووسین)
- هەڵەکانی پەستاندن/کردنەوەی پەستاو
- شکستەکانی بەدەستهێنانی قفڵ

---

بۆ نموونەی زیاتر، [EXAMPLES.md](EXAMPLES.md) ببینە.

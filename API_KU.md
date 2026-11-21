# سەرچاوەی API

بەڵگەنامەی تەواوی API بۆ PHP EasyCache وەشانی 3.1.

## پێرستی ناوەڕۆک

- [MultiTierCache](#multitiercache)
- [TaggedCache](#taggedcache)
- [Storage Backends](#storage-backends)
- [قفڵەکان (Locks)](#قفڵەکان-locks)
- [Serializers](#serializers)
- [Compressors](#compressors)
- [کاتژمێرەکان (Clocks)](#کاتژمێرەکان-clocks)
- [ئامرازەکان](#ئامرازەکان)
- [هەڵەکان](#هەڵەکان)
- [یەکخستن لەگەڵ Laravel](#یەکخستن-لەگەڵ-laravel)
- [شێوازی تۆمار](#شێوازی-تۆمار)

---

## MultiTierCache

پۆلی سەرەکی cache کە ڕووکاری PSR-16 واتە `CacheInterface` جێبەجێ دەکات.

### دروستکەر (Constructor)

```php
public function __construct(
    array $tiers,
    ?SerializerInterface $serializer = null,
    ?CompressorInterface $compressor = null,
    int $defaultTtl = 3600,
    ?LoggerInterface $logger = null,
    ?string $lockPath = null,
    ?ClockInterface $clock = null,
    ?string $signingKey = null,
    ?LockProviderInterface $lockProvider = null,
    float $lockTimeout = 30.0
)
```

**پارامەترەکان:**
- `$tiers` (StorageInterface[]): چینەکانی هەڵگرتن، ڕیزکراو لە خێراترینەوە بۆ خاوترین
- `$serializer`: بنەڕەت `NativeSerializer`
- `$compressor`: بنەڕەت `NullCompressor`
- `$defaultTtl` (int): TTL ی بنەڕەت بە چرکە کاتێک TTL نەدرابێت. `0` واتە هەرگیز بەسەرناچێت (بنەڕەت: 3600)
- `$logger`: logger ی PSR-3. هەروەها دەدرێت بە هەر چینێک کە `LoggerAwareInterface` جێبەجێ دەکات و هێشتا logger ی نییە
- `$lockPath` (string|null): بوخچەی فایلەکانی قفڵ (بنەڕەت: بوخچەیەکی تایبەتی بەکارهێنەر لە temp ی سیستەم). ئەگەر `$lockProvider` درابێت پشتگوێ دەخرێت
- `$clock` (`Psr\Clock\ClockInterface`): سەرچاوەی کات (بنەڕەت: `SystemClock`)
- `$signingKey` (string|null): کلیلێکی نهێنی بە لایەنی کەم 16 بایت. ئەگەر دابنرێت، تۆمارەکان بە HMAC-SHA256 واژۆ دەکرێن و تۆماری بێ واژۆ یان دەستکاریکراو **پێش** deserialize کردن ڕەت دەکرێتەوە
- `$lockProvider`: جێبەجێکردنی قفڵ (بنەڕەت: `FileLockProvider`)
- `$lockTimeout` (float): زۆرترین چرکەی چاوەڕوانی بۆ قفڵ لە cache miss؛ دواتر بەها بەبێ قفڵ هەژمار دەکرێت (بنەڕەت: 30)

**هەڵە:**
- `\InvalidArgumentException` ئەگەر `$tiers` بەتاڵ بێت یان شتێکی تێدابێت کە `StorageInterface` نییە، ئەگەر کلیلی واژۆ لە 16 بایت کورتتر بێت، یان ناوی serializer/compressor بەتاڵ یان لە 255 بایت درێژتر بێت

دروستکەر دەست لە سیستەمی فایل نادات: بوخچەی قفڵ لە یەکەم بەکارهێناندا دروست دەکرێت.

**نموونە:**
```php
$cache = new MultiTierCache(
    [new ApcuStorage('app:', maxTtl: 60), new FileStorage('/var/cache/app')],
    new NativeSerializer(allowedClasses: [UserDto::class]),
    new GzipCompressor(5),
    defaultTtl: 3600,
    logger: $logger,
    signingKey: $_ENV['EASYCACHE_SIGNING_KEY'],
);
```

---

### ()get

```php
public function get(string $key, mixed $default = null): mixed
```

- `$key`: کلیل (یاساکان لە [KeyValidator](#keyvalidator))
- `$default`: ئەو بەهایەی لە کاتی miss دەگەڕێتەوە

**دەگەڕێنێتەوە:** بەهای cache کراو (دەکرێت `null` یان `false` بێت) یان `$default`

**هەڵە:** `InvalidArgument` بۆ کلیلی نادروست

چینەکان بە ڕیز دەخوێندرێنەوە. وەشانێکی بەسەرچوو لە چینێکی خێرادا گەڕان ناوەستێنێت: لەوانەیە چینێکی خاوتر وەشانێکی تازەتری هەبێت (کە ڕاژەکارێکی تر نووسیویەتی). ئەو کاتە ئەو وەشانە لە چینە خێراترەکانیشدا دەنووسرێتەوە (backfill).

```php
$value = $cache->get('user_123', ['name' => 'نەزانراو']);
```

---

### ()set

```php
public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
```

- `$ttl`: `null` واتە TTL ی بنەڕەت؛ ژمارەی پۆزەتیڤ یان `DateInterval` واتە ماوەی ژیان؛ `0` یان ژمارەی نێگەتیڤ بڕگەکە **دەسڕێتەوە** (بەپێی PSR-16)

**دەگەڕێنێتەوە:** `true` ئەگەر هەموو چینەکان بڕگەکەیان هەڵگرتبێت

```php
$cache->set('user_123', $user, 3600);
$cache->set('config', $config, new DateInterval('P1D'));
```

---

### ()delete

```php
public function delete(string $key): bool
```

**دەگەڕێنێتەوە:** `true` کاتێک بڕگەکە لە هەموو چینەکان نەماوە (تەنانەت ئەگەر هەرگیز بوونی نەبووبێت)؛ `false` تەنها لە کاتی هەڵەدا

---

### ()clear

```php
public function clear(): bool
```

هەموو چینەکان پاک دەکاتەوە. هەر storage ێک تەنها تۆمارەکانی خۆی دەسڕێتەوە (بەپێی prefix یان شێوازی ناوی فایل)، نەک داتای تر.

---

### ()has

```php
public function has(string $key): bool
```

**دەگەڕێنێتەوە:** `true` ئەگەر تۆمارێکی تازە هەبێت (تەنانەت کاتێک بەهای cache کراو `null` ە). بەهاکە deserialize ناکرێت.

---

### ()getMultiple

```php
public function getMultiple(iterable $keys, mixed $default = null): array
```

**دەگەڕێنێتەوە:** ڕیزی key => value بە ڕیزبەندی `$keys`

**هەڵە:** `InvalidArgument` ئەگەر یەکێک لە کلیلەکان نادروست یان string نەبێت

ئەو storage انەی `BatchStorageInterface` جێبەجێ دەکەن لە هەر چینێکدا بە یەک داواکاری دەخوێندرێنەوە، و لە چینە نزمترەکان تەنها ئەو کلیلانە دەپرسرێن کە هێشتا نەدۆزراونەتەوە.

```php
$cache->getMultiple(['key1', 'key2', 'key3'], null);
// ['key1' => 'value1', 'key2' => 'value2', 'key3' => null]
```

---

### ()setMultiple

```php
public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
```

هەموو کلیلەکان پێش نووسین پشکنین دەکرێن. TTL ی بچووکتر یان یەکسان بە سفر کلیلەکان دەسڕێتەوە.

---

### ()deleteMultiple

```php
public function deleteMultiple(iterable $keys): bool
```

---

### ()getOrSetSWR

وەرگرتن یان هەژمارکردنی بەها لەگەڵ stale-while-revalidate، stale-if-error و ڕێگری لە cache stampede.

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
- `$producer`: فەنکشنێک بە شێوەی `function(): mixed` کە بەهای تازە بەرهەم دەهێنێت
- `$ttl`: ماوەی تازەبوون. `null` واتە TTL ی بنەڕەت (`0` = هەرگیز بەسەرناچێت). بەهای بچووکتر یان یەکسان بە سفر واتە هەژمارکردن **بەبێ cache کردن**
- `$swrSeconds`: تا چەند چرکە دوای بەسەرچوون بەهای کۆن پێشکەش بکرێت لە کاتێکدا نوێ دەکرێتەوە
- `$staleIfErrorSeconds`: تا چەند چرکە دوای بەسەرچوون، ئەگەر producer هەڵەی دا، بەهای کۆن پێشکەش بکرێت
- `$options['mode']`: `'sync'` (بنەڕەت) یان `'defer'`

**هەڵە:**
- `InvalidArgument` بۆ کلیل یان mode ی نادروست
- هەڵەکانی `$producer` کاتێک هیچ بەهایەکی کۆنی بەکارهاتوو نییە

**ڕەفتار:**

| دۆخی تۆمار | چی ڕوودەدات |
|---|---|
| تازە | دەستبەجێ دەگەڕێتەوە |
| بەسەرچوو، لەناو `swrSeconds` | تەنها یەک پرۆسە تۆمارەکە نوێ دەکاتەوە؛ ئەو پرۆسانەی قفڵەکە وەرناگرن بەبێ چاوەڕوانی بەهای کۆن وەردەگرن. لە دۆخی `sync` پرۆسەی نوێکەرەوە بەهای **نوێ** دەگەڕێنێتەوە؛ لە دۆخی `defer` بەهای کۆن دەگەڕێتەوە و نوێکردنەوە دوای ناردنی وەڵام ئەنجام دەدرێت (PHP-FPM/LiteSpeed، ئەگەرنا `sync`). ئەگەر نوێکردنەوە سەرنەکەوێت بەهای کۆن دەگەڕێتەوە |
| بەسەرچوو، تەنها لەناو `staleIfErrorSeconds` | دووبارە هەژمار دەکرێت؛ تەنها ئەگەر producer هەڵە بدات بەهای کۆن دەگەڕێتەوە |
| بوونی نییە (یان زۆر کۆنە) | لەژێر قفڵی تایبەت بەو کلیلە هەژمار دەکرێت (زۆرترین چاوەڕوانی `lockTimeout`). دوای وەرگرتنی قفڵ cache دووبارە دەپشکنرێت، بۆیە داواکارییە هاوکاتەکان producer تەنها یەک جار جێبەجێ دەکەن |

ئەنجامی `null` یش وەک هەر بەهایەکی تر cache دەکرێت.

```php
$data = $cache->getOrSetSWR(
    'expensive_data',
    fn () => computeExpensiveData(),
    ttl: 300,
    swrSeconds: 60,
    staleIfErrorSeconds: 600,
    options: ['mode' => 'defer'],
);
```

---

### ()remember

```php
public function remember(string $key, null|int|DateInterval $ttl, callable $producer): mixed
```

کورتکراوەی `getOrSetSWR($key, $producer, $ttl)` (بەبێ پێشکەشکردنی بەهای کۆن، لەگەڵ ڕێگری لە stampede).

---

### ()forever

```php
public function forever(string $key, mixed $value): bool
```

هەڵگرتنی بەها بەبێ بەسەرچوون.

---

### ()tags و ()invalidateTags

```php
public function tags(string|array $tags): TaggedCache
public function invalidateTags(string|array $tags): bool
```

`tags()` دیمەنێکی [TaggedCache](#taggedcache) دەگەڕێنێتەوە. `invalidateTags()` هەموو ئەو تۆمارانەی بە هەر یەکێک لەم tag انە نووسراون نەگەیشتوو دەکات. ئەم تۆمارانە ناسڕدرێنەوە؛ بە TTL ی خۆیان یان `prune()` لادەبرێن.

```php
$cache->tags(['users', 'team-7'])->set('user-42', $user, 3600);
$cache->invalidateTags('team-7');
```

---

### ()prune

```php
public function prune(): int
```

**دەگەڕێنێتەوە:** ژمارەی تۆمارە لابراوەکان. `FileStorage` و `PdoStorage` تۆمارە بەسەرچووەکان دەسڕنەوە (FileStorage هەروەها فایلی شێوازی نەناسراو و فایلی کاتیی بەجێماو پاک دەکاتەوە)؛ APCu و Redis خۆیان تۆمارەکان بەسەر دەبەن.

---

### ()getStats و ()resetStats

```php
public function getStats(): array
public function resetStats(): void
```

ژمێرەرەکانی ئەم نموونەیە:

```php
[
    'hits' => 120, 'misses' => 8, 'stale_hits' => 3, 'stale_if_error' => 1,
    'writes' => 10, 'deletes' => 2, 'errors' => 0, 'hit_ratio' => 0.9426,
]
```

---

## TaggedCache

دیمەنێکی PSR-16 کە `MultiTierCache::tags()` دەیگەڕێنێتەوە. میتۆدەکانی `get`، `set`، `delete`، `has`، `getMultiple`، `setMultiple`، `deleteMultiple`، `getOrSetSWR`، `remember` و `forever` ی هەیە. `clear()` لەسەر ئەم دیمەنە tag ەکانی بەتاڵ دەکاتەوە. تۆمارەکان دەبێت بە هەمان کۆمەڵە tag بخوێندرێنەوە کە پێی نووسراون (ڕیزبەندی گرنگ نییە). درێژی کلیل زۆرترین 217 بایتە.

---

## Storage Backends

### StorageInterface

```php
interface StorageInterface
{
    public function get(string $key): ?string;          // null ئەگەر بوونی نەبێت یان بەسەرچووبێت
    public function set(string $key, string $payload, int $ttl): bool; // $ttl = 0 واتە بەبێ بەسەرچوون
    public function delete(string $key): bool;          // بۆ کلیلی نەبوویش true
    public function has(string $key): bool;
    public function clear(): bool;                      // تەنها تۆمارەکانی ئەم storage ە
    public function prune(): int;                       // ژمارەی تۆمارە لابراوەکان
}
```

### BatchStorageInterface

ئارەزوومەندانە؛ هەموو storage ە ناوخۆییەکان جێبەجێیان کردووە:

```php
interface BatchStorageInterface extends StorageInterface
{
    public function getMany(array $keys): array;              // payload ە دۆزراوەکان بەپێی کلیل
    public function setMany(array $payloads, int $ttl): bool;
    public function deleteMany(array $keys): bool;
}
```

هەموو storage ە ناوخۆییەکان `Psr\Log\LoggerAwareInterface` جێبەجێ دەکەن و لە جیاتی `error_log()` هەڵەکان لە ڕێگەی PSR-3 ڕاپۆرت دەکەن.

---

### FileStorage

```php
public function __construct(
    string $path,
    string $ext = '.cache',
    int $shards = 2,
    int $fileMode = 0640,
    int $dirMode = 0770,
    ?ClockInterface $clock = null,
    ?LoggerInterface $logger = null
)
```

- `$path`: بوخچەی cache (ئەگەر نەبێت دروست دەکرێت). نابێت بەتاڵ یان `/` بێت
- `$ext`: پاشگری فایل؛ تەنها پیت، ژمارە و `.` `_` `-`
- `$shards`: ژمارەی ئاستەکانی ژێربوخچە (0 تا 3)
- `$fileMode` و `$dirMode`: مۆڵەتە ورددەکان (umask جێبەجێ ناکرێت)

**هەڵە:** `\InvalidArgumentException` بۆ ڕێڕەو یان پاشگری نادروست؛ `\RuntimeException` ئەگەر بوخچە دروست نەکرێت یان نەتوانرێت تێیدا بنووسرێت

**تێبینی:**
- ناوی فایل hash ی xxh128 ی کلیلە؛ لەگەڵ 2 shard پێکهاتە `path/a1/b2/<hash>.cache` ە
- هەر فایلێک بە `ECF1` و کاتی بەسەرچوون دەست پێدەکات: `get()` فایلی بەسەرچوو پشتگوێ دەخات (و دەیسڕێتەوە) و `prune()` پاکی دەکاتەوە
- نووسینی ئەتۆمی: فایلی کاتیی تایبەت (`fopen('x')` کە هەرگیز دوای symlink ناکەوێت) و پاشان `rename()`
- بوخچەکان تەنها لە کاتی نووسیندا دروست دەکرێن
- `clear()` تەنها ئەو فایلانە دەسڕێتەوە کە لەگەڵ شێوازی ناوی ئەم storage ە لە بوخچەکانی shard دا دەگونجێن و هەرگیز دوای symlink ناکەوێت

---

### ApcuStorage

```php
public function __construct(string $prefix = 'ec:', int $maxTtl = 0, ?LoggerInterface $logger = null)
public static function isAvailable(): bool
```

- `$prefix`: پێویست و نابێت بەتاڵ بێت (`clear()` هەر کلیلێک بەم prefix ە دەسڕێتەوە)
- `$maxTtl`: سنووری TTL ی تۆمارەکانی ئەم چینە (0 = بێ سنوور). APCu تەنها تایبەت بە یەک ڕاژەکارە؛ سنوورێکی کورت وا دەکات گۆڕانکارییەکانی ڕاژەکارەکانی تر زوو دەربکەون

**هەڵە:** `\RuntimeException` ئەگەر APCu دانەمەزرابێت یان چالاک نەبێت (لە CLI دا `apc.enable_cli=1` پێویستە)؛ `\InvalidArgumentException` بۆ prefix ی بەتاڵ

---

### RedisStorage

```php
public function __construct(object $redisClient, string $prefix = 'ec:', ?LoggerInterface $logger = null)
```

- `$redisClient`: نموونەی `\Redis` (phpredis) یان `\Predis\ClientInterface`
- `$prefix`: پێویست و نابێت بەتاڵ بێت

**هەڵە:** `\InvalidArgumentException` بۆ client ی پشتگیرینەکراو یان prefix ی بەتاڵ

**تێبینی:**
- `SETEX` بۆ TTL، `MGET` بۆ خوێندنەوەی کۆمەڵ و pipeline بۆ نووسینی کۆمەڵ
- `clear()` بە `SCAN` ی نابلۆککەر و prefix ی escape کراو کار دەکات (بۆ Predis یش) و prefix ی ئاستی client (`Redis::OPT_PREFIX` و هەڵبژاردەی `prefix` لە Predis) بەدروستی بەڕێوە دەبات
- `prune()` سفر دەگەڕێنێتەوە (Redis خۆی کلیلەکان بەسەر دەبات)

---

### PdoStorage

```php
public function __construct(
    PDO $pdo,
    string $table = 'easycache',
    ?ClockInterface $clock = null,
    ?LoggerInterface $logger = null
)
public function ensureTable(): void
public function upgradeSchema(): void
```

- `$table`: بە شێوەی `[A-Za-z_][A-Za-z0-9_]*` یان `schema.table`؛ هەر شتێکی تر `\InvalidArgumentException` دەدات
- ڕێکخستنەکانی پەیوەندی (وەک error mode) ناگۆڕدرێن؛ هەردوو دۆخی exception و silent پشتگیری دەکرێن
- payload بە شێوەی دووانەیی (`PDO::PARAM_LOB`) bind دەکرێت
- کلیلی درێژتر لە 64 بایت بە شێوەی `':' . sha256` هەڵدەگیرێت تاکو لە ستوونی کلیلدا جێی ببێتەوە
- `ensureTable()` لە کاتی پێویستدا خشتەکە دروست دەکات:

| درایڤەر | کلیل | Payload | بەسەرچوون |
|---|---|---|---|
| SQLite | `TEXT` | `BLOB` | `INTEGER` |
| MySQL/MariaDB | `VARBINARY(64)` (هەستیار بە پیتی گەورە و بچووک) | `LONGBLOB` | `BIGINT` |
| PostgreSQL | `VARCHAR(64)` | `BYTEA` | `BIGINT` |

- `upgradeSchema()` خشتەکانی MySQL کە بە EasyCache ی پێش 3.1 دروست کراون (`BLOB` و کلیلی ناهەستیار) نوێ دەکاتەوە
- `prune()` ڕیزە بەسەرچووەکان دەسڕێتەوە و ژمارەیان دەگەڕێنێتەوە

---

## قفڵەکان (Locks)

### LockInterface و LockProviderInterface

```php
interface LockInterface
{
    public function acquire(bool $blocking = true, ?float $timeout = null): bool;
    public function release(): void;
}

interface LockProviderInterface
{
    public function create(string $key): LockInterface;
}
```

### FileLockProvider

```php
public function __construct(?string $directory = null, int $mode = 0770)
```

قفڵی لەسەر بنەمای `flock()` (تەنها لە یەک ئامێردا). بەبێ بوخچە، بوخچەیەکی تایبەتی بەکارهێنەر لە temp ی سیستەم بە مۆڵەتی `0700` دروست دەکرێت و تەنها کاتێک بەکاردێت کە خاوەنەکەی بەکارهێنەری ئێستا بێت و کەسانی تر نەتوانن تێیدا بنووسن. ئەگەر بوخچەکە بەکارنەیەت، قفڵ وەرناگیرێت و بەها بەبێ قفڵ هەژمار (و هەر cache) دەکرێت.

### RedisLockProvider

```php
public function __construct(object $redis, string $prefix = 'ec:lock:', int $ttlMs = 30000)
```

قفڵی دابەشکراو بۆ چەند ڕاژەکار (`SET key token NX PX ttl` و ئازادکردن بە سکریپتی Lua ی compare-and-delete). TTL پارێزگاری لە پرۆسەی تێکشکاو دەکات؛ `acquire()` ی بلۆککەر زۆرترین بە ئەندازەی TTL چاوەڕێ دەکات.

### NullLockProvider

قفڵ ناچالاک دەکات (هەر قفڵێک دەستبەجێ وەردەگیرێت).

### Util\Lock

```php
public function __construct(string $path, bool $removeOnRelease = true)
public function acquire(bool $blocking = true, ?float $timeout = null): bool
public function release(): void
public function isAcquired(): bool
```

قفڵی فایلی کە `FileLockProvider` بەکاری دەهێنێت. فایلی قفڵی symlink ڕەت دەکاتەوە، timeout ی هەیە و لە کاتی ئازادکردندا فایلی قفڵ دەسڕێتەوە (بەبێ race: دوای قفڵکردن دەپشکنێت کە فایلە قفڵکراوەکە هێشتا هەمان فایلی ناو ڕێڕەوەکەیە).

---

## Serializers

### SerializerInterface

```php
interface SerializerInterface
{
    public function serialize(mixed $value): string;
    public function deserialize(string $payload): mixed; // بۆ داتای نادروست دەبێت exception بدات
    public function name(): string;                      // 1 تا 255 بایت، لەگەڵ هەر تۆمارێک هەڵدەگیرێت
}
```

### NativeSerializer

```php
public function __construct(bool|array $allowedClasses = true, int $maxDepth = 0)
```

- `$allowedClasses`: `true` (هەر پۆلێک)، `false` (بێ ئۆبجێکت) یان لیستێک لە ناوی پۆلەکان. پۆلەکانی تر دەبنە `__PHP_Incomplete_Class`
- `$maxDepth`: زۆرترین قووڵی (0 = بنەڕەتی PHP)
- بۆ payload ی نادروست `\UnexpectedValueException` دەدات (بەهای `false` ی هەڵگیراو هەر `false` دەگەڕێتەوە)
- ناو: `php`

> unserialize کردنی داتایەک کە هێرشبەر کۆنترۆڵی دەکات دەتوانێت ببێتە هۆی جێبەجێکردنی کۆد. `$allowedClasses` سنووردار بکە و/یان کلیلی واژۆ بەکاربهێنە.

### JsonSerializer

```php
public function __construct(int $depth = 512)
```

- هەرگیز ئۆبجێکت دروست ناکات: ئۆبجێکتەکان وەک ڕیزی هاوبەش دەگەڕێنەوە
- جۆری float دەپارێزرێت (`1.0` هەر float دەمێنێتەوە)
- بۆ داتای نادروست `\JsonException` دەدات
- ناو: `json`

---

## Compressors

### CompressorInterface

```php
interface CompressorInterface
{
    public function compress(string $data): string;
    public function decompress(string $data): string;
    public function name(): string; // لەگەڵ هەر تۆمارێک هەڵدەگیرێت؛ "none" واتە نەپەستێنراو
}
```

ناوی compressor لەگەڵ هەر تۆمارێک هەڵدەگیرێت. تۆمارە پەستێنراوەکان بە `gzip` یان `zstd` دوای گۆڕینی compressor یش دەخوێندرێنەوە؛ ئەو تۆمارانەی بە compressor ی تایبەت نووسراون بۆ خوێندنەوە پێویستیان بە هەمان compressor هەیە (ئەگەرنا miss دەژمێردرێن).

### NullCompressor

بەبێ پەستاندن. ناو: `none`.

### GzipCompressor

```php
public function __construct(int $level = 3, int $maxDecompressedSize = 64 * 1024 * 1024)
```

- `$level`: 0 تا 9 (دەرەوەی ئەم مەودایە سنووردار دەکرێت)
- `$maxDecompressedSize`: زیاتر لەم ژمارە بایتە ناکاتەوە (0 = بێ سنوور)
- پێویستی بە ext-zlib هەیە. ناو: `gzip`

### ZstdCompressor

```php
public function __construct(int $level = 3, int $maxDecompressedSize = 64 * 1024 * 1024)
```

- `$level`: 1 تا 22 (دەرەوەی ئەم مەودایە سنووردار دەکرێت)
- پێویستی بە ext-zstd هەیە. ناو: `zstd`

---

## کاتژمێرەکان (Clocks)

هەردووکیان `Psr\Clock\ClockInterface` جێبەجێ دەکەن.

- `SystemClock`: کاتی سیستەم (بنەڕەت)
- `FrozenClock`: بۆ تاقیکردنەوە: `new FrozenClock()`، `FrozenClock::at($timestamp)`، `->advance(int $seconds)` و `->set(DateTimeImmutable $now)`

هەمان clock بدە بە `MultiTierCache` و `FileStorage`/`PdoStorage` تاکو بەسەرچوون لە تاقیکردنەوەکاندا بەبێ `sleep()` کۆنترۆڵ بکەیت.

---

## ئامرازەکان

### KeyValidator

```php
public static function assert(string $key): void
public static function normalize(mixed $key): string // بۆ کلیلەکانی ناو iterable؛ ژمارەی تەواویش وەردەگیرێت
```

**یاساکان:**
- 1 تا 255 بایت (`KeyValidator::MAX_LENGTH`)
- تەنها ASCII ی چاپکراو (`!` تا `~`): بێ بۆشایی، پیتی کۆنترۆڵ یان بایتی نا-ASCII
- بێ پیتە پارێزراوەکانی `{}()/\@:` (`KeyValidator::RESERVED_CHARACTERS`)

```php
KeyValidator::assert('user_123');          // دروست
KeyValidator::assert('user-3f2a-77b2');    // دروست
KeyValidator::assert('user:123');          // هەڵە
KeyValidator::assert("user\n");            // هەڵە
```

---

## هەڵەکان

### InvalidArgument

```php
final class InvalidArgument extends \InvalidArgumentException
    implements Psr\SimpleCache\InvalidArgumentException
```

بۆ کلیل، tag یان mode ی نادروستی SWR فڕێدەدرێت.

---

## یەکخستن لەگەڵ Laravel

### Container و Facade

```php
app('easycache');                                    // singleton ی MultiTierCache
app(\Iprodev\EasyCache\Cache\MultiTierCache::class); // هەمان نموونە
EasyCache::remember('stats', 300, fn () => $this->computeStats());
```

Facade هەموو میتۆدە گشتییەکانی `MultiTierCache` پێشکەش دەکات.

### فەرمانەکانی Artisan

```bash
php artisan easycache:prune   # سڕینەوەی تۆمارە بەسەرچووەکان
php artisan easycache:clear   # سڕینەوەی هەموو تۆمارەکان
```

### ڕێکخستن

فەرمانی `php artisan vendor:publish --tag=easycache-config` فایلی `config/easycache.php` بڵاو دەکاتەوە. هەڵبژاردە سەرەکییەکان (هەموویان لە ڕێگەی گۆڕاوەکانی ژینگەی `EASYCACHE_*` یش ڕێکدەخرێن؛ README ببینە):

```php
return [
    'drivers' => ['file'],              // apcu، redis، file، pdo (خێراترین یەکەم)
    'skip_unavailable_drivers' => true, // درایڤەرێک کە دەست پێنەکات تۆمار و تێپەڕێنراو دەکرێت
    'default_ttl' => 600,
    'sign' => true,                     // واژۆکردنی تۆمارەکان...
    'signing_key' => null,              // ...بەم کلیلە یان کلیلێک کە لە APP_KEY وەرگیراوە
    'lock' => ['driver' => 'file', 'path' => storage_path('framework/cache/easycache-locks'), 'timeout' => 30],
    'apcu' => ['prefix' => 'ec:', 'max_ttl' => 60],
    'redis' => ['client' => 'phpredis', 'host' => '127.0.0.1', 'port' => 6379, 'prefix' => 'ec:'],
    'file' => ['path' => storage_path('framework/cache/easycache'), 'ext' => '.cache', 'shards' => 2],
    'pdo' => ['dsn' => '', 'table' => 'easycache', 'auto_create' => true], // '' = SQLite لە storage
    'serializer' => ['driver' => 'php', 'allowed_classes' => true],
    'compressor' => ['driver' => 'none', 'level' => 3],
];
```

---

## شێوازی تۆمار

```
"EC03" | flags (1 بایت، بیتی 0 = واژۆکراو) | expires (uint64 BE، 0 = هەرگیز)
| swr (uint32 BE) | sei (uint32 BE)
| درێژی ناوی serializer (1) + ناو | درێژی ناوی compressor (1) + ناو
| [HMAC-SHA256 لەسەر سەردێڕ + ناوەڕۆک (32 بایت)، ئەگەر واژۆ کرابێت]
| body = compress(serialize(value))
```

سەردێڕ بە `unpack()` دەخوێندرێتەوە؛ تەنها ناوەڕۆک، و تەنها دوای پشتڕاستکردنەوەی واژۆ (ئەگەر هەبێت)، دەدرێت بە serializer. تۆمار بە هەر شێوازێکی تر miss دەژمێردرێت.

---

## بەڕێوەبردنی هەڵە

هەڵەکانی storage، serialization و پەستاندن تۆمار دەکرێن (PSR-3) و لە `getStats()['errors']` دا دەژمێردرێن؛ لەو کاتەدا کردارەکە لە جیاتی فڕێدانی exception، `false`، بەهای بنەڕەت یان miss دەگەڕێنێتەوە.

**ئەو هەڵانەی فڕێدەدرێن:**
- `InvalidArgument`: کلیل، tag یان هەڵبژاردەی نادروست
- `\InvalidArgumentException` / `\RuntimeException`: هەڵەکانی دروستکردن (پاشکۆی نەبوو، ڕێڕەوی نادروست، کلیلی واژۆی کورت...)
- هەڵەکانی producer ی تۆ لە `getOrSetSWR()`/`remember()` کاتێک هیچ بەهایەکی کۆنی پێشکەشکراو نییە

---

بۆ نموونەی زیاتر سەیری [EXAMPLES_KU.md](EXAMPLES_KU.md) بکە.

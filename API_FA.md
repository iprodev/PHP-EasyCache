# مرجع API

مستندات کامل API برای PHP EasyCache نسخهٔ 3.1.

## فهرست مطالب

- [MultiTierCache](#multitiercache)
- [TaggedCache](#taggedcache)
- [Storage Backends](#storage-backends)
- [قفل‌ها (Locks)](#قفلها-locks)
- [Serializers](#serializers)
- [Compressors](#compressors)
- [ساعت‌ها (Clocks)](#ساعتها-clocks)
- [ابزارها](#ابزارها)
- [استثناها](#استثناها)
- [یکپارچگی با Laravel](#یکپارچگی-با-laravel)
- [قالب رکورد](#قالب-رکورد)

---

## MultiTierCache

کلاس اصلی cache که رابط PSR-16 یعنی `CacheInterface` را پیاده‌سازی می‌کند.

### سازنده (Constructor)

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

**پارامترها:**
- `$tiers` (StorageInterface[]): لایه‌های ذخیره‌سازی، مرتب از سریع‌ترین به کندترین
- `$serializer`: پیش‌فرض `NativeSerializer`
- `$compressor`: پیش‌فرض `NullCompressor`
- `$defaultTtl` (int): TTL پیش‌فرض به ثانیه وقتی TTL داده نشود. مقدار `0` یعنی بدون انقضا (پیش‌فرض: 3600)
- `$logger`: logger سازگار با PSR-3. به هر لایه‌ای هم که `LoggerAwareInterface` را پیاده کرده و هنوز logger ندارد داده می‌شود
- `$lockPath` (string|null): دایرکتوری فایل‌های قفل (پیش‌فرض: یک دایرکتوری خصوصی مخصوص همان کاربر در temp سیستم). اگر `$lockProvider` داده شود نادیده گرفته می‌شود
- `$clock` (`Psr\Clock\ClockInterface`): منبع زمان (پیش‌فرض: `SystemClock`)
- `$signingKey` (string|null): کلید مخفی با حداقل ۱۶ بایت. اگر تنظیم شود رکوردها با HMAC-SHA256 امضا می‌شوند و رکوردهای بدون امضا یا دستکاری‌شده **پیش از** deserialize شدن رد می‌شوند
- `$lockProvider`: پیاده‌سازی قفل (پیش‌فرض: `FileLockProvider`)
- `$lockTimeout` (float): حداکثر ثانیه‌های انتظار برای قفل در cache miss؛ بعد از آن مقدار بدون قفل محاسبه می‌شود (پیش‌فرض: 30)

**استثناها:**
- `\InvalidArgumentException` اگر `$tiers` خالی باشد یا عضوی غیر از `StorageInterface` داشته باشد، کلید امضا کوتاه‌تر از ۱۶ بایت باشد، یا نام serializer/compressor خالی یا بیش از ۲۵۵ بایت باشد

سازنده به فایل‌سیستم دست نمی‌زند: دایرکتوری قفل در اولین استفاده ساخته می‌شود.

**مثال:**
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

- `$key`: کلید (قوانین در [KeyValidator](#keyvalidator))
- `$default`: مقداری که در صورت miss برگردانده می‌شود

**خروجی:** مقدار کش‌شده (که می‌تواند `null` یا `false` هم باشد) یا `$default`

**استثنا:** `InvalidArgument` برای کلید نامعتبر

لایه‌ها به ترتیب خوانده می‌شوند. نسخهٔ منقضی‌شده در یک لایهٔ سریع جستجو را متوقف نمی‌کند: ممکن است یک لایهٔ کندتر نسخهٔ تازه‌تری داشته باشد (که سرور دیگری نوشته). در این صورت آن نسخه در لایه‌های سریع‌تر هم نوشته می‌شود (backfill).

```php
$value = $cache->get('user_123', ['name' => 'Unknown']);
```

---

### ()set

```php
public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
```

- `$ttl`: مقدار `null` یعنی TTL پیش‌فرض؛ عدد مثبت یا `DateInterval` یعنی طول عمر؛ `0` یا عدد منفی آیتم را **حذف** می‌کند (طبق PSR-16)

**خروجی:** اگر همهٔ لایه‌ها آیتم را ذخیره کنند `true`

```php
$cache->set('user_123', $user, 3600);
$cache->set('config', $config, new DateInterval('P1D'));
```

---

### ()delete

```php
public function delete(string $key): bool
```

**خروجی:** اگر آیتم از همهٔ لایه‌ها حذف شده باشد `true` (حتی اگر از اول وجود نداشته)؛ `false` فقط در صورت خطا

---

### ()clear

```php
public function clear(): bool
```

همهٔ لایه‌ها را پاک می‌کند. هر storage فقط رکوردهای خودش را حذف می‌کند (بر اساس prefix یا الگوی نام فایل)، نه دادهٔ دیگری را.

---

### ()has

```php
public function has(string $key): bool
```

**خروجی:** اگر رکورد تازه‌ای وجود داشته باشد `true` (حتی وقتی مقدار کش‌شده `null` است). مقدار deserialize نمی‌شود.

---

### ()getMultiple

```php
public function getMultiple(iterable $keys, mixed $default = null): array
```

**خروجی:** آرایهٔ key => value به ترتیب `$keys`

**استثنا:** `InvalidArgument` اگر یکی از کلیدها نامعتبر یا غیررشته‌ای باشد

storageهایی که `BatchStorageInterface` را پیاده می‌کنند در هر لایه با یک درخواست خوانده می‌شوند، و از لایه‌های پایین‌تر فقط کلیدهایی پرسیده می‌شود که هنوز پیدا نشده‌اند.

```php
$cache->getMultiple(['key1', 'key2', 'key3'], null);
// ['key1' => 'value1', 'key2' => 'value2', 'key3' => null]
```

---

### ()setMultiple

```php
public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
```

همهٔ کلیدها پیش از نوشتن اعتبارسنجی می‌شوند. TTL کوچک‌تر یا مساوی صفر کلیدها را حذف می‌کند.

---

### ()deleteMultiple

```php
public function deleteMultiple(iterable $keys): bool
```

---

### ()getOrSetSWR

گرفتن یا محاسبهٔ مقدار با stale-while-revalidate، stale-if-error و جلوگیری از cache stampede.

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
- `$producer`: تابعی به شکل `function(): mixed` که مقدار تازه را تولید می‌کند
- `$ttl`: مدت تازه بودن. `null` یعنی TTL پیش‌فرض (`0` = بدون انقضا). مقدار کوچک‌تر یا مساوی صفر یعنی محاسبه **بدون کش کردن**
- `$swrSeconds`: تا چند ثانیه بعد از انقضا مقدار قدیمی سرو شود و همزمان تازه‌سازی انجام شود
- `$staleIfErrorSeconds`: تا چند ثانیه بعد از انقضا، اگر producer خطا داد، مقدار قدیمی سرو شود
- `$options['mode']`: `'sync'` (پیش‌فرض) یا `'defer'`

**استثناها:**
- `InvalidArgument` برای کلید یا mode نامعتبر
- خطای `$producer` وقتی مقدار قدیمی قابل‌استفاده‌ای وجود نداشته باشد

**رفتار:**

| وضعیت رکورد | چه اتفاقی می‌افتد |
|---|---|
| تازه | فوراً برگردانده می‌شود |
| منقضی، داخل `swrSeconds` | فقط یک پروسه رکورد را تازه می‌کند؛ پروسه‌هایی که قفل را نمی‌گیرند بدون انتظار مقدار قدیمی را می‌گیرند. در حالت `sync` پروسهٔ تازه‌کننده مقدار **جدید** را برمی‌گرداند؛ در حالت `defer` مقدار قدیمی برگردانده می‌شود و تازه‌سازی بعد از ارسال پاسخ انجام می‌شود (PHP-FPM/LiteSpeed، در غیر این صورت `sync`). اگر تازه‌سازی شکست بخورد مقدار قدیمی برگردانده می‌شود |
| منقضی، فقط داخل `staleIfErrorSeconds` | دوباره محاسبه می‌شود؛ فقط اگر producer خطا بدهد مقدار قدیمی برگردانده می‌شود |
| وجود ندارد (یا خیلی قدیمی است) | زیر قفل مخصوص همان کلید محاسبه می‌شود (حداکثر انتظار `lockTimeout`). بعد از گرفتن قفل دوباره کش بررسی می‌شود، پس درخواست‌های همزمان producer را فقط یک بار اجرا می‌کنند |

نتیجهٔ `null` هم مثل بقیهٔ مقادیر کش می‌شود.

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

میان‌بر برای `getOrSetSWR($key, $producer, $ttl)` (بدون سرو مقدار قدیمی، با جلوگیری از stampede).

---

### ()forever

```php
public function forever(string $key, mixed $value): bool
```

ذخیرهٔ مقدار بدون انقضا.

---

### ()tags و ()invalidateTags

```php
public function tags(string|array $tags): TaggedCache
public function invalidateTags(string|array $tags): bool
```

`tags()` یک نمای [TaggedCache](#taggedcache) برمی‌گرداند. `invalidateTags()` همهٔ رکوردهایی را که با هر یک از این tagها نوشته شده‌اند غیرقابل‌دسترس می‌کند. این رکوردها حذف نمی‌شوند؛ با TTL خودشان یا `prune()` از بین می‌روند.

```php
$cache->tags(['users', 'team-7'])->set('user-42', $user, 3600);
$cache->invalidateTags('team-7');
```

---

### ()prune

```php
public function prune(): int
```

**خروجی:** تعداد رکوردهای حذف‌شده. `FileStorage` و `PdoStorage` رکوردهای منقضی را حذف می‌کنند (FileStorage فایل‌هایی با قالب ناشناخته و فایل‌های موقت رهاشده را هم پاک می‌کند)؛ APCu و Redis خودشان رکوردها را منقضی می‌کنند.

---

### ()getStats و ()resetStats

```php
public function getStats(): array
public function resetStats(): void
```

شمارنده‌های همین نمونه:

```php
[
    'hits' => 120, 'misses' => 8, 'stale_hits' => 3, 'stale_if_error' => 1,
    'writes' => 10, 'deletes' => 2, 'errors' => 0, 'hit_ratio' => 0.9426,
]
```

---

## TaggedCache

نمای PSR-16 که `MultiTierCache::tags()` برمی‌گرداند. متدهای `get`، `set`، `delete`، `has`، `getMultiple`، `setMultiple`، `deleteMultiple`، `getOrSetSWR`، `remember` و `forever` را دارد. `clear()` روی این نما tagهای آن را باطل می‌کند. رکوردها باید با همان مجموعه tagی خوانده شوند که با آن نوشته شده‌اند (ترتیب مهم نیست). طول کلید حداکثر ۲۱۷ بایت است.

---

## Storage Backends

### StorageInterface

```php
interface StorageInterface
{
    public function get(string $key): ?string;          // null اگر وجود نداشته باشد یا منقضی شده باشد
    public function set(string $key, string $payload, int $ttl): bool; // $ttl = 0 یعنی بدون انقضا
    public function delete(string $key): bool;          // برای کلید ناموجود هم true
    public function has(string $key): bool;
    public function clear(): bool;                      // فقط رکوردهای همین storage
    public function prune(): int;                       // تعداد رکوردهای حذف‌شده
}
```

### BatchStorageInterface

اختیاری؛ همهٔ storageهای داخلی آن را پیاده کرده‌اند:

```php
interface BatchStorageInterface extends StorageInterface
{
    public function getMany(array $keys): array;              // payloadهای پیداشده بر اساس کلید
    public function setMany(array $payloads, int $ttl): bool;
    public function deleteMany(array $keys): bool;
}
```

همهٔ storageهای داخلی `Psr\Log\LoggerAwareInterface` را پیاده می‌کنند و خطاها را به‌جای `error_log()` از طریق PSR-3 گزارش می‌دهند.

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

- `$path`: دایرکتوری کش (اگر نباشد ساخته می‌شود). نباید خالی یا `/` باشد
- `$ext`: پسوند فایل؛ فقط حروف، اعداد و `.` `_` `-`
- `$shards`: تعداد سطح‌های زیردایرکتوری (۰ تا ۳)
- `$fileMode` و `$dirMode`: مجوزهای دقیق (umask اعمال نمی‌شود)

**استثناها:** `\InvalidArgumentException` برای مسیر یا پسوند نامعتبر؛ `\RuntimeException` اگر دایرکتوری ساخته نشود یا قابل نوشتن نباشد

**نکات:**
- نام فایل، هش xxh128 کلید است؛ با ۲ shard ساختار `path/a1/b2/<hash>.cache` است
- هر فایل با `ECF1` و زمان انقضا شروع می‌شود: `get()` فایل منقضی را نادیده می‌گیرد (و حذف می‌کند) و `prune()` آن را پاک می‌کند
- نوشتن اتمیک: فایل موقت انحصاری (`fopen('x')` که هرگز symlink را دنبال نمی‌کند) و سپس `rename()`
- دایرکتوری‌ها فقط هنگام نوشتن ساخته می‌شوند
- `clear()` فقط فایل‌هایی را حذف می‌کند که با الگوی نام همین storage در دایرکتوری‌های shard مطابقت دارند و هرگز symlink را دنبال نمی‌کند

---

### ApcuStorage

```php
public function __construct(string $prefix = 'ec:', int $maxTtl = 0, ?LoggerInterface $logger = null)
public static function isAvailable(): bool
```

- `$prefix`: اجباری و غیرخالی (`clear()` هر کلیدی با این prefix را حذف می‌کند)
- `$maxTtl`: سقف TTL رکوردهای این لایه (۰ = بدون سقف). APCu فقط مخصوص یک سرور است؛ سقف کوتاه باعث می‌شود تغییرات سرورهای دیگر سریع دیده شوند

**استثناها:** `\RuntimeException` اگر APCu نصب یا فعال نباشد (در CLI به `apc.enable_cli=1` نیاز است)؛ `\InvalidArgumentException` برای prefix خالی

---

### RedisStorage

```php
public function __construct(object $redisClient, string $prefix = 'ec:', ?LoggerInterface $logger = null)
```

- `$redisClient`: نمونهٔ `\Redis` (phpredis) یا `\Predis\ClientInterface`
- `$prefix`: اجباری و غیرخالی

**استثنا:** `\InvalidArgumentException` برای کلاینت پشتیبانی‌نشده یا prefix خالی

**نکات:**
- `SETEX` برای TTL، `MGET` برای خواندن دسته‌ای و pipeline برای نوشتن دسته‌ای
- `clear()` از `SCAN` غیرمسدودکننده با prefix escape‌شده استفاده می‌کند (برای Predis هم) و prefix سطح کلاینت (`Redis::OPT_PREFIX` و گزینهٔ `prefix` در Predis) را درست مدیریت می‌کند
- `prune()` صفر برمی‌گرداند (Redis خودش کلیدها را منقضی می‌کند)

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

- `$table`: به شکل `[A-Za-z_][A-Za-z0-9_]*` یا `schema.table`؛ هر چیز دیگری `\InvalidArgumentException` می‌دهد
- تنظیمات اتصال (مثل error mode) تغییر نمی‌کند؛ هم حالت exception و هم حالت silent پشتیبانی می‌شوند
- payload به‌صورت باینری (`PDO::PARAM_LOB`) bind می‌شود
- کلیدهای بلندتر از ۶۴ بایت به شکل `':' . sha256` ذخیره می‌شوند تا در ستون کلید جا شوند
- `ensureTable()` در صورت نیاز جدول را می‌سازد:

| درایور | کلید | Payload | انقضا |
|---|---|---|---|
| SQLite | `TEXT` | `BLOB` | `INTEGER` |
| MySQL/MariaDB | `VARBINARY(64)` (حساس به حروف بزرگ و کوچک) | `LONGBLOB` | `BIGINT` |
| PostgreSQL | `VARCHAR(64)` | `BYTEA` | `BIGINT` |

- `upgradeSchema()` جدول‌های MySQL ساخته‌شده با EasyCache قبل از 3.1 را (`BLOB` و کلید غیرحساس به حروف) به‌روز می‌کند
- `prune()` ردیف‌های منقضی را حذف و تعدادشان را برمی‌گرداند

---

## قفل‌ها (Locks)

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

قفل‌های مبتنی بر `flock()` (فقط در یک ماشین). بدون دایرکتوری، یک دایرکتوری مخصوص کاربر در temp سیستم با مجوز `0700` ساخته می‌شود و فقط وقتی استفاده می‌شود که مالکش کاربر جاری باشد و دیگران نتوانند در آن بنویسند. اگر دایرکتوری قابل استفاده نباشد قفل گرفته نمی‌شود و مقدار بدون قفل محاسبه (و همچنان کش) می‌شود.

### RedisLockProvider

```php
public function __construct(object $redis, string $prefix = 'ec:lock:', int $ttlMs = 30000)
```

قفل توزیع‌شده برای چند سرور (`SET key token NX PX ttl` و آزادسازی با اسکریپت Lua از نوع compare-and-delete). TTL در برابر پروسه‌هایی که crash می‌کنند محافظت می‌کند؛ `acquire()` مسدودکننده حداکثر به اندازهٔ TTL منتظر می‌ماند.

### NullLockProvider

قفل را غیرفعال می‌کند (هر قفلی فوراً گرفته می‌شود).

### Util\Lock

```php
public function __construct(string $path, bool $removeOnRelease = true)
public function acquire(bool $blocking = true, ?float $timeout = null): bool
public function release(): void
public function isAcquired(): bool
```

قفل فایلی که `FileLockProvider` استفاده می‌کند. فایل قفلی را که symlink باشد رد می‌کند، timeout دارد و فایل قفل را هنگام آزادسازی حذف می‌کند (بدون race: بعد از قفل کردن بررسی می‌کند فایل قفل‌شده هنوز همان فایل موجود در مسیر باشد).

---

## Serializers

### SerializerInterface

```php
interface SerializerInterface
{
    public function serialize(mixed $value): string;
    public function deserialize(string $payload): mixed; // برای ورودی نامعتبر باید exception بدهد
    public function name(): string;                      // ۱ تا ۲۵۵ بایت، همراه هر رکورد ذخیره می‌شود
}
```

### NativeSerializer

```php
public function __construct(bool|array $allowedClasses = true, int $maxDepth = 0)
```

- `$allowedClasses`: `true` (هر کلاسی)، `false` (بدون شیء) یا فهرستی از نام کلاس‌ها. بقیهٔ کلاس‌ها به `__PHP_Incomplete_Class` تبدیل می‌شوند
- `$maxDepth`: حداکثر عمق تودرتو بودن (۰ = پیش‌فرض PHP)
- برای payload نامعتبر `\UnexpectedValueException` می‌دهد (مقدار `false` ذخیره‌شده همچنان `false` برگردانده می‌شود)
- نام: `php`

> unserialize کردن داده‌ای که مهاجم کنترلش می‌کند می‌تواند به اجرای کد برسد. `$allowedClasses` را محدود کنید و/یا از کلید امضا استفاده کنید.

### JsonSerializer

```php
public function __construct(int $depth = 512)
```

- هرگز شیء نمی‌سازد: اشیاء به‌صورت آرایهٔ انجمنی برمی‌گردند
- نوع float حفظ می‌شود (`1.0` همان float می‌ماند)
- برای دادهٔ نامعتبر `\JsonException` می‌دهد
- نام: `json`

---

## Compressors

### CompressorInterface

```php
interface CompressorInterface
{
    public function compress(string $data): string;
    public function decompress(string $data): string;
    public function name(): string; // همراه هر رکورد ذخیره می‌شود؛ "none" یعنی فشرده‌نشده
}
```

نام compressor همراه هر رکورد ذخیره می‌شود. رکوردهای فشرده با `gzip` یا `zstd` بعد از تعویض compressor هم قابل خواندن می‌مانند؛ رکوردهایی که با compressor سفارشی نوشته شده‌اند برای خوانده شدن به همان compressor نیاز دارند (وگرنه miss حساب می‌شوند).

### NullCompressor

بدون فشرده‌سازی. نام: `none`.

### GzipCompressor

```php
public function __construct(int $level = 3, int $maxDecompressedSize = 64 * 1024 * 1024)
```

- `$level`: ۰ تا ۹ (خارج از بازه محدود می‌شود)
- `$maxDecompressedSize`: بیش از این تعداد بایت را از حالت فشرده خارج نمی‌کند (۰ = نامحدود)
- به ext-zlib نیاز دارد. نام: `gzip`

### ZstdCompressor

```php
public function __construct(int $level = 3, int $maxDecompressedSize = 64 * 1024 * 1024)
```

- `$level`: ۱ تا ۲۲ (خارج از بازه محدود می‌شود)
- به ext-zstd نیاز دارد. نام: `zstd`

---

## ساعت‌ها (Clocks)

هر دو `Psr\Clock\ClockInterface` را پیاده می‌کنند.

- `SystemClock`: زمان سیستم (پیش‌فرض)
- `FrozenClock`: برای تست: `new FrozenClock()`، `FrozenClock::at($timestamp)`، `->advance(int $seconds)` و `->set(DateTimeImmutable $now)`

یک clock مشترک را به `MultiTierCache` و `FileStorage`/`PdoStorage` بدهید تا انقضا را در تست‌ها بدون `sleep()` کنترل کنید.

---

## ابزارها

### KeyValidator

```php
public static function assert(string $key): void
public static function normalize(mixed $key): string // برای کلیدهای داخل iterable؛ عدد صحیح هم پذیرفته می‌شود
```

**قوانین:**
- ۱ تا ۲۵۵ بایت (`KeyValidator::MAX_LENGTH`)
- فقط ASCII قابل چاپ (`!` تا `~`): بدون فاصله، کاراکتر کنترلی یا بایت غیر ASCII
- بدون کاراکترهای رزروشدهٔ `{}()/\@:` (`KeyValidator::RESERVED_CHARACTERS`)

```php
KeyValidator::assert('user_123');          // معتبر
KeyValidator::assert('user-3f2a-77b2');    // معتبر
KeyValidator::assert('user:123');          // exception
KeyValidator::assert("user\n");            // exception
```

---

## استثناها

### InvalidArgument

```php
final class InvalidArgument extends \InvalidArgumentException
    implements Psr\SimpleCache\InvalidArgumentException
```

برای کلید، tag یا mode نامعتبر SWR پرتاب می‌شود.

---

## یکپارچگی با Laravel

### Container و Facade

```php
app('easycache');                                    // singleton از MultiTierCache
app(\Iprodev\EasyCache\Cache\MultiTierCache::class); // همان نمونه
EasyCache::remember('stats', 300, fn () => $this->computeStats());
```

Facade همهٔ متدهای عمومی `MultiTierCache` را در اختیار می‌گذارد.

### دستورات Artisan

```bash
php artisan easycache:prune   # حذف رکوردهای منقضی
php artisan easycache:clear   # حذف همهٔ رکوردها
```

### پیکربندی

دستور `php artisan vendor:publish --tag=easycache-config` فایل `config/easycache.php` را منتشر می‌کند. گزینه‌های اصلی (همه از طریق متغیرهای محیطی `EASYCACHE_*` هم قابل تنظیم‌اند؛ README را ببینید):

```php
return [
    'drivers' => ['file'],              // apcu، redis، file، pdo (سریع‌ترین اول)
    'skip_unavailable_drivers' => true, // درایوری که راه‌اندازی نشود لاگ و رد می‌شود
    'default_ttl' => 600,
    'sign' => true,                     // امضای رکوردها...
    'signing_key' => null,              // ...با این کلید یا کلیدی مشتق از APP_KEY
    'lock' => ['driver' => 'file', 'path' => storage_path('framework/cache/easycache-locks'), 'timeout' => 30],
    'apcu' => ['prefix' => 'ec:', 'max_ttl' => 60],
    'redis' => ['client' => 'phpredis', 'host' => '127.0.0.1', 'port' => 6379, 'prefix' => 'ec:'],
    'file' => ['path' => storage_path('framework/cache/easycache'), 'ext' => '.cache', 'shards' => 2],
    'pdo' => ['dsn' => '', 'table' => 'easycache', 'auto_create' => true], // '' = SQLite در storage
    'serializer' => ['driver' => 'php', 'allowed_classes' => true],
    'compressor' => ['driver' => 'none', 'level' => 3],
];
```

---

## قالب رکورد

```
"EC03" | flags (۱ بایت، بیت ۰ = امضاشده) | expires (uint64 BE، ۰ = بدون انقضا)
| swr (uint32 BE) | sei (uint32 BE)
| طول نام serializer (۱) + نام | طول نام compressor (۱) + نام
| [HMAC-SHA256 روی هدر + بدنه (۳۲ بایت)، اگر امضا شده باشد]
| body = compress(serialize(value))
```

هدر با `unpack()` خوانده می‌شود؛ فقط بدنه و فقط بعد از تأیید امضا (در صورت وجود) به serializer داده می‌شود. رکوردهای با هر قالب دیگری miss حساب می‌شوند.

---

## مدیریت خطا

خطاهای storage، serialization و فشرده‌سازی لاگ می‌شوند (PSR-3) و در `getStats()['errors']` شمرده می‌شوند؛ در این حالت عملیات به‌جای پرتاب exception مقدار `false`، مقدار پیش‌فرض یا miss برمی‌گرداند.

**استثناهایی که پرتاب می‌شوند:**
- `InvalidArgument`: کلید، tag یا گزینهٔ نامعتبر
- `\InvalidArgumentException` / `\RuntimeException`: خطاهای ساخت شیء (افزونهٔ ناموجود، مسیر نامعتبر، کلید امضای کوتاه و...)
- خطاهای producer شما در `getOrSetSWR()`/`remember()` وقتی مقدار قدیمی قابل سرو وجود نداشته باشد

---

برای مثال‌های بیشتر [EXAMPLES_FA.md](EXAMPLES_FA.md) را ببینید.

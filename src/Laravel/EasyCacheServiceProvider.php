<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Laravel;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Compression\CompressorInterface;
use Iprodev\EasyCache\Compression\GzipCompressor;
use Iprodev\EasyCache\Compression\NullCompressor;
use Iprodev\EasyCache\Compression\ZstdCompressor;
use Iprodev\EasyCache\Laravel\Console\ClearCommand;
use Iprodev\EasyCache\Laravel\Console\PruneCommand;
use Iprodev\EasyCache\Lock\FileLockProvider;
use Iprodev\EasyCache\Lock\LockProviderInterface;
use Iprodev\EasyCache\Lock\NullLockProvider;
use Iprodev\EasyCache\Lock\RedisLockProvider;
use Iprodev\EasyCache\Serialization\JsonSerializer;
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Serialization\SerializerInterface;
use Iprodev\EasyCache\Storage\ApcuStorage;
use Iprodev\EasyCache\Storage\FileStorage;
use Iprodev\EasyCache\Storage\PdoStorage;
use Iprodev\EasyCache\Storage\RedisStorage;
use Iprodev\EasyCache\Storage\StorageInterface;
use InvalidArgumentException;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class EasyCacheServiceProvider extends ServiceProvider
{
    private ?object $redisClient = null;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/easycache.php', 'easycache');

        $this->app->singleton('easycache', function (Application $app): MultiTierCache {
            return $this->createCache($app);
        });
        $this->app->alias('easycache', MultiTierCache::class);

        // Make Cache::store('easycache') work without editing config/cache.php.
        $config = $this->app->make('config');
        if ($config->get('cache.stores.easycache') === null) {
            $config->set('cache.stores.easycache', ['driver' => 'easycache']);
        }

        $this->callAfterResolving('cache', function (CacheManager $manager): void {
            $manager->extend('easycache', function (Application $app, array $config) use ($manager): Repository {
                $prefix = $config['prefix'] ?? $app->make('config')->get('cache.prefix', '');

                return $manager->repository(
                    new EasyCacheStore($app->make('easycache'), is_string($prefix) ? $prefix : ''),
                    $config
                );
            });
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/easycache.php' => $this->app->configPath('easycache.php'),
        ], 'easycache-config');

        if ($this->app->runningInConsole()) {
            $this->commands([PruneCommand::class, ClearCommand::class]);
        }
    }

    private function createCache(Application $app): MultiTierCache
    {
        /** @var array<string, mixed> $cfg */
        $cfg = (array) $app->make('config')->get('easycache', []);
        $logger = $this->logger($app);
        $skipUnavailable = (bool) ($cfg['skip_unavailable_drivers'] ?? true);

        $tiers = [];
        foreach ((array) ($cfg['drivers'] ?? ['file']) as $driver) {
            try {
                $tiers[] = $this->makeTier((string) $driver, $cfg);
            } catch (Throwable $e) {
                if (!$skipUnavailable) {
                    throw $e;
                }
                $logger?->warning('EasyCache: driver "{driver}" is unavailable and was skipped: {error}', [
                    'driver' => $driver,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        if ($tiers === []) {
            $logger?->warning('EasyCache: no configured driver is available, falling back to file storage');
            $tiers[] = $this->makeTier('file', $cfg);
        }

        $lockCfg = (array) ($cfg['lock'] ?? []);

        return new MultiTierCache(
            $tiers,
            serializer: $this->makeSerializer((array) ($cfg['serializer'] ?? [])),
            compressor: $this->makeCompressor((array) ($cfg['compressor'] ?? [])),
            defaultTtl: (int) ($cfg['default_ttl'] ?? 600),
            logger: $logger,
            signingKey: $this->signingKey($cfg, $app),
            lockProvider: $this->makeLockProvider($lockCfg, $cfg, $logger),
            lockTimeout: (float) ($lockCfg['timeout'] ?? 30),
            allowUnsignedObjects: (bool) ($cfg['allow_unsigned_objects'] ?? false),
        );
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function makeTier(string $driver, array $cfg): StorageInterface
    {
        switch ($driver) {
            case 'apcu':
                $apcu = (array) ($cfg['apcu'] ?? []);
                return new ApcuStorage((string) ($apcu['prefix'] ?? 'ec:'), (int) ($apcu['max_ttl'] ?? 60));

            case 'redis':
                $redis = (array) ($cfg['redis'] ?? []);
                return new RedisStorage($this->redis($redis), (string) ($redis['prefix'] ?? 'ec:'));

            case 'file':
                $file = (array) ($cfg['file'] ?? []);
                return new FileStorage(
                    (string) ($file['path'] ?? storage_path('framework/cache/easycache')),
                    (string) ($file['ext'] ?? '.cache'),
                    (int) ($file['shards'] ?? 2),
                    (int) ($file['file_mode'] ?? 0640),
                    (int) ($file['dir_mode'] ?? 0770)
                );

            case 'pdo':
                $pdoCfg = (array) ($cfg['pdo'] ?? []);
                $store = new PdoStorage($this->makePdo($pdoCfg), (string) ($pdoCfg['table'] ?? 'easycache'));
                if (($pdoCfg['auto_create'] ?? true) === true) {
                    $store->ensureTable();
                }
                return $store;

            default:
                throw new InvalidArgumentException("Unknown EasyCache driver '{$driver}'");
        }
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function makeSerializer(array $cfg): SerializerInterface
    {
        if (($cfg['driver'] ?? 'php') === 'json') {
            return new JsonSerializer();
        }

        $allowed = $cfg['allowed_classes'] ?? true;
        /** @var bool|list<class-string> $allowed */
        $allowed = is_array($allowed) ? array_values(array_map('strval', $allowed)) : (bool) $allowed;

        return new NativeSerializer($allowed);
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function makeCompressor(array $cfg): CompressorInterface
    {
        $level = (int) ($cfg['level'] ?? 3);

        return match ($cfg['driver'] ?? 'none') {
            'gzip' => new GzipCompressor($level),
            'zstd' => new ZstdCompressor($level),
            default => new NullCompressor(),
        };
    }

    /**
     * @param array<string, mixed> $lockCfg
     * @param array<string, mixed> $cfg
     */
    private function makeLockProvider(array $lockCfg, array $cfg, ?LoggerInterface $logger): LockProviderInterface
    {
        $driver = (string) ($lockCfg['driver'] ?? 'file');

        if ($driver === 'none') {
            return new NullLockProvider();
        }

        if ($driver === 'redis') {
            try {
                $redis = (array) ($cfg['redis'] ?? []);
                return new RedisLockProvider(
                    $this->redis($redis),
                    (string) ($redis['prefix'] ?? 'ec:') . 'lock:',
                    (int) ($lockCfg['redis_ttl_ms'] ?? 30000)
                );
            } catch (Throwable $e) {
                $logger?->warning('EasyCache: Redis locks unavailable, using file locks: {error}', [
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        $path = $lockCfg['path'] ?? null;

        return new FileLockProvider(is_string($path) && $path !== '' ? $path : null);
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function signingKey(array $cfg, Application $app): ?string
    {
        if (!(bool) ($cfg['sign'] ?? true)) {
            return null;
        }

        $key = $cfg['signing_key'] ?? null;
        if (is_string($key) && $key !== '') {
            return strlen($key) >= 16 ? $key : hash('sha256', $key);
        }

        $appKey = $app->make('config')->get('app.key');
        if (is_string($appKey) && $appKey !== '') {
            // Derive a dedicated key instead of using APP_KEY directly.
            return hash_hmac('sha256', 'iprodev/php-easycache:signing', $appKey);
        }

        return null;
    }

    /**
     * Create (once) the Redis client shared by the storage and the lock provider.
     *
     * @param array<string, mixed> $cfg
     */
    private function redis(array $cfg): object
    {
        if ($this->redisClient !== null) {
            return $this->redisClient;
        }

        $host = (string) ($cfg['host'] ?? '127.0.0.1');
        $port = (int) ($cfg['port'] ?? 6379);
        $password = $cfg['password'] ?? null;
        $database = (int) ($cfg['database'] ?? 0);
        $timeout = (float) ($cfg['timeout'] ?? 1.5);

        if (($cfg['client'] ?? 'phpredis') === 'predis') {
            if (!class_exists('Predis\Client')) {
                throw new RuntimeException('predis/predis is not installed');
            }
            $client = new \Predis\Client([
                'scheme' => (string) ($cfg['scheme'] ?? 'tcp'),
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'password' => is_string($password) && $password !== '' ? $password : null,
                'timeout' => $timeout,
            ]);
            $client->connect();

            return $this->redisClient = $client;
        }

        if (!class_exists(\Redis::class)) {
            throw new RuntimeException('The phpredis extension is not installed');
        }

        $redis = new \Redis();
        $scheme = (string) ($cfg['scheme'] ?? 'tcp');
        $target = $scheme === 'tls' ? 'tls://' . $host : $host;
        if (!$redis->connect($target, $port, $timeout)) {
            throw new RuntimeException("Unable to connect to Redis at {$host}:{$port}");
        }
        if (is_string($password) && $password !== '' && !$redis->auth($password)) {
            throw new RuntimeException('Redis authentication failed');
        }
        if ($database !== 0 && !$redis->select($database)) {
            throw new RuntimeException("Unable to select Redis database {$database}");
        }

        return $this->redisClient = $redis;
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function makePdo(array $cfg): PDO
    {
        $dsn = $cfg['dsn'] ?? '';
        if (!is_string($dsn) || $dsn === '') {
            $file = storage_path('framework/cache/easycache.sqlite');
            if (!is_dir(dirname($file))) {
                @mkdir(dirname($file), 0770, true);
            }
            $dsn = 'sqlite:' . $file;
        }

        $user = $cfg['user'] ?? null;
        $pass = $cfg['password'] ?? null;
        /** @var array<int, mixed> $options */
        $options = (array) ($cfg['options'] ?? [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        return new PDO(
            $dsn,
            is_string($user) && $user !== '' ? $user : null,
            is_string($pass) && $pass !== '' ? $pass : null,
            $options
        );
    }

    private function logger(Application $app): ?LoggerInterface
    {
        if (!$app->bound('log')) {
            return null;
        }

        $logger = $app->make('log');

        return $logger instanceof LoggerInterface ? $logger : null;
    }
}

<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Laravel;

use Illuminate\Support\ServiceProvider;
use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Storage\FileStorage;
use Iprodev\EasyCache\Storage\ApcuStorage;
use Iprodev\EasyCache\Storage\RedisStorage;
use Iprodev\EasyCache\Storage\PdoStorage;
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Serialization\JsonSerializer;
use Iprodev\EasyCache\Compression\NullCompressor;
use Iprodev\EasyCache\Compression\GzipCompressor;
use Iprodev\EasyCache\Compression\ZstdCompressor;
use PDO;
use Redis;
use Predis\Client as PredisClient;

final class EasyCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/easycache.php', 'easycache');

        $this->app->singleton('easycache', function ($app) {
            $cfg = $app['config']->get('easycache', []);

            $tiers = [];
            foreach ($cfg['drivers'] as $driver) {
                switch ($driver) {
                    case 'apcu':
                        $tiers[] = new ApcuStorage($cfg['apcu']['prefix'] ?? 'ec:');
                        break;
                    case 'redis':
                        $tiers[] = $this->makeRedis($cfg['redis'] ?? []);
                        break;
                    case 'file':
                        $path = $cfg['file']['path'] ?? storage_path('framework/cache/easycache');
                        $ext = $cfg['file']['ext'] ?? '.cache';
                        $shards = $cfg['file']['shards'] ?? 2;
                        $tiers[] = new FileStorage($path, $ext, $shards);
                        break;
                    case 'pdo':
                        $pdo = $this->makePdo($cfg['pdo'] ?? []);
                        $pdoStore = new PdoStorage($pdo, $cfg['pdo']['table'] ?? 'easycache');
                        if (($cfg['pdo']['auto_create'] ?? true) === true) {
                            $pdoStore->ensureTable();
                        }
                        $tiers[] = $pdoStore;
                        break;
                }
            }

            $serializer = match ($cfg['serializer']['driver'] ?? 'php') {
                'json' => new JsonSerializer(),
                default => new NativeSerializer(),
            };

            $compressor = match ($cfg['compressor']['driver'] ?? 'none') {
                'gzip' => new GzipCompressor((int)($cfg['compressor']['level'] ?? 3)),
                'zstd' => new ZstdCompressor((int)($cfg['compressor']['level'] ?? 3)),
                default => new NullCompressor(),
            };

            return new MultiTierCache(
                $tiers,
                $serializer,
                $compressor,
                (int)($cfg['default_ttl'] ?? 3600),
                $app['log'] ?? null,
                $cfg['lock_path'] ?? storage_path('framework/cache/easycache-locks')
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/easycache.php' => config_path('easycache.php')
        ], 'easycache-config');
    }

    private function makeRedis(array $cfg)
    {
        $client = $cfg['client'] ?? 'phpredis';
        if ($client === 'predis') {
            $predis = new PredisClient([
                'scheme' => 'tcp',
                'host' => $cfg['host'] ?? '127.0.0.1',
                'port' => $cfg['port'] ?? 6379,
                'database' => $cfg['database'] ?? 0,
                'password' => $cfg['password'] ?? null,
            ]);
            return new RedisStorage($predis, $cfg['prefix'] ?? 'ec:');
        } else {
            $r = new Redis();
            $r->connect($cfg['host'] ?? '127.0.0.1', (int)($cfg['port'] ?? 6379), 1.5);
            if (!empty($cfg['password'])) {
                $r->auth($cfg['password']);
            }
            if (isset($cfg['database'])) {
                $r->select((int)$cfg['database']);
            }
            return new RedisStorage($r, $cfg['prefix'] ?? 'ec:');
        }
    }

    private function makePdo(array $cfg): PDO
    {
        $dsn = $cfg['dsn'] ?? 'sqlite:' . storage_path('framework/cache/easycache.sqlite');
        $user = $cfg['user'] ?? null;
        $pass = $cfg['password'] ?? null;
        $opts = $cfg['options'] ?? [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        return new PDO($dsn, $user, $pass, $opts);
    }
}

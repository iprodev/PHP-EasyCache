<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Support;

use PDO;
use Throwable;

/**
 * Connections to optional integration backends configured through
 * environment variables (see phpunit.xml.dist).
 */
final class Backends
{
    public static function phpRedis(): ?\Redis
    {
        $host = getenv('EASYCACHE_TEST_REDIS_HOST');
        if ($host === false || $host === '' || !class_exists(\Redis::class)) {
            return null;
        }
        try {
            $redis = new \Redis();
            if (!$redis->connect($host, (int) (getenv('EASYCACHE_TEST_REDIS_PORT') ?: 6379), 1.0)) {
                return null;
            }
            $redis->select(15);
            return $redis;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function predis(array $options = []): ?object
    {
        $host = getenv('EASYCACHE_TEST_REDIS_HOST');
        if ($host === false || $host === '' || !class_exists('Predis\Client')) {
            return null;
        }
        try {
            $client = new \Predis\Client([
                'host' => $host,
                'port' => (int) (getenv('EASYCACHE_TEST_REDIS_PORT') ?: 6379),
                'database' => 15,
            ], $options);
            $client->connect();
            return $client;
        } catch (Throwable) {
            return null;
        }
    }

    public static function pdo(string $driver): ?PDO
    {
        $prefix = 'EASYCACHE_TEST_' . strtoupper($driver);
        $dsn = getenv($prefix . '_DSN');
        if ($dsn === false || $dsn === '' || !in_array($driver, PDO::getAvailableDrivers(), true)) {
            return null;
        }
        try {
            return new PDO(
                $dsn,
                getenv($prefix . '_USER') ?: null,
                getenv($prefix . '_PASSWORD') ?: null,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (Throwable) {
            return null;
        }
    }
}

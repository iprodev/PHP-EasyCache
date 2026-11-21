<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Lock;

use Iprodev\EasyCache\Lock\RedisLockProvider;
use Iprodev\EasyCache\Tests\Support\Backends;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
class RedisLockTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function clients(): iterable
    {
        yield 'phpredis' => ['phpredis'];
        yield 'predis' => ['predis'];
    }

    private function client(string $type): object
    {
        $client = $type === 'phpredis' ? Backends::phpRedis() : Backends::predis();
        if ($client === null) {
            $this->markTestSkipped("Set EASYCACHE_TEST_REDIS_HOST to run {$type} lock tests");
        }
        return $client;
    }

    #[DataProvider('clients')]
    public function testLockIsExclusive(string $type): void
    {
        $provider = new RedisLockProvider($this->client($type), 'ec-test-lock:', 5000);
        $key = 'k' . bin2hex(random_bytes(4));

        $a = $provider->create($key);
        $b = $provider->create($key);

        $this->assertTrue($a->acquire(false));
        $this->assertFalse($b->acquire(false));
        $this->assertFalse($b->acquire(true, 0.1));
        $a->release();
        $this->assertTrue($b->acquire(false));
        $b->release();
    }

    #[DataProvider('clients')]
    public function testReleaseNeverDeletesSomebodyElsesLock(string $type): void
    {
        $client = $this->client($type);
        $provider = new RedisLockProvider($client, 'ec-test-lock:', 100);
        $key = 'k' . bin2hex(random_bytes(4));

        $a = $provider->create($key);
        $this->assertTrue($a->acquire(false));
        usleep(200_000); // a's lock expired
        $b = $provider->create($key);
        $this->assertTrue($b->acquire(false));

        $a->release(); // must not remove b's lock
        $this->assertFalse($provider->create($key)->acquire(false));
        $b->release();
    }

    public function testRejectsUnsupportedClient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RedisLockProvider(new \stdClass());
    }
}

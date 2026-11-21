<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Storage;

use Iprodev\EasyCache\Clock\FrozenClock;
use Iprodev\EasyCache\Storage\RedisStorage;
use Iprodev\EasyCache\Storage\StorageInterface;
use Iprodev\EasyCache\Tests\Support\Backends;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
class RedisStorageTest extends StorageContractTestCase
{
    private \Redis $redis;

    protected function usesClock(): bool
    {
        return false;
    }

    protected function createStorage(FrozenClock $clock): StorageInterface
    {
        $redis = Backends::phpRedis();
        if ($redis === null) {
            $this->markTestSkipped('Set EASYCACHE_TEST_REDIS_HOST to run phpredis tests');
        }
        $this->redis = $redis;
        return new RedisStorage($redis, 'ec-test:');
    }

    public function testClearKeepsForeignKeys(): void
    {
        $this->redis->set('foreign:key', 'keep');
        $this->redis->set('ec-test', 'keep'); // equals the prefix without the trailing ':'
        $this->storage->set('mine', 'x', 0);

        $this->assertTrue($this->storage->clear());
        $this->assertNull($this->storage->get('mine'));
        $this->assertSame('keep', $this->redis->get('foreign:key'));
        $this->assertSame('keep', $this->redis->get('ec-test'));
        $this->redis->del('foreign:key', 'ec-test');
    }

    public function testClearEscapesGlobCharactersInPrefix(): void
    {
        $storage = new RedisStorage($this->redis, 'ec*:');
        $storage->set('mine', 'x', 0);
        $this->redis->set('ecANYTHING:other', 'keep');

        $this->assertTrue($storage->clear());
        $this->assertNull($storage->get('mine'));
        $this->assertSame('keep', $this->redis->get('ecANYTHING:other'));
        $this->redis->del('ecANYTHING:other');
    }

    public function testClearWithClientLevelPrefix(): void
    {
        $redis = Backends::phpRedis();
        $this->assertNotNull($redis);
        $redis->setOption(\Redis::OPT_PREFIX, 'app:');
        $storage = new RedisStorage($redis, 'ec:');

        $storage->set('one', '1', 0);
        $this->assertSame('1', $this->redis->get('app:ec:one'));
        $this->assertTrue($storage->clear());
        $this->assertFalse($this->redis->exists('app:ec:one') > 0);
    }

    public function testRejectsEmptyPrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RedisStorage($this->redis, '');
    }
}

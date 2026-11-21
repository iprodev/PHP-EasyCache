<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Storage;

use Iprodev\EasyCache\Clock\FrozenClock;
use Iprodev\EasyCache\Storage\RedisStorage;
use Iprodev\EasyCache\Storage\StorageInterface;
use Iprodev\EasyCache\Tests\Support\Backends;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
class PredisStorageTest extends StorageContractTestCase
{
    protected function usesClock(): bool
    {
        return false;
    }

    protected function createStorage(FrozenClock $clock): StorageInterface
    {
        $client = Backends::predis();
        if ($client === null) {
            $this->markTestSkipped('Set EASYCACHE_TEST_REDIS_HOST to run Predis tests');
        }
        return new RedisStorage($client, 'ec-predis:');
    }

    public function testClearWithClientLevelPrefix(): void
    {
        $client = Backends::predis(['prefix' => 'app:']);
        $raw = Backends::predis();
        $this->assertNotNull($client);
        $this->assertNotNull($raw);

        $storage = new RedisStorage($client, 'ec:');
        $storage->set('one', '1', 0);
        $raw->set('app:other', 'keep');
        $this->assertSame('1', $raw->get('app:ec:one'));

        $this->assertTrue($storage->clear());
        $this->assertSame(0, (int) $raw->exists('app:ec:one'));
        $this->assertSame('keep', $raw->get('app:other'));
        $raw->del('app:other');
    }
}

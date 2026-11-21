<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Storage;

use Iprodev\EasyCache\Clock\FrozenClock;
use Iprodev\EasyCache\Storage\PrunableStorageInterface;
use Iprodev\EasyCache\Storage\StorageInterface;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour every storage backend must have.
 */
abstract class StorageContractTestCase extends TestCase
{
    protected FrozenClock $clock;
    protected StorageInterface $storage;

    abstract protected function createStorage(FrozenClock $clock): StorageInterface;

    /**
     * Whether the storage reads time from the injected clock (file, PDO) as
     * opposed to the server clock (Redis, APCu).
     */
    protected function usesClock(): bool
    {
        return true;
    }

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->storage = $this->createStorage($this->clock);
        $this->storage->clear();
    }

    protected function tearDown(): void
    {
        if (isset($this->storage)) {
            $this->storage->clear();
        }
    }

    public function testSetAndGet(): void
    {
        $this->assertTrue($this->storage->set('key', 'payload', 60));
        $this->assertSame('payload', $this->storage->get('key'));
        $this->assertTrue($this->storage->has('key'));
    }

    public function testMissingKey(): void
    {
        $this->assertNull($this->storage->get('missing'));
        $this->assertFalse($this->storage->has('missing'));
    }

    public function testOverwrite(): void
    {
        $this->storage->set('key', 'one', 60);
        $this->storage->set('key', 'two', 60);
        $this->assertSame('two', $this->storage->get('key'));
    }

    public function testNoExpiry(): void
    {
        $this->assertTrue($this->storage->set('forever', 'payload', 0));
        $this->assertSame('payload', $this->storage->get('forever'));
    }

    public function testExpiry(): void
    {
        if (!$this->usesClock()) {
            $this->storage->set('short', 'payload', 1);
            $this->assertSame('payload', $this->storage->get('short'));
            usleep(2_100_000);
            $this->assertNull($this->storage->get('short'));
            return;
        }

        $this->storage->set('short', 'payload', 10);
        $this->clock->advance(9);
        $this->assertSame('payload', $this->storage->get('short'));
        $this->clock->advance(1);
        $this->assertNull($this->storage->get('short'));
        $this->assertFalse($this->storage->has('short'));
    }

    public function testDeleteReturnsTrueForMissingKey(): void
    {
        $this->assertTrue($this->storage->delete('never-existed'));
    }

    public function testDelete(): void
    {
        $this->storage->set('key', 'payload', 60);
        $this->assertTrue($this->storage->delete('key'));
        $this->assertNull($this->storage->get('key'));
    }

    public function testClear(): void
    {
        $this->storage->set('a', '1', 60);
        $this->storage->set('b', '2', 0);
        $this->assertTrue($this->storage->clear());
        $this->assertNull($this->storage->get('a'));
        $this->assertNull($this->storage->get('b'));
    }

    public function testBinaryPayload(): void
    {
        $payload = "EC03\x00\x01\x02" . random_bytes(256) . "\x00\xff";
        $this->assertTrue($this->storage->set('binary', $payload, 60));
        $this->assertSame($payload, $this->storage->get('binary'));
    }

    public function testLargePayload(): void
    {
        $payload = random_bytes(300 * 1024); // larger than MySQL's 64KB BLOB
        $this->assertTrue($this->storage->set('large', $payload, 60));
        $this->assertSame($payload, $this->storage->get('large'));
    }

    public function testKeysAreCaseSensitive(): void
    {
        $this->storage->set('User', 'upper', 60);
        $this->storage->set('user', 'lower', 60);
        $this->assertSame('upper', $this->storage->get('User'));
        $this->assertSame('lower', $this->storage->get('user'));
    }

    public function testLongKeys(): void
    {
        $a = str_repeat('a', 254) . '1';
        $b = str_repeat('a', 254) . '2';
        $this->storage->set($a, 'first', 60);
        $this->storage->set($b, 'second', 60);
        $this->assertSame('first', $this->storage->get($a));
        $this->assertSame('second', $this->storage->get($b));
    }

    public function testSpecialCharacterKeys(): void
    {
        $key = 'user-3f2a_b.c#d*e?f[g]%h';
        $this->storage->set($key, 'payload', 60);
        $this->assertSame('payload', $this->storage->get($key));
    }

    public function testBatchOperations(): void
    {
        $this->assertTrue($this->storage->setMany(['a' => '1', 'b' => '2', '123' => '3'], 60));
        $found = $this->storage->getMany(['a', 'b', '123', 'missing']);
        ksort($found, SORT_STRING);
        $this->assertSame(['123' => '3', 'a' => '1', 'b' => '2'], $found);
        $this->assertTrue($this->storage->deleteMany(['a', 'missing']));
        $this->assertSame(['b' => '2'], $this->storage->getMany(['a', 'b']));
        $this->assertSame([], $this->storage->getMany([]));
        $this->assertTrue($this->storage->setMany([], 60));
    }

    public function testPruneReturnsCount(): void
    {
        if (!$this->storage instanceof PrunableStorageInterface) {
            $this->markTestSkipped('The backend expires entries on its own');
        }
        $this->storage->set('keep', 'payload', 0);
        $this->assertGreaterThanOrEqual(0, $this->storage->prune());
        $this->assertSame('payload', $this->storage->get('keep'));
    }
}

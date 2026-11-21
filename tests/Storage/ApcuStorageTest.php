<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Storage;

use Iprodev\EasyCache\Clock\FrozenClock;
use Iprodev\EasyCache\Storage\ApcuStorage;
use Iprodev\EasyCache\Storage\StorageInterface;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
class ApcuStorageTest extends StorageContractTestCase
{
    protected function usesClock(): bool
    {
        return false;
    }

    protected function createStorage(FrozenClock $clock): StorageInterface
    {
        if (!ApcuStorage::isAvailable()) {
            $this->markTestSkipped('APCu is not available (enable apc.enable_cli to run these tests)');
        }
        return new ApcuStorage('ec-test:');
    }

    public function testExpiry(): void
    {
        // APCu only expires entries on a later request, never within the same
        // CLI request, so the TTL cannot be observed here.
        $this->storage->set('short', 'payload', 1);
        $this->assertSame('payload', $this->storage->get('short'));
    }

    public function testClearKeepsForeignKeys(): void
    {
        apcu_store('foreign:key', 'keep');
        $this->storage->set('mine', 'x', 0);

        $this->assertTrue($this->storage->clear());
        $this->assertNull($this->storage->get('mine'));
        $this->assertSame('keep', apcu_fetch('foreign:key'));
        apcu_delete('foreign:key');
    }

    public function testRejectsEmptyPrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ApcuStorage('');
    }
}

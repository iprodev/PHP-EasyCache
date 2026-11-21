<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Storage;

use Iprodev\EasyCache\Clock\FrozenClock;
use Iprodev\EasyCache\Storage\PdoStorage;
use Iprodev\EasyCache\Storage\StorageInterface;
use Iprodev\EasyCache\Tests\Support\Backends;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
class PgsqlStorageTest extends StorageContractTestCase
{
    protected function createStorage(FrozenClock $clock): StorageInterface
    {
        $pdo = Backends::pdo('pgsql');
        if ($pdo === null) {
            $this->markTestSkipped('Set EASYCACHE_TEST_PGSQL_DSN to run PostgreSQL tests');
        }
        $pdo->exec('DROP TABLE IF EXISTS easycache_test');
        $storage = new PdoStorage($pdo, 'easycache_test', $clock);
        $storage->ensureTable();
        $storage->ensureTable();
        return $storage;
    }
}

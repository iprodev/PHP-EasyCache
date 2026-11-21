<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Storage;

use Iprodev\EasyCache\Clock\FrozenClock;
use Iprodev\EasyCache\Storage\PdoStorage;
use Iprodev\EasyCache\Storage\StorageInterface;
use Iprodev\EasyCache\Tests\Support\Backends;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
class MysqlStorageTest extends StorageContractTestCase
{
    protected function createStorage(FrozenClock $clock): StorageInterface
    {
        $pdo = Backends::pdo('mysql');
        if ($pdo === null) {
            $this->markTestSkipped('Set EASYCACHE_TEST_MYSQL_DSN to run MySQL tests');
        }
        $pdo->exec('DROP TABLE IF EXISTS easycache_test');
        $storage = new PdoStorage($pdo, 'easycache_test', $clock);
        $storage->ensureTable();
        $storage->ensureTable();
        return $storage;
    }

    public function testUpgradeSchemaFromLegacyTable(): void
    {
        $pdo = Backends::pdo('mysql');
        $this->assertNotNull($pdo);
        $pdo->exec('DROP TABLE IF EXISTS easycache_legacy');
        $pdo->exec(
            'CREATE TABLE easycache_legacy (k VARCHAR(64) PRIMARY KEY, payload BLOB NOT NULL, expires_at BIGINT NOT NULL)'
        );

        $storage = new PdoStorage($pdo, 'easycache_legacy', $this->clock);
        $storage->upgradeSchema();

        $payload = random_bytes(200 * 1024);
        $this->assertTrue($storage->set('Key', $payload, 60));
        $this->assertTrue($storage->set('key', 'lower', 60));
        $this->assertSame($payload, $storage->get('Key'));
        $this->assertSame('lower', $storage->get('key'));

        $pdo->exec('DROP TABLE easycache_legacy');
    }
}

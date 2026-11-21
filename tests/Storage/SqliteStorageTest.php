<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Storage;

use Iprodev\EasyCache\Clock\FrozenClock;
use Iprodev\EasyCache\Storage\PdoStorage;
use Iprodev\EasyCache\Storage\StorageInterface;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

class SqliteStorageTest extends StorageContractTestCase
{
    private PDO $pdo;

    protected function createStorage(FrozenClock $clock): StorageInterface
    {
        $this->pdo = new PDO('sqlite::memory:');
        $storage = new PdoStorage($this->pdo, 'easycache', $clock);
        $storage->ensureTable();
        $storage->ensureTable(); // idempotent
        return $storage;
    }

    public function testPruneDeletesExpiredRows(): void
    {
        $this->storage->set('a', '1', 10);
        $this->storage->set('b', '2', 100);
        $this->storage->set('c', '3', 0);
        $this->clock->advance(50);

        $this->assertSame(1, $this->storage->prune());
        $this->assertSame('2', $this->storage->get('b'));
        $this->assertSame('3', $this->storage->get('c'));
    }

    public function testDoesNotChangeTheConnectionErrorMode(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $storage = new PdoStorage($pdo);
        $storage->ensureTable();

        $this->assertSame(PDO::ERRMODE_SILENT, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        $this->assertTrue($storage->set('k', 'v', 60));
        $this->assertSame('v', $storage->get('k'));
    }

    public function testErrorsAreReportedInSilentMode(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $storage = new PdoStorage($pdo, 'missing_table');

        $this->assertFalse($storage->set('k', 'v', 60));
        $this->assertNull($storage->get('k'));
        $this->assertFalse($storage->delete('k'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTableNames(): iterable
    {
        yield 'injection' => ['easycache; DROP TABLE users; --'];
        yield 'quote' => ["cache'"];
        yield 'space' => ['my cache'];
        yield 'empty' => [''];
        yield 'leading digit' => ['1cache'];
    }

    #[DataProvider('invalidTableNames')]
    public function testRejectsInvalidTableNames(string $table): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdoStorage(new PDO('sqlite::memory:'), $table);
    }

    public function testAcceptsSchemaQualifiedTable(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $storage = new PdoStorage($pdo, 'main.cache_items');
        $storage->ensureTable();
        $this->assertTrue($storage->set('k', 'v', 0));
        $this->assertSame('v', $storage->get('k'));
    }
}

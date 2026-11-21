<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Storage;

use Iprodev\EasyCache\Clock\FrozenClock;
use Iprodev\EasyCache\Storage\FileStorage;
use Iprodev\EasyCache\Storage\StorageInterface;
use Iprodev\EasyCache\Tests\Support\TempDirectory;

class FileStorageTest extends StorageContractTestCase
{
    use TempDirectory;

    private string $dir;

    protected function createStorage(FrozenClock $clock): StorageInterface
    {
        $this->dir = $this->makeTempDir();
        return new FileStorage($this->dir, '.cache', 2, 0640, 0770, $clock);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeTempDirs();
    }

    /**
     * @return list<string>
     */
    private function listFiles(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            $files[] = substr((string) $file, strlen($dir) + 1);
        }
        sort($files);
        return $files;
    }

    public function testFilePermissionsIgnoreUmask(): void
    {
        $old = umask(0);
        try {
            $this->storage->set('perm', 'secret', 60);
        } finally {
            umask($old);
        }

        $files = array_values(array_filter($this->listFiles($this->dir), fn ($f) => str_ends_with($f, '.cache')));
        $this->assertCount(1, $files);
        $this->assertSame('0640', substr(sprintf('%o', fileperms($this->dir . '/' . $files[0])), -4));
    }

    public function testReadsDoNotCreateDirectories(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->storage->get("missing{$i}");
            $this->storage->has("missing{$i}");
            $this->storage->delete("missing{$i}");
        }
        $this->assertSame([], $this->listFiles($this->dir));
    }

    public function testShardedLayout(): void
    {
        $this->storage->set('sharded', 'data', 60);
        $files = array_values(array_filter($this->listFiles($this->dir), fn ($f) => str_ends_with($f, '.cache')));
        $this->assertMatchesRegularExpression('#^[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{32}\.cache$#', $files[0]);
    }

    public function testClearOnlyRemovesCacheFiles(): void
    {
        file_put_contents($this->dir . '/important.txt', 'keep me');
        file_put_contents($this->dir . '/notes.cache', 'keep me too');
        mkdir($this->dir . '/project');
        file_put_contents($this->dir . '/project/' . str_repeat('a', 32) . '.cache', 'not in a shard dir');

        $outside = $this->makeTempDir('easycache-outside');
        mkdir($outside);
        $victim = $outside . '/' . str_repeat('b', 32) . '.cache';
        file_put_contents($victim, 'outside');
        symlink($outside, $this->dir . '/ab');

        $this->storage->set('k1', 'v1', 60);
        $this->assertTrue($this->storage->clear());

        $this->assertNull($this->storage->get('k1'));
        $this->assertFileExists($this->dir . '/important.txt');
        $this->assertFileExists($this->dir . '/notes.cache');
        $this->assertFileExists($this->dir . '/project/' . str_repeat('a', 32) . '.cache');
        $this->assertFileExists($victim, 'clear() must not follow symlinks');
    }

    public function testPruneRemovesExpiredAndForeignEntries(): void
    {
        $this->storage->set('expired', 'x', 10);
        $this->storage->set('fresh', 'y', 1000);
        $this->storage->set('forever', 'z', 0);

        // A file in the pre-3.1 format (no header) and an orphaned temp file.
        $old = $this->dir . '/' . str_repeat('c', 32) . '.cache';
        file_put_contents($old, 'EC02 old format');
        $tmp = $this->dir . '/' . str_repeat('d', 32) . '.cache.tmp.' . str_repeat('e', 12);
        file_put_contents($tmp, 'partial');
        touch($tmp, time() - 7200);

        $this->clock->advance(20);

        $this->assertSame(2, $this->storage->prune());
        $this->assertNull($this->storage->get('expired'));
        $this->assertSame('y', $this->storage->get('fresh'));
        $this->assertSame('z', $this->storage->get('forever'));
        $this->assertFileDoesNotExist($old);
        $this->assertFileDoesNotExist($tmp);
    }

    public function testGetDeletesExpiredFile(): void
    {
        $this->storage->set('gone', 'x', 5);
        $this->clock->advance(6);
        $this->assertNull($this->storage->get('gone'));
        $this->assertSame([], array_filter($this->listFiles($this->dir), fn ($f) => str_ends_with($f, '.cache')));
    }

    public function testInvalidDirectory(): void
    {
        // A path below a regular file can never be created, not even by root.
        $file = $this->makeTempDir('easycache-file');
        file_put_contents($file, 'x');

        $this->expectException(\RuntimeException::class);
        new FileStorage($file . '/cache', '.cache', 0);
    }

    public function testRejectsInvalidExtension(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FileStorage($this->makeTempDir(), '/../evil');
    }

    public function testRejectsRootPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FileStorage('/');
    }
}

<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Lock;

use Iprodev\EasyCache\Lock\FileLockProvider;
use Iprodev\EasyCache\Lock\UnavailableLock;
use Iprodev\EasyCache\Tests\Support\TempDirectory;
use Iprodev\EasyCache\Util\Lock;
use PHPUnit\Framework\TestCase;

class FileLockTest extends TestCase
{
    use TempDirectory;

    private string $lockDir;

    protected function setUp(): void
    {
        $this->lockDir = $this->makeTempDir('easycache-lock-test');
        mkdir($this->lockDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    public function testAcquireAndReleaseRemovesTheLockFile(): void
    {
        $path = $this->lockDir . '/test.lock';
        $lock = new Lock($path);

        $this->assertTrue($lock->acquire());
        $this->assertFileExists($path);
        $this->assertTrue($lock->isAcquired());

        $lock->release();
        $this->assertFileDoesNotExist($path);
        $this->assertFalse($lock->isAcquired());
        $lock->release(); // idempotent
    }

    public function testNonBlockingLock(): void
    {
        $path = $this->lockDir . '/nonblocking.lock';
        $lock1 = new Lock($path);
        $lock2 = new Lock($path);

        $this->assertTrue($lock1->acquire(false));
        $this->assertFalse($lock2->acquire(false));

        $lock1->release();
        $this->assertTrue($lock2->acquire(false));
        $lock2->release();
    }

    public function testBlockingAcquireTimesOut(): void
    {
        $path = $this->lockDir . '/timeout.lock';
        $holder = new Lock($path);
        $this->assertTrue($holder->acquire());

        $start = microtime(true);
        $this->assertFalse((new Lock($path))->acquire(true, 0.2));
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(0.19, $elapsed);
        $this->assertLessThan(2.0, $elapsed);
        $holder->release();
    }

    public function testReacquireAfterHolderRemovedTheFile(): void
    {
        $path = $this->lockDir . '/inode.lock';
        $first = new Lock($path);
        $this->assertTrue($first->acquire());
        $first->release(); // removes the file

        $second = new Lock($path);
        $third = new Lock($path);
        $this->assertTrue($second->acquire(false));
        $this->assertFalse($third->acquire(false), 'the lock must still be exclusive after file removal');
        $second->release();
    }

    public function testAutomaticReleaseOnDestruct(): void
    {
        $path = $this->lockDir . '/destruct.lock';
        $lock1 = new Lock($path);
        $this->assertTrue($lock1->acquire());
        unset($lock1);

        $lock2 = new Lock($path);
        $this->assertTrue($lock2->acquire(false));
        $lock2->release();
    }

    public function testRepeatedAcquireAndRelease(): void
    {
        $lock = new Lock($this->lockDir . '/repeated.lock');
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($lock->acquire());
            $this->assertTrue($lock->acquire(), 'acquiring a held lock again is a no-op');
            $lock->release();
        }
    }

    public function testRefusesSymlinkedLockFile(): void
    {
        $target = $this->lockDir . '/target';
        $link = $this->lockDir . '/link.lock';
        symlink($target, $link);

        $this->assertFalse((new Lock($link))->acquire(false));
        $this->assertFileDoesNotExist($target);
    }

    public function testProviderCreatesDirectoryLazily(): void
    {
        $dir = $this->makeTempDir('easycache-provider');
        $provider = new FileLockProvider($dir);
        $this->assertDirectoryDoesNotExist($dir);

        $lock = $provider->create('some-key');
        $this->assertTrue($lock->acquire(false));
        $this->assertDirectoryExists($dir);
        $lock->release();

        $this->assertTrue($provider->create('other')->acquire(false));
    }

    public function testDefaultDirectoryIsPrivate(): void
    {
        $provider = new FileLockProvider();
        $lock = $provider->create('private-check-' . bin2hex(random_bytes(4)));
        $this->assertTrue($lock->acquire(false));
        $lock->release();

        $perms = fileperms($provider->getDirectory()) & 0777;
        $this->assertSame(0, $perms & 0077, 'default lock directory must not be accessible by others');
    }

    public function testUnusableDirectoryYieldsUnavailableLock(): void
    {
        $file = $this->makeTempDir('easycache-notadir');
        file_put_contents($file, 'x');

        $lock = (new FileLockProvider($file . '/locks'))->create('k');
        $this->assertInstanceOf(UnavailableLock::class, $lock);
        $this->assertFalse($lock->acquire());
    }
}

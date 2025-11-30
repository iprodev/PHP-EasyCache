<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Util;

use Iprodev\EasyCache\Util\Lock;
use PHPUnit\Framework\TestCase;

class LockTest extends TestCase
{
    private string $lockDir;

    protected function setUp(): void
    {
        $this->lockDir = sys_get_temp_dir() . '/easycache-lock-test-' . bin2hex(random_bytes(8));
        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir, 0770, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->lockDir)) {
            $files = glob($this->lockDir . '/*.lock');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->lockDir);
        }
    }

    public function testAcquireAndRelease(): void
    {
        $lockPath = $this->lockDir . '/test.lock';
        $lock = new Lock($lockPath);

        $this->assertTrue($lock->acquire(true));
        $lock->release();

        // Lock file should exist
        $this->assertFileExists($lockPath);
    }

    public function testBlockingLock(): void
    {
        $lockPath = $this->lockDir . '/blocking.lock';

        $lock1 = new Lock($lockPath);
        $this->assertTrue($lock1->acquire(true));

        // Second lock should wait (we can't test actual blocking easily)
        // but we can test that it succeeds after first is released
        $lock1->release();

        $lock2 = new Lock($lockPath);
        $this->assertTrue($lock2->acquire(true));
        $lock2->release();
    }

    public function testNonBlockingLock(): void
    {
        $lockPath = $this->lockDir . '/nonblocking.lock';

        $lock1 = new Lock($lockPath);
        $this->assertTrue($lock1->acquire(true));

        // Second lock should fail immediately with non-blocking
        $lock2 = new Lock($lockPath);
        $this->assertFalse($lock2->acquire(false));

        $lock1->release();

        // Now it should succeed
        $this->assertTrue($lock2->acquire(false));
        $lock2->release();
    }

    public function testAutomaticReleaseOnDestruct(): void
    {
        $lockPath = $this->lockDir . '/destruct.lock';

        $lock1 = new Lock($lockPath);
        $this->assertTrue($lock1->acquire(true));

        // Destroy first lock (should release)
        unset($lock1);

        // Second lock should succeed
        $lock2 = new Lock($lockPath);
        $this->assertTrue($lock2->acquire(false));
        $lock2->release();
    }

    public function testMultipleLockFiles(): void
    {
        $lock1 = new Lock($this->lockDir . '/lock1.lock');
        $lock2 = new Lock($this->lockDir . '/lock2.lock');
        $lock3 = new Lock($this->lockDir . '/lock3.lock');

        $this->assertTrue($lock1->acquire(false));
        $this->assertTrue($lock2->acquire(false));
        $this->assertTrue($lock3->acquire(false));

        $lock1->release();
        $lock2->release();
        $lock3->release();
    }

    public function testRepeatedAcquireAndRelease(): void
    {
        $lockPath = $this->lockDir . '/repeated.lock';
        $lock = new Lock($lockPath);

        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($lock->acquire(true));
            $lock->release();
        }
    }
}

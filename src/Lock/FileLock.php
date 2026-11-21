<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Lock;

/**
 * Exclusive lock based on flock().
 *
 * - Supports non-blocking acquisition and a timeout for blocking acquisition.
 * - Refuses to open a lock path that is a symlink.
 * - Removes the lock file on release (while still holding the lock) and, on
 *   acquisition, verifies that the locked file is still the one at the path,
 *   so lock files do not pile up and removing them is race free.
 */
final class FileLock implements LockInterface
{
    private const int MAX_RETRIES = 100;

    private string $path;
    private bool $removeOnRelease;
    /** @var resource|null */
    private $handle = null;

    public function __construct(string $path, bool $removeOnRelease = true)
    {
        $this->path = $path;
        $this->removeOnRelease = $removeOnRelease;
    }

    public function acquire(bool $blocking = true, ?float $timeout = null): bool
    {
        if ($this->handle !== null) {
            return true;
        }

        $deadline = ($blocking && $timeout !== null) ? microtime(true) + max(0.0, $timeout) : null;
        $sleep = 2000;
        $staleHandles = 0;

        while (true) {
            if (is_link($this->path)) {
                return false;
            }

            $handle = @fopen($this->path, 'c');
            if ($handle === false) {
                return false;
            }

            $operation = ($blocking && $deadline === null) ? LOCK_EX : (LOCK_EX | LOCK_NB);
            if (@flock($handle, $operation)) {
                if ($this->isCurrentFile($handle)) {
                    $this->handle = $handle;
                    return true;
                }
                // The file was removed by the previous holder after we opened
                // it: we locked an orphaned inode. Try again with a new file.
                @flock($handle, LOCK_UN);
                @fclose($handle);
                if (++$staleHandles >= self::MAX_RETRIES) {
                    return false;
                }
                continue;
            }

            @fclose($handle);

            if (!$blocking) {
                return false;
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                return false;
            }

            usleep($sleep);
            $sleep = min($sleep * 2, 100000);
        }
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        if ($this->removeOnRelease) {
            // Unlink while we still hold the lock; waiters notice the inode
            // change in isCurrentFile() and retry.
            @unlink($this->path);
        }

        @flock($this->handle, LOCK_UN);
        @fclose($this->handle);
        $this->handle = null;
    }

    public function isAcquired(): bool
    {
        return $this->handle !== null;
    }

    public function __destruct()
    {
        $this->release();
    }

    /**
     * @param resource $handle
     */
    private function isCurrentFile($handle): bool
    {
        $locked = @fstat($handle);
        clearstatcache(true, $this->path);
        $current = @stat($this->path);

        if ($locked === false || $current === false) {
            return false;
        }

        return $locked['ino'] === $current['ino'] && $locked['dev'] === $current['dev'];
    }
}

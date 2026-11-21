<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Lock;

use Iprodev\EasyCache\Support\Environment;
use Iprodev\EasyCache\Util\Lock;

/**
 * Creates flock() based locks in a directory.
 *
 * Locks only coordinate processes on the same machine. Use RedisLockProvider
 * when several servers share a cache tier.
 *
 * Without an explicit directory, a per-user directory inside the system temp
 * dir is used. It is created with mode 0700 and is only used if it is owned by
 * the current user and not writable by others, which prevents other local
 * users from holding locks or planting symlinks.
 */
final class FileLockProvider implements LockProviderInterface
{
    private string $directory;
    private bool $strict;
    private int $mode;
    private ?bool $usable = null;

    public function __construct(?string $directory = null, int $mode = 0770)
    {
        $this->strict = $directory === null || $directory === '';
        $this->directory = $this->strict
            ? self::defaultDirectory()
            : rtrim((string) $directory, '/\\');
        $this->mode = $this->strict ? 0700 : $mode;
    }

    public static function defaultDirectory(): string
    {
        return Environment::tempDirectory('easycache-locks');
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    public function create(string $key): LockInterface
    {
        if (!$this->isUsable()) {
            return new UnavailableLock();
        }

        return new Lock($this->directory . '/' . hash('xxh128', $key) . '.lock');
    }

    private function isUsable(): bool
    {
        if ($this->usable !== null) {
            return $this->usable;
        }

        if (!Environment::ensureDirectory($this->directory, $this->mode)) {
            return $this->usable = false;
        }

        if ($this->strict) {
            return $this->usable = Environment::isPrivateDirectory($this->directory);
        }

        return $this->usable = !is_link($this->directory) && is_writable($this->directory);
    }
}

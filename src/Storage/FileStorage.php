<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Storage;

use InvalidArgumentException;
use Iprodev\EasyCache\Clock\SystemClock;
use Iprodev\EasyCache\Support\Environment;
use Iprodev\EasyCache\Support\LogsErrors;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Stores each entry in its own file, written atomically (temp file + rename).
 *
 * File layout: "ECF1" + 8 byte big-endian expiry timestamp (0 = never) + payload.
 * The expiry header lets get() ignore and prune() delete expired files.
 */
final class FileStorage implements PrunableStorageInterface, LoggerAwareInterface
{
    use LogsErrors;

    private const string MAGIC = 'ECF1';
    private const int HEADER_LENGTH = 12;
    /** Temp files older than this are considered orphaned by prune(). */
    private const int ORPHAN_TMP_AGE = 3600;

    private string $path;
    private string $ext;
    private int $shards;
    private int $fileMode;
    private int $dirMode;
    private ClockInterface $clock;

    /**
     * @param string $path     Cache directory (created if missing)
     * @param string $ext      File extension, e.g. ".cache"
     * @param int    $shards   Number of 2-character sub-directory levels (0-3)
     * @param int    $fileMode Permissions of cache files (not affected by umask)
     * @param int    $dirMode  Permissions of created directories (not affected by umask)
     */
    public function __construct(
        string $path,
        string $ext = '.cache',
        int $shards = 2,
        int $fileMode = 0640,
        int $dirMode = 0770,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null
    ) {
        $path = rtrim($path, '/\\');
        if ($path === '') {
            throw new InvalidArgumentException('Cache path must not be empty or the filesystem root');
        }
        if (preg_match('/^[A-Za-z0-9._-]{0,32}$/', $ext) !== 1) {
            throw new InvalidArgumentException(
                "Invalid cache file extension '{$ext}': only letters, digits, '.', '_' and '-' are allowed"
            );
        }

        $this->path = $path;
        $this->ext = $ext;
        $this->shards = max(0, min(3, $shards));
        $this->fileMode = $fileMode & 0777;
        $this->dirMode = $dirMode & 0777;
        $this->clock = $clock ?? new SystemClock();
        $this->logger = $logger;

        if (!Environment::ensureDirectory($this->path, $this->dirMode)) {
            throw new RuntimeException("Unable to create cache directory: {$this->path}");
        }
        if (!is_writable($this->path)) {
            throw new RuntimeException("Cache directory is not writable: {$this->path}");
        }
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function get(string $key): ?string
    {
        $file = $this->fileFor($key);
        $raw = @file_get_contents($file);
        if ($raw === false || strlen($raw) < self::HEADER_LENGTH) {
            return null;
        }

        $expires = $this->parseHeader(substr($raw, 0, self::HEADER_LENGTH));
        if ($expires === null) {
            return null;
        }
        if ($this->isExpired($expires)) {
            @unlink($file);
            return null;
        }

        return substr($raw, self::HEADER_LENGTH);
    }

    public function set(string $key, string $payload, int $ttl): bool
    {
        $file = $this->fileFor($key);
        $dir = dirname($file);

        if (!Environment::ensureDirectory($dir, $this->dirMode)) {
            $this->logWarning('EasyCache: failed to create cache directory {dir}', ['dir' => $dir]);
            return false;
        }

        $expires = $ttl > 0 ? $this->now() + $ttl : 0;
        $data = self::MAGIC . pack('J', $expires) . $payload;
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));

        // 'x' fails if the path exists, so a planted symlink is never followed.
        $handle = @fopen($tmp, 'xb');
        if ($handle === false) {
            $this->logWarning('EasyCache: failed to create temporary file {file}', ['file' => $tmp]);
            return false;
        }

        $written = @fwrite($handle, $data);
        $closed = @fclose($handle);

        if ($written !== strlen($data) || !$closed) {
            @unlink($tmp);
            $this->logWarning('EasyCache: failed to write cache file {file}', ['file' => $tmp]);
            return false;
        }

        @chmod($tmp, $this->fileMode);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            $this->logWarning('EasyCache: failed to move {tmp} to {file}', ['tmp' => $tmp, 'file' => $file]);
            return false;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $file = $this->fileFor($key);

        if (@unlink($file) || !file_exists($file)) {
            return true;
        }

        $this->logWarning('EasyCache: failed to delete cache file {file}', ['file' => $file]);
        return false;
    }

    public function has(string $key): bool
    {
        $handle = @fopen($this->fileFor($key), 'rb');
        if ($handle === false) {
            return false;
        }
        $header = (string) fread($handle, self::HEADER_LENGTH);
        fclose($handle);

        $expires = $this->parseHeader($header);

        return $expires !== null && !$this->isExpired($expires);
    }

    public function getMany(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $value = $this->get($key);
            if ($value !== null) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    public function setMany(array $payloads, int $ttl): bool
    {
        $ok = true;
        foreach ($payloads as $key => $payload) {
            $ok = $this->set((string) $key, $payload, $ttl) && $ok;
        }
        return $ok;
    }

    public function deleteMany(array $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->delete($key) && $ok;
        }
        return $ok;
    }

    /**
     * Delete every cache file (and temp file) created by this storage.
     *
     * Only files whose names match the storage's naming scheme are removed,
     * only shard directories are descended into, and symlinks are never
     * followed, so a misconfigured path cannot wipe unrelated files.
     */
    public function clear(): bool
    {
        $ok = true;
        $this->walk($this->path, 0, function (string $file, bool $isTemp) use (&$ok): void {
            if (!@unlink($file) && file_exists($file)) {
                $this->logWarning('EasyCache: failed to delete cache file {file}', ['file' => $file]);
                $ok = false;
            }
        });

        return $ok;
    }

    /**
     * Delete expired cache files, files in an unknown/old format and orphaned
     * temporary files. Returns the number of deleted cache entries.
     */
    public function prune(): int
    {
        $now = $this->now();
        $count = 0;

        $this->walk($this->path, 0, function (string $file, bool $isTemp) use ($now, &$count): void {
            if ($isTemp) {
                $mtime = @filemtime($file);
                if ($mtime !== false && $mtime < $now - self::ORPHAN_TMP_AGE) {
                    @unlink($file);
                }
                return;
            }

            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                return;
            }
            $header = (string) fread($handle, self::HEADER_LENGTH);
            fclose($handle);

            $expires = $this->parseHeader($header);
            if (($expires === null || ($expires > 0 && $expires <= $now)) && @unlink($file)) {
                $count++;
            }
        });

        return $count;
    }

    private function fileFor(string $key): string
    {
        $hash = hash('xxh128', $key);
        $dir = $this->path;

        for ($i = 0; $i < $this->shards; $i++) {
            $dir .= '/' . substr($hash, $i * 2, 2);
        }

        return $dir . '/' . $hash . $this->ext;
    }

    /**
     * @param callable(string, bool): void $callback Receives the file path and whether it is a temp file
     */
    private function walk(string $dir, int $depth, callable $callback): void
    {
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }

        $ext = preg_quote($this->ext, '/');
        $dataPattern = '/^[0-9a-f]{32}' . $ext . '$/';
        $tmpPattern = '/^[0-9a-f]{32}' . $ext . '\.tmp\.[0-9a-f]{12}$/';

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_link($path)) {
                continue;
            }

            if (is_dir($path)) {
                if ($depth < 3 && preg_match('/^[0-9a-f]{2}$/', $item) === 1) {
                    $this->walk($path, $depth + 1, $callback);
                    @rmdir($path); // only succeeds when empty
                }
                continue;
            }

            if (preg_match($dataPattern, $item) === 1) {
                $callback($path, false);
            } elseif (preg_match($tmpPattern, $item) === 1) {
                $callback($path, true);
            }
        }
    }

    private function parseHeader(string $header): ?int
    {
        if (strlen($header) < self::HEADER_LENGTH || strncmp($header, self::MAGIC, 4) !== 0) {
            return null;
        }

        /** @var array{1: int} $parts */
        $parts = unpack('J', $header, 4);

        return $parts[1];
    }

    private function isExpired(int $expires): bool
    {
        return $expires > 0 && $expires <= $this->now();
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}

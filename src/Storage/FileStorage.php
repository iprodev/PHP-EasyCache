<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Storage;

final class FileStorage implements StorageInterface
{
    private string $path;
    private string $ext;
    private int $shards;

    public function __construct(string $path, string $ext = '.cache', int $shards = 2)
    {
        $this->path = rtrim($path, '/');
        $this->ext = $ext;
        $this->shards = max(0, min(3, $shards));

        if (!is_dir($this->path)) {
            if (!mkdir($this->path, 0770, true) && !is_dir($this->path)) {
                throw new \RuntimeException("Unable to create cache directory: {$this->path}");
            }
        }

        if (!is_writable($this->path)) {
            throw new \RuntimeException("Cache directory is not writable: {$this->path}");
        }
    }

    private function fileFor(string $key): string
    {
        $hash = md5($key);
        $dir = $this->path;

        // Create sharded subdirectories
        if ($this->shards > 0) {
            for ($i = 0; $i < $this->shards; $i++) {
                $dir .= '/' . substr($hash, $i * 2, 2);
            }
        }

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0770, true) && !is_dir($dir)) {
                error_log("Failed to create cache subdirectory: {$dir}");
            }
        }

        return $dir . '/' . $hash . $this->ext;
    }

    public function get(string $key): ?string
    {
        $file = $this->fileFor($key);

        if (!is_file($file)) {
            return null;
        }

        $fp = fopen($file, 'rb');
        if (!$fp) {
            error_log("Failed to open cache file for reading: {$file}");
            return null;
        }

        // Acquire shared lock
        if (!flock($fp, LOCK_SH)) {
            error_log("Failed to acquire shared lock on cache file: {$file}");
            fclose($fp);
            return null;
        }

        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($raw === false || $raw === '') {
            return null;
        }

        return $raw;
    }

    public function set(string $key, string $payload, int $ttl): bool
    {
        $file = $this->fileFor($key);
        $tmp  = $file . '.tmp.' . bin2hex(random_bytes(6));

        $fp = fopen($tmp, 'wb');
        if (!$fp) {
            error_log("Failed to open temporary cache file for writing: {$tmp}");
            return false;
        }

        // Acquire exclusive lock
        if (!flock($fp, LOCK_EX)) {
            error_log("Failed to acquire exclusive lock on temporary file: {$tmp}");
            fclose($fp);
            return false;
        }

        $bytesWritten = fwrite($fp, $payload);
        $ok = $bytesWritten !== false && $bytesWritten === strlen($payload);

        if ($ok) {
            $ok = fflush($fp);
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        if (!$ok) {
            unlink($tmp);
            error_log("Failed to write cache data to temporary file: {$tmp}");
            return false;
        }

        // Atomic rename
        if (!rename($tmp, $file)) {
            unlink($tmp);
            error_log("Failed to rename temporary file {$tmp} to {$file}");
            return false;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $file = $this->fileFor($key);

        if (!file_exists($file)) {
            return true; // Already doesn't exist
        }

        if (!unlink($file)) {
            error_log("Failed to delete cache file: {$file}");
            return false;
        }

        return true;
    }

    public function has(string $key): bool
    {
        $file = $this->fileFor($key);
        return is_file($file);
    }

    public function clear(): bool
    {
        return $this->purgeDir($this->path, $this->ext);
    }

    /**
     * File storage doesn't support TTL-based expiration.
     * Expired items are handled at the MultiTierCache level.
     */
    public function prune(): int
    {
        return 0;
    }

    private function purgeDir(string $dir, string $ext): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $items = scandir($dir);
        if ($items === false) {
            error_log("Failed to scan directory for purging: {$dir}");
            return false;
        }

        $ok = true;

        foreach ($items as $it) {
            if ($it === '.' || $it === '..') {
                continue;
            }

            $path = $dir . '/' . $it;

            if (is_dir($path)) {
                $ok = $this->purgeDir($path, $ext) && $ok;
                // Try to remove empty directory
                @rmdir($path);
            } else {
                // Only delete files with our extension
                if ($ext === '' || str_ends_with($it, $ext)) {
                    if (!unlink($path) && file_exists($path)) {
                        error_log("Failed to delete cache file during purge: {$path}");
                        $ok = false;
                    }
                }
            }
        }

        return $ok;
    }
}

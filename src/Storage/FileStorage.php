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
            if (!@mkdir($this->path, 0770, true) && !is_dir($this->path)) {
                throw new \RuntimeException("Unable to create cache directory: {$this->path}");
            }
        }
    }

    private function fileFor(string $key): string
    {
        $hash = md5($key);
        $dir = $this->path;
        if ($this->shards > 0) {
            for ($i = 0; $i < $this->shards; $i++) {
                $dir .= '/' . substr($hash, $i * 2, 2);
            }
        }
        if (!is_dir($dir)) { @mkdir($dir, 0770, true); }
        return $dir . '/' . $hash . $this->ext;
    }

    public function get(string $key): ?string
    {
        $file = $this->fileFor($key);
        if (!is_file($file)) return null;
        $fp = @fopen($file, 'rb');
        if (!$fp) return null;
        @flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        @flock($fp, LOCK_UN);
        fclose($fp);
        return ($raw === false || $raw === '') ? null : $raw;
    }

    public function set(string $key, string $payload, int $ttl): bool
    {
        $file = $this->fileFor($key);
        $tmp  = $file . '.tmp.' . bin2hex(random_bytes(6));
        $fp   = @fopen($tmp, 'wb');
        if (!$fp) return false;
        @flock($fp, LOCK_EX);
        $ok = fwrite($fp, $payload) !== false && fflush($fp);
        @flock($fp, LOCK_UN);
        fclose($fp);
        if (!$ok) { @unlink($tmp); return false; }
        return @rename($tmp, $file);
    }

    public function delete(string $key): bool
    {
        $file = $this->fileFor($key);
        return @unlink($file) || !file_exists($file);
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

    public function prune(): int { return 0; }

    private function purgeDir(string $dir, string $ext): bool
    {
        if (!is_dir($dir)) return true;
        $items = scandir($dir);
        if ($items === false) return false;
        $ok = true;
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') continue;
            $path = $dir . '/' . $it;
            if (is_dir($path)) {
                $ok = $this->purgeDir($path, $ext) && $ok;
                @rmdir($path);
            } else {
                if ($ext === '' || str_ends_with($it, $ext)) {
                    $ok = (@unlink($path) || !file_exists($path)) && $ok;
                }
            }
        }
        return $ok;
    }
}

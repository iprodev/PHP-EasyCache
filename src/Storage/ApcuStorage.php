<?php
declare(strict_types=1);

namespace Iprodev\EasyCache\Storage;

final class ApcuStorage implements StorageInterface
{
    private string $prefix;

    public function __construct(string $prefix = 'ec:')
    {
        if (!function_exists('apcu_fetch')) {
            throw new \RuntimeException('APCu extension is not available');
        }
        $this->prefix = $prefix;
    }

    private function k(string $key): string { return $this->prefix . $key; }

    public function get(string $key): ?string
    {
        $ok = false;
        $val = apcu_fetch($this->k($key), $ok);
        return $ok && is_string($val) ? $val : null;
    }

    public function set(string $key, string $payload, int $ttl): bool
    {
        return apcu_store($this->k($key), $payload, $ttl);
    }

    public function delete(string $key): bool
    {
        return (bool) apcu_delete($this->k($key));
    }

    public function has(string $key): bool
    {
        $ok = false; apcu_fetch($this->k($key), $ok); return $ok;
    }

    public function clear(): bool
    {
        return apcu_clear_cache();
    }

    public function prune(): int { return 0; }
}

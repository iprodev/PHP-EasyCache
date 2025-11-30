<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Storage;

final class ApcuStorage implements StorageInterface
{
    private string $prefix;

    public function __construct(string $prefix = 'ec:')
    {
        if (!function_exists('apcu_fetch')) {
            throw new \RuntimeException('APCu extension is not available. Install ext-apcu to use ApcuStorage.');
        }
        if (!ini_get('apc.enabled') || (PHP_SAPI === 'cli' && !ini_get('apc.enable_cli'))) {
            throw new \RuntimeException('APCu is not enabled. Check your php.ini configuration.');
        }
        $this->prefix = $prefix;
    }

    private function k(string $key): string
    {
        return $this->prefix . $key;
    }

    public function get(string $key): ?string
    {
        $ok = false;
        $val = apcu_fetch($this->k($key), $ok);
        return $ok && is_string($val) ? $val : null;
    }

    public function set(string $key, string $payload, int $ttl): bool
    {
        $result = apcu_store($this->k($key), $payload, $ttl);
        if (!$result) {
            error_log("APCu store failed for key: {$key}");
        }
        return $result;
    }

    public function delete(string $key): bool
    {
        return (bool) apcu_delete($this->k($key));
    }

    public function has(string $key): bool
    {
        $ok = false;
        apcu_fetch($this->k($key), $ok);
        return $ok;
    }

    /**
     * Clear only keys with the configured prefix.
     * This is safer than apcu_clear_cache() which clears ALL APCu data.
     */
    public function clear(): bool
    {
        $iterator = new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '/');
        $deleted = 0;
        $failed = 0;

        foreach ($iterator as $item) {
            if (apcu_delete($item['key'])) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        if ($failed > 0) {
            error_log("APCu clear: deleted {$deleted} keys, failed to delete {$failed} keys");
        }

        return $failed === 0;
    }

    public function prune(): int
    {
        // APCu handles expiration automatically
        return 0;
    }
}

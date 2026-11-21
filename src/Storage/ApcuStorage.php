<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Storage;

use InvalidArgumentException;
use Iprodev\EasyCache\Support\LogsErrors;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * APCu storage (shared memory of the current server / PHP-FPM pool).
 *
 * APCu is local to one server: in a multi-server setup, entries written or
 * deleted on one server are not visible to the others. Keep its TTL short
 * when it is used as a first tier in front of a shared tier.
 */
final class ApcuStorage implements BatchStorageInterface, LoggerAwareInterface
{
    use LogsErrors;

    private string $prefix;
    /** Upper bound for the TTL of entries in this tier (0 = no cap). */
    private int $maxTtl;

    /**
     * @param string $prefix Key prefix (required, clear() only removes keys with it)
     * @param int    $maxTtl Cap for the TTL of stored entries in seconds (0 = no cap)
     */
    public function __construct(string $prefix = 'ec:', int $maxTtl = 0, ?LoggerInterface $logger = null)
    {
        if (!function_exists('apcu_fetch')) {
            throw new RuntimeException('APCu extension is not available. Install ext-apcu to use ApcuStorage.');
        }
        if (!self::isEnabled()) {
            throw new RuntimeException(
                'APCu is not enabled. Check apc.enabled (and apc.enable_cli for CLI) in php.ini.'
            );
        }
        if ($prefix === '') {
            throw new InvalidArgumentException(
                'ApcuStorage requires a non-empty prefix: clear() deletes every key that starts with it'
            );
        }

        $this->prefix = $prefix;
        $this->maxTtl = max(0, $maxTtl);
        $this->logger = $logger;
    }

    public static function isAvailable(): bool
    {
        return function_exists('apcu_fetch') && self::isEnabled();
    }

    public function get(string $key): ?string
    {
        $ok = false;
        $value = apcu_fetch($this->k($key), $ok);
        return $ok && is_string($value) ? $value : null;
    }

    public function set(string $key, string $payload, int $ttl): bool
    {
        $result = apcu_store($this->k($key), $payload, $this->ttl($ttl));
        if (!$result) {
            $this->logWarning('EasyCache: APCu store failed for key {key}', ['key' => $key]);
        }
        return $result;
    }

    public function delete(string $key): bool
    {
        $k = $this->k($key);
        return apcu_delete($k) || !apcu_exists($k);
    }

    public function has(string $key): bool
    {
        return apcu_exists($this->k($key));
    }

    public function getMany(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $map = [];
        foreach ($keys as $key) {
            $map[$this->k($key)] = $key;
        }

        $ok = false;
        $values = apcu_fetch(array_keys($map), $ok);
        $result = [];
        if (is_array($values)) {
            foreach ($values as $apcuKey => $value) {
                if (is_string($value) && isset($map[$apcuKey])) {
                    $result[$map[$apcuKey]] = $value;
                }
            }
        }

        return $result;
    }

    public function setMany(array $payloads, int $ttl): bool
    {
        if ($payloads === []) {
            return true;
        }

        $values = [];
        foreach ($payloads as $key => $payload) {
            $values[$this->k((string) $key)] = $payload;
        }

        // With an array, apcu_store() returns the keys that could not be stored.
        $failed = apcu_store($values, null, $this->ttl($ttl));
        if ($failed === []) {
            return true;
        }

        $this->logWarning('EasyCache: APCu store failed for {count} keys', [
            'count' => is_array($failed) ? count($failed) : count($values),
        ]);

        return false;
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
     * Clear only keys with the configured prefix.
     * This is safer than apcu_clear_cache() which clears ALL APCu data.
     */
    public function clear(): bool
    {
        $iterator = new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '/', APC_ITER_KEY);
        $failed = 0;

        foreach ($iterator as $item) {
            $key = (string) $item['key'];
            if (!apcu_delete($key) && apcu_exists($key)) {
                $failed++;
            }
        }

        if ($failed > 0) {
            $this->logWarning('EasyCache: APCu clear failed to delete {count} keys', ['count' => $failed]);
        }

        return $failed === 0;
    }

    public function prune(): int
    {
        // APCu handles expiration automatically
        return 0;
    }

    private static function isEnabled(): bool
    {
        if (function_exists('apcu_enabled')) {
            return apcu_enabled();
        }

        return (bool) ini_get('apc.enabled') && (PHP_SAPI !== 'cli' || (bool) ini_get('apc.enable_cli'));
    }

    private function ttl(int $ttl): int
    {
        if ($this->maxTtl === 0) {
            return max(0, $ttl);
        }

        return $ttl > 0 ? min($ttl, $this->maxTtl) : $this->maxTtl;
    }

    private function k(string $key): string
    {
        return $this->prefix . $key;
    }
}

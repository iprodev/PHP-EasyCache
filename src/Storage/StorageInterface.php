<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Storage;

interface StorageInterface
{
    /**
     * Return the stored payload, or null when missing or expired.
     */
    public function get(string $key): ?string;

    /**
     * Store a payload. A TTL of 0 means "no expiry".
     */
    public function set(string $key, string $payload, int $ttl): bool;

    /**
     * Delete a payload. Returns true when the key is gone afterwards (also
     * when it did not exist), false only on errors.
     */
    public function delete(string $key): bool;

    public function has(string $key): bool;

    /**
     * Remove every entry that belongs to this storage (and nothing else).
     */
    public function clear(): bool;

    /**
     * Remove expired entries. Returns the number of removed entries.
     */
    public function prune(): int;
}

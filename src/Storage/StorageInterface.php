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
     * Read several keys, ideally in one round trip.
     *
     * @param list<string> $keys
     * @return array<string, string> Found payloads indexed by key (missing keys are omitted)
     */
    public function getMany(array $keys): array;

    /**
     * Store several payloads with the same TTL, ideally in one round trip.
     *
     * @param array<string, string> $payloads Payloads indexed by key
     */
    public function setMany(array $payloads, int $ttl): bool;

    /**
     * @param list<string> $keys
     */
    public function deleteMany(array $keys): bool;
}

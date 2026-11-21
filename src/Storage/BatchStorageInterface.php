<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Storage;

/**
 * Optional interface for storages that can read/write several keys in one
 * round trip. MultiTierCache uses it for getMultiple()/setMultiple()/
 * deleteMultiple() and falls back to single-key calls otherwise.
 */
interface BatchStorageInterface extends StorageInterface
{
    /**
     * @param list<string> $keys
     * @return array<string, string> Found payloads indexed by key (missing keys are omitted)
     */
    public function getMany(array $keys): array;

    /**
     * @param array<string, string> $payloads Payloads indexed by key
     */
    public function setMany(array $payloads, int $ttl): bool;

    /**
     * @param list<string> $keys
     */
    public function deleteMany(array $keys): bool;
}

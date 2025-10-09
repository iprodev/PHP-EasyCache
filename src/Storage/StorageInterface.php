<?php
declare(strict_types=1);

namespace Iprodev\EasyCache\Storage;

interface StorageInterface
{
    public function get(string $key): ?string;
    public function set(string $key, string $payload, int $ttl): bool;
    public function delete(string $key): bool;
    public function has(string $key): bool;
    public function clear(): bool;
    public function prune(): int;
}

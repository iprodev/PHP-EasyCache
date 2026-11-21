<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Support;

use Iprodev\EasyCache\Storage\PrunableStorageInterface;
use Psr\Clock\ClockInterface;

/**
 * In-memory storage that honours TTLs through a PSR-20 clock and records calls.
 */
final class ArrayStorage implements PrunableStorageInterface
{
    /** @var array<string, array{payload: string, expires: int}> */
    public array $data = [];
    /** @var array<string, int> */
    public array $calls = [];
    public bool $failing = false;

    public function __construct(private ClockInterface $clock)
    {
    }

    public function get(string $key): ?string
    {
        $this->count('get');
        return $this->read($key);
    }

    public function set(string $key, string $payload, int $ttl): bool
    {
        $this->count('set');
        if ($this->failing) {
            throw new \RuntimeException('storage failure');
        }
        $this->data[$key] = ['payload' => $payload, 'expires' => $ttl > 0 ? $this->now() + $ttl : 0];
        return true;
    }

    public function delete(string $key): bool
    {
        $this->count('delete');
        unset($this->data[$key]);
        return true;
    }

    public function has(string $key): bool
    {
        return $this->read($key) !== null;
    }

    public function clear(): bool
    {
        $this->data = [];
        return true;
    }

    public function prune(): int
    {
        $count = 0;
        foreach ($this->data as $key => $entry) {
            if ($entry['expires'] > 0 && $entry['expires'] <= $this->now()) {
                unset($this->data[$key]);
                $count++;
            }
        }
        return $count;
    }

    public function getMany(array $keys): array
    {
        $this->count('getMany');
        $result = [];
        foreach ($keys as $key) {
            $value = $this->read($key);
            if ($value !== null) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    public function setMany(array $payloads, int $ttl): bool
    {
        $this->count('setMany');
        foreach ($payloads as $key => $payload) {
            $this->data[(string) $key] = ['payload' => $payload, 'expires' => $ttl > 0 ? $this->now() + $ttl : 0];
        }
        return true;
    }

    public function deleteMany(array $keys): bool
    {
        $this->count('deleteMany');
        foreach ($keys as $key) {
            unset($this->data[$key]);
        }
        return true;
    }

    public function rawGet(string $key): ?string
    {
        return $this->data[$key]['payload'] ?? null;
    }

    public function rawSet(string $key, string $payload, int $expires = 0): void
    {
        $this->data[$key] = ['payload' => $payload, 'expires' => $expires];
    }

    private function read(string $key): ?string
    {
        if ($this->failing) {
            throw new \RuntimeException('storage failure');
        }
        $entry = $this->data[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($entry['expires'] > 0 && $entry['expires'] <= $this->now()) {
            unset($this->data[$key]);
            return null;
        }
        return $entry['payload'];
    }

    private function count(string $method): void
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}

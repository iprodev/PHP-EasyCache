<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Psr6;

use DateInterval;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Clock\ClockInterface;

/**
 * PSR-6 cache item created by CacheItemPool.
 */
final class CacheItem implements CacheItemInterface
{
    /** Expiry as a Unix timestamp, or null for the cache's default TTL. */
    private ?int $expiry = null;

    /**
     * @internal Created by CacheItemPool.
     */
    public function __construct(
        private readonly string $key,
        private mixed $value,
        private readonly bool $hit,
        private readonly ClockInterface $clock
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->hit ? $this->value : null;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;
        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->expiry = $expiration?->getTimestamp();
        return $this;
    }

    public function expiresAfter(int|DateInterval|null $time): static
    {
        $now = $this->clock->now();
        $this->expiry = match (true) {
            $time === null => null,
            $time instanceof DateInterval => $now->add($time)->getTimestamp(),
            default => $now->getTimestamp() + $time,
        };
        return $this;
    }

    /**
     * @internal
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * @internal
     */
    public function expiry(): ?int
    {
        return $this->expiry;
    }
}

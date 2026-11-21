<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Serialization;

interface SerializerInterface
{
    public function serialize(mixed $value): string;

    /**
     * Must throw when the payload cannot be decoded (never return a
     * placeholder value), so corrupt entries are treated as cache misses.
     */
    public function deserialize(string $payload): mixed;

    /**
     * Unique name (1-255 bytes) stored with every entry.
     */
    public function name(): string;

    /**
     * Whether deserialize() can instantiate PHP objects.
     *
     * Such serializers are only safe with trusted input, so MultiTierCache
     * requires a signing key for them (see MultiTierCache::__construct()).
     */
    public function allowsObjects(): bool;
}
